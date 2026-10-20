<?php

namespace App\Http\Controllers;

use App\Models\History;
use App\Models\Item;
use App\Models\Label;
use App\Models\Location;
use App\Models\Status;
use App\Models\SyncBatch;
use App\Models\SyncBatchOperation;
use App\Models\Team;
use App\Support\TeamRevision;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * API-037: batch sync — ONE request for a phone's whole offline queue.
 *
 * Operations are applied ONE BY ONE in submission order (same semantics as
 * the single-item endpoints), each in its own transaction with per-op
 * isolation: a failing op never blocks the batch. Every op gets a persisted
 * receipt (`sync_batch_operations`) and the batch id can be re-fetched via
 * GET api/sync/batch/{batch} so the phone can reconcile after a dropped
 * response.
 *
 * Conflict oracle: each field op carries `base_revision` (the team revision
 * the phone last synced at) plus per-field `base` values. When the item's
 * `sync_revision` has moved past `base_revision`, the server merges
 * field-by-field: a field the other device did NOT touch (server still
 * equals the phone's base) applies; a field the phone already matches is a
 * noop; a same-field divergence is reported back to the app for a user
 * decision (atomic per op — any conflict applies nothing). Deletes are
 * documented last-writer-wins: they converge, so a diverged base_revision
 * does not block them.
 *
 * The helpers mirror ItemController's private implementations (repo style,
 * cf. KanbanController) with a boolean variant of checkParents that yields
 * per-op `bad_reference` errors instead of aborting the request with 404.
 */
class SyncBatchController extends Controller
{
    /** The 9 op types mirrored from the Android PendingOp queue. */
    private const OP_TYPES = [
        'create', 'rename', 'move', 'assign', 'pick', 'unpick',
        'delete', 'label_add', 'label_remove',
    ];

    /** Field ops: op => the fields its `changes` payload touches. */
    private const FIELD_MAPS = [
        'rename' => ['name'],
        'move' => ['parent_id'],
        'assign' => ['status_id', 'location_id'],
    ];

    /**
     * POST api/sync/batch — submit an ordered list of operations.
     *
     * @param Request $request
     * @return JsonResponse
     * @throws AuthorizationException
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        /* API-015: the token's current team when set, the user's otherwise */
        $team = $user->effectiveTeam();

        if (!$team instanceof Team ||
            !$user->hasTeamPermission($team, 'item:write') ||
            !$user->tokenCan('item:write')
        ) {
            throw new AuthorizationException();
        }

        /* Envelope validation only — per-op field problems are per-op
           `error` results so one malformed entry cannot sink the batch. */
        $data = $request->validate([
            'operations' => 'required|array|min:1|max:200',
            'operations.*.op' => ['required', 'string', Rule::in(self::OP_TYPES)],
            'operations.*.base_revision' => 'required|integer|min:0',
            /* creates carry no item_id; everything else must */
            'operations.*.item_id' => [
                'required_unless:operations.*.op,create', 'nullable', 'string',
            ],
            'operations.*.changes' => 'nullable|array',
            'operations.*.base' => 'nullable|array',
        ]);

        $batch = SyncBatch::create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'total' => count($data['operations']),
        ]);

        $results = [];
        $totals = ['applied' => 0, 'conflicted' => 0, 'noop' => 0, 'failed' => 0];
        /* Revision values THIS batch produced. The phone's queued ops all
           carry the base_revision of its last sync; the batch's own applied
           ops move items past that point. Those stamps are the phone's own
           work, not third-party divergence — later ops on the same item
           must not treat them as a conflict trigger. */
        $ownRevisions = [];

        foreach ($data['operations'] as $index => $op) {
            /* Per-op isolation: its own transaction, its own failure. */
            try {
                $outcome = DB::transaction(function () use ($team, $user, $op, $ownRevisions) {
                    return $this->applyOp($team, $user, $op, $ownRevisions);
                });
            } catch (\Throwable $e) {
                report($e);
                $outcome = ['result' => SyncBatchOperation::RESULT_ERROR, 'detail' => ['error' => 'exception']];
            }

            /* Read-only — record whatever the counter is at now, bumped or
               not, so the next op's divergence check can recognize it. */
            $ownRevisions[] = (int) Team::whereKey($team->id)->value('revision');

            $result = $outcome['result'];
            $totals[match ($result) {
                SyncBatchOperation::RESULT_APPLIED => 'applied',
                SyncBatchOperation::RESULT_CONFLICT => 'conflicted',
                SyncBatchOperation::RESULT_NOOP => 'noop',
                default => 'failed',
            }]++;

            $itemId = $outcome['item_id'] ?? ($op['item_id'] ?? null);

            /* The receipt must stay self-describing for the GET endpoint:
               an error result without an explicit detail gets its code. */
            $detail = $outcome['detail']
                ?? (isset($outcome['error']) ? ['error' => $outcome['error']] : null);

            SyncBatchOperation::create([
                'batch_id' => $batch->id,
                'op' => $op['op'],
                'index' => $index,
                'item_id' => $itemId,
                'payload' => $op,
                'result' => $result,
                'detail' => $detail,
                'applied_at' => $result === SyncBatchOperation::RESULT_APPLIED ? now() : null,
            ]);

            $entry = [
                'index' => $index,
                'op' => $op['op'],
                'item_id' => $itemId,
                'result' => $result,
            ];
            if (isset($outcome['item'])) {
                $entry['item'] = $outcome['item'];
            }
            if (isset($outcome['conflicts'])) {
                $entry['conflicts'] = $outcome['conflicts'];
            }
            if (isset($outcome['error'])) {
                $entry['error'] = $outcome['error'];
            }
            $results[] = $entry;
        }

        $batch->update($totals + ['finished_at' => now()]);

        /* The counter moved under us (one bump per applied op) — read it
           fresh. Read-only: no bump (nextForId would increment). */
        $revision = (int) Team::whereKey($team->id)->value('revision');

        return response()
            ->json([
                'batch_id' => $batch->id,
                'results' => $results,
                'revision' => $revision,
            ])
            ->header('X-Revision', (string) $revision);
    }

    /**
     * GET api/sync/batch/{batch} — reconciliation: replay the persisted
     * outcomes of a batch (e.g. after a dropped response). A batch from
     * another team is a plain 404 (no existence leak).
     *
     * @param Request $request
     * @param string $batchId
     * @return JsonResponse
     * @throws AuthorizationException
     */
    public function show(Request $request, string $batchId): JsonResponse
    {
        $user = $request->user();
        $team = $user->effectiveTeam();

        if (!$team instanceof Team ||
            !$user->hasTeamPermission($team, 'item:read') ||
            !$user->tokenCan('item:read')
        ) {
            throw new AuthorizationException();
        }

        $batch = SyncBatch::where('id', $batchId)
            ->where('team_id', $team->id)
            ->firstOrFail();

        return response()->json([
            'batch_id' => $batch->id,
            'created_at' => $batch->created_at,
            'finished_at' => $batch->finished_at,
            'total' => $batch->total,
            'applied' => $batch->applied,
            'conflicted' => $batch->conflicted,
            'noop' => $batch->noop,
            'failed' => $batch->failed,
            'operations' => $batch->operations->map(fn (SyncBatchOperation $operation) => [
                'index' => $operation->index,
                'op' => $operation->op,
                'item_id' => $operation->item_id,
                'result' => $operation->result,
                'detail' => $operation->detail,
                'applied_at' => $operation->applied_at,
            ]),
        ]);
    }

    /**
     * Dispatch one operation. Returns the outcome:
     * result + optional item / conflicts / error / detail / item_id.
     *
     * @param Team $team
     * @param $user
     * @param array $op
     * @param int[] $ownRevisions revisions this batch already produced
     * @return array
     */
    private function applyOp(Team $team, $user, array $op, array $ownRevisions = []): array
    {
        return match ($op['op']) {
            'create' => $this->opCreate($team, $user, $op),
            'delete' => $this->opDelete($team, $user, $op),
            'label_add', 'label_remove' => $this->opLabel($team, $user, $op),
            default => $this->opField($team, $user, $op, $ownRevisions),
        };
    }

    /**
     * Shared item resolution for ops addressing an existing item.
     * Returns [$item, $errorCode] — exactly one is set. Trashed items are
     * `not_found` everywhere except delete (where they are a noop).
     *
     * @param string $teamId
     * @param string|null $itemId
     * @param bool $allowTrashed
     * @return array
     */
    private function resolveItem(string $teamId, ?string $itemId, bool $allowTrashed = false): array
    {
        if (!$itemId) {
            return [null, 'invalid_op'];
        }

        $item = Item::withTrashed()->find($itemId);
        if (!$item) {
            return [null, 'not_found'];
        }
        if ($item->team_id !== $teamId) {
            return [null, 'foreign_team'];
        }
        if (!$allowTrashed && $item->trashed()) {
            return [null, 'not_found'];
        }

        return [$item, null];
    }

    /**
     * `create` — mirrors ItemController::store(): name/location/status
     * required, references must be live rows of the batch's team. No
     * conflict check (a new id cannot have diverged).
     *
     * @param Team $team
     * @param $user
     * @param array $op
     * @return array
     */
    private function opCreate(Team $team, $user, array $op): array
    {
        $changes = $op['changes'] ?? [];

        if (!isset($changes['name']) || !is_string($changes['name']) || $changes['name'] === ''
            || !isset($changes['location_id']) || !isset($changes['status_id'])) {
            return ['result' => SyncBatchOperation::RESULT_ERROR, 'error' => 'invalid_op'];
        }

        /* Cross-team/trashed/truly-missing references are all the same
           per-op error (the boolean checkParents variant). */
        foreach ([
            Location::class => $changes['location_id'],
            Status::class => $changes['status_id'],
            Item::class => $changes['parent_id'] ?? null,
        ] as $model => $value) {
            if (!$value) {
                continue;
            }
            $exists = $model::where('id', $value)
                ->where('team_id', $team->id)
                ->whereNull('deleted_at')
                ->exists();
            if (!$exists) {
                return ['result' => SyncBatchOperation::RESULT_ERROR, 'error' => 'bad_reference'];
            }
        }

        $item = Item::create([
            'name' => $changes['name'],
            'team_id' => $team->id,
            'location_id' => $changes['location_id'],
            'status_id' => $changes['status_id'],
            'parent_id' => $changes['parent_id'] ?? null,
        ]);

        return [
            'result' => SyncBatchOperation::RESULT_APPLIED,
            'item_id' => $item->id,
            'item' => $item,
        ];
    }

    /**
     * `delete` — mirrors ItemController::destroy(): children re-parented
     * to root with one history row each, mass-detached with an explicit
     * revision stamp; the trashed row gets its own bump from the observer.
     * Deletes converge, so a diverged base_revision is NOT a conflict
     * (documented last-writer-wins). Deleting a deleted item is a noop.
     *
     * @param Team $team
     * @param $user
     * @param array $op
     * @return array
     */
    private function opDelete(Team $team, $user, array $op): array
    {
        [$item, $error] = $this->resolveItem($team->id, $op['item_id'] ?? null, true);
        if ($error) {
            return ['result' => SyncBatchOperation::RESULT_ERROR, 'error' => $error];
        }
        if ($item->trashed()) {
            return ['result' => SyncBatchOperation::RESULT_NOOP];
        }

        $children = Item::where('parent_id', $item->id)->get();
        $detachedAt = now();

        foreach ($children as $child) {
            History::create([
                'item_id' => $child->id,
                'user_id' => $user->id,
                'field_name' => 'parent_id',
                'old_value' => $item->id,
                'new_value' => null,
                'changed_at' => $detachedAt,
            ]);
        }

        /* Mass update: no model events, one query for the whole detach —
           stamp the detached children with the post-increment counter
           (API-033); the trashed row itself is stamped by the observer. */
        if ($children->isNotEmpty()) {
            Item::where('parent_id', $item->id)->update([
                'parent_id' => null,
                'updated_at' => $detachedAt,
                'sync_revision' => TeamRevision::nextForId($item->team_id),
            ]);
        }

        $item->delete();

        return [
            'result' => SyncBatchOperation::RESULT_APPLIED,
            'item_id' => $item->id,
            'detail' => ['detached_ids' => $children->pluck('id')->all()],
        ];
    }

    /**
     * `label_add` / `label_remove` — LabelController semantics made
     * idempotent: a duplicate attach or an absent detach is a noop (the
     * single endpoint 409s / bumps unconditionally). `label_not_found` is
     * team-scoped; labels stay root-box-only (`has_parent`).
     *
     * @param Team $team
     * @param $user
     * @param array $op
     * @return array
     */
    private function opLabel(Team $team, $user, array $op): array
    {
        [$item, $error] = $this->resolveItem($team->id, $op['item_id'] ?? null);
        if ($error) {
            return ['result' => SyncBatchOperation::RESULT_ERROR, 'error' => $error];
        }

        $labelId = $op['label_id'] ?? null;
        if (!$labelId) {
            return ['result' => SyncBatchOperation::RESULT_ERROR, 'error' => 'invalid_op'];
        }

        $label = Label::where('id', $labelId)->where('team_id', $team->id)->first();
        if (!$label) {
            return ['result' => SyncBatchOperation::RESULT_ERROR, 'error' => 'label_not_found'];
        }

        $attached = $item->labels()->where('label_id', $labelId)->exists();

        if ($op['op'] === 'label_add') {
            if ($item->parent_id) {
                return ['result' => SyncBatchOperation::RESULT_ERROR, 'error' => 'has_parent'];
            }
            if ($attached) {
                return ['result' => SyncBatchOperation::RESULT_NOOP];
            }
            $item->labels()->attach($labelId);
        } else {
            if (!$attached) {
                return ['result' => SyncBatchOperation::RESULT_NOOP];
            }
            $item->labels()->detach($labelId);
        }

        /* Pivot writes fire no model events — bump the team revision and
           stamp the item (API-003 + API-033). */
        TeamRevision::bumpAndStamp($item);

        return [
            'result' => SyncBatchOperation::RESULT_APPLIED,
            'item_id' => $item->id,
            'item' => $item->fresh(['labels']),
        ];
    }

    /**
     * Field ops: rename / move / assign (+ the body-less pick / unpick).
     *
     * rename/move/assign go through the three-way merge when the item has
     * diverged (its `sync_revision` moved past `base_revision` — excluding
     * stamps this batch itself produced, which are the phone's own work);
     * the merge is atomic per op — any same-field conflict applies nothing.
     *
     * @param Team $team
     * @param $user
     * @param array $op
     * @param int[] $ownRevisions
     * @return array
     */
    private function opField(Team $team, $user, array $op, array $ownRevisions = []): array
    {
        [$item, $error] = $this->resolveItem($team->id, $op['item_id'] ?? null);
        if ($error) {
            return ['result' => SyncBatchOperation::RESULT_ERROR, 'error' => $error];
        }

        $type = $op['op'];
        $changes = $op['changes'] ?? [];

        if ($type === 'pick' || $type === 'unpick') {
            $data = ['picked_at' => $type === 'pick' ? now() : null];

            if ($type === 'unpick' && $item->picked_at === null) {
                return ['result' => SyncBatchOperation::RESULT_NOOP];
            }

            $this->recordItemChanges($item, $data, $user, true);
            $item->update($data);

            return [
                'result' => SyncBatchOperation::RESULT_APPLIED,
                'item_id' => $item->id,
                'item' => $item->refresh(),
            ];
        }

        $fields = self::FIELD_MAPS[$type];

        if ($type === 'rename' && !isset($changes['name'])) {
            return ['result' => SyncBatchOperation::RESULT_ERROR, 'error' => 'invalid_op'];
        }
        if ($type === 'assign'
            && (!isset($changes['status_id']) || !isset($changes['location_id']))) {
            return ['result' => SyncBatchOperation::RESULT_ERROR, 'error' => 'invalid_op'];
        }

        /* Reference pre-checks against the submitted values (the batch
           flavor of checkParents: per-op error instead of a 404 abort). */
        if ($type === 'move' && isset($changes['parent_id']) && $changes['parent_id'] !== null) {
            $parent = Item::where('id', $changes['parent_id'])
                ->where('team_id', $team->id)
                ->whereNull('deleted_at')
                ->first();
            if (!$parent) {
                return ['result' => SyncBatchOperation::RESULT_ERROR, 'error' => 'bad_reference'];
            }
            if ($this->wouldCycle($item, $changes['parent_id'])) {
                return ['result' => SyncBatchOperation::RESULT_ERROR, 'error' => 'cycle'];
            }
        }
        if ($type === 'assign') {
            foreach ([
                Status::class => $changes['status_id'],
                Location::class => $changes['location_id'],
            ] as $model => $value) {
                $exists = $model::where('id', $value)
                    ->where('team_id', $team->id)
                    ->whereNull('deleted_at')
                    ->exists();
                if (!$exists) {
                    return ['result' => SyncBatchOperation::RESULT_ERROR, 'error' => 'bad_reference'];
                }
            }
        }

        /* Three-way merge. Not diverged (the item moved at most as far as
           the phone's base_revision, or only by this batch's own ops):
           apply everything — same-value writes still cost nothing (no
           history row, no bump). Diverged by a third party: per-field
           server == base → the other device left this field alone → merge;
           server == mine → already applied → noop; otherwise → conflict. */
        $diverged = (int) $item->sync_revision > (int) $op['base_revision']
            && !in_array((int) $item->sync_revision, $ownRevisions, true);
        $apply = [];
        $conflicts = [];

        foreach ($fields as $field) {
            $mine = $changes[$field] ?? null;
            $server = $item->$field;

            if (!$diverged) {
                $apply[$field] = $mine;
                continue;
            }

            $base = $op['base'][$field] ?? null;

            if ($this->fieldMatches($server, $base)) {
                $apply[$field] = $mine;
            } elseif (!$this->fieldMatches($server, $mine)) {
                $conflicts[$field] = [
                    'base' => $base,
                    'yours' => $mine,
                    'server' => $server,
                ];
            }
            /* else: server == mine → idempotent noop for this field */
        }

        if ($conflicts !== []) {
            return [
                'result' => SyncBatchOperation::RESULT_CONFLICT,
                'item_id' => $item->id,
                'conflicts' => $conflicts,
                'detail' => ['conflicts' => $conflicts],
            ];
        }

        if ($apply === []) {
            /* Every field already matches the server — nothing to write. */
            return ['result' => SyncBatchOperation::RESULT_NOOP];
        }

        $this->recordItemChanges($item, $apply, $user, true);
        $item->update($apply);

        /* Same-value writes are noop results too (rename to the current
           name on a fresh base): no history row, no bump, nothing changed. */
        if (!$item->wasChanged()) {
            return ['result' => SyncBatchOperation::RESULT_NOOP];
        }

        return [
            'result' => SyncBatchOperation::RESULT_APPLIED,
            'item_id' => $item->id,
            'item' => $item->refresh(),
        ];
    }

    /**
     * Record changes to item fields in the history table — verbatim port of
     * ItemController::recordItemChanges (only-present-keys flavor).
     *
     * @param Item $item
     * @param array $data
     * @param $user
     */
    private function recordItemChanges(Item $item, array $data, $user): void
    {
        /* picked_at (API-032): the datetime cast hands back Carbon objects,
           so `!==` compares object identity — every pick/unpick that changes
           the state records a row (re-pick refreshes the timestamp with a
           new row), while a no-op unpick (null -> null) records nothing. */
        $trackableFields = ['name', 'location_id', 'status_id', 'parent_id', 'picked_at'];

        foreach ($trackableFields as $field) {
            if (array_key_exists($field, $data)) {
                $oldValue = $item->$field;
                $newValue = $data[$field];

                // Only record if the value actually changed
                if ($oldValue !== $newValue) {
                    History::create([
                        'item_id' => $item->id,
                        'user_id' => $user->id,
                        'field_name' => $field,
                        'old_value' => $oldValue,
                        'new_value' => $newValue,
                        'changed_at' => now(),
                    ]);
                }
            }
        }
    }

    /**
     * Whether moving $item under $newParentId would create a cycle —
     * verbatim port of ItemController::wouldCycle.
     *
     * @param Item $item
     * @param string|null $newParentId
     * @return bool
     */
    private function wouldCycle(Item $item, ?string $newParentId): bool
    {
        if ($newParentId === $item->id) {
            return true;
        }

        /* Walk up the chain from the proposed parent; if we reach the item,
           the proposed parent lives in its subtree (bounded loop). */
        $seen = [];
        $cursor = $newParentId ? Item::find($newParentId) : null;
        while ($cursor && !isset($seen[$cursor->id])) {
            if ($cursor->id === $item->id) {
                return true;
            }
            $seen[$cursor->id] = true;
            $cursor = $cursor->parent_id ? Item::find($cursor->parent_id) : null;
        }

        return false;
    }

    /**
     * Field-value comparison for the merge oracle: the phone's base/mine
     * values arrive as JSON scalars (uuids as strings, datetimes as ISO-8601),
     * the server's are model attributes (datetime casts hand back Carbon).
     * Datetimes compare by timestamp; everything else by normalized string,
     * with null === null.
     *
     * @param mixed $server
     * @param mixed $phone
     * @return bool
     */
    private function fieldMatches($server, $phone): bool
    {
        if ($server instanceof \DateTimeInterface) {
            return $phone !== null
                && $server->getTimestamp() === strtotime((string) $phone);
        }

        if ($server === null || $phone === null) {
            return $server === $phone;
        }

        return (string) $server === (string) $phone;
    }
}
