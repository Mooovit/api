<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\History;
use App\Models\Item;
use App\Models\ItemBarcode;
use App\Models\Location;
use App\Models\Status;
use App\Models\Team;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Support\TeamRevision;

class ItemController extends Controller
{

    /**
     * Check all parents to validate that they belong to the same Team ID
     *
     * @param String $team_id
     * @param array $data
     */
    private function checkParents(String $team_id, Array $data)
    {
        foreach ([
                Location::class => isset($data['location_id']) ? $data['location_id'] : null,
                Status::class => isset($data['status_id']) ? $data['status_id'] : null,
                Item::class => isset($data['parent_id']) ? $data['parent_id'] : null,
            ] as $model => $value
        ) {
            if (!$value) {
                continue;
            }
            $model::where([
                'id' => $value,
                'team_id' => $team_id,
            ])->firstOrFail();
        }
    }

    /**
     * Record changes to item fields in the history table
     *
     * @param Item $item
     * @param array $data
     * @param $user
     * @param bool $onlyPresentKeys when true, a key present with a null value
     *        (e.g. `parent_id: null` from the move verb) is still tracked;
     *        the legacy update() behavior (isset semantics) is unchanged.
     */
    private function recordItemChanges(Item $item, array $data, $user, bool $onlyPresentKeys = false)
    {
        /* picked_at (API-032): the datetime cast hands back Carbon objects,
           so `!==` compares object identity — every pick/unpick that changes
           the state records a row (re-pick refreshes the timestamp with a
           new row), while a no-op unpick (null -> null) records nothing. */
        $trackableFields = ['name', 'location_id', 'status_id', 'parent_id', 'picked_at'];

        foreach ($trackableFields as $field) {
            $present = $onlyPresentKeys ? array_key_exists($field, $data) : isset($data[$field]);
            if ($present) {
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
     * Whether moving $item under $newParentId would create a cycle (the
     * destination is the item itself or one of its descendants). Row-level
     * helper shared by the single move verb and bulk-move (API-007).
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
     * Ensure the proposed parent is not the item itself nor one of its
     * descendants (moving would create a cycle).
     *
     * @param Item $item
     * @param string|null $newParentId
     * @throws ValidationException
     */
    private function ensureNoCycle(Item $item, ?string $newParentId): void
    {
        if ($this->wouldCycle($item, $newParentId)) {
            throw ValidationException::withMessages([
                'parent_id' => 'Cannot move an item into one of its own descendants.',
            ]);
        }
    }

    /**
     * Authorization shared by the intent verbs (move/assign/rename): the
     * item's own team + item:write token ability (api/* only, like update()).
     *
     * @param Request $request
     * @param Item $item
     * @throws AuthorizationException
     */
    private function authorizeIntentVerb(Request $request, Item $item): void
    {
        $user = $request->user();
        if (!$user->hasTeamPermission($item->team, 'item:write') ||
            !$user->tokenCan('item:write')
        ) {
            throw new AuthorizationException();
        }
    }

    /**
     * POST api/item/{item}/move — move the box under another parent
     * (`parent_id: null` makes it a root). Touches only parent_id.
     *
     * @param Request $request
     * @param Item $item
     * @return Item
     * @throws AuthorizationException|ValidationException
     */
    public function move(Request $request, Item $item): Item
    {
        $this->authorizeIntentVerb($request, $item);

        $data = $request->validate([
            'parent_id' => 'nullable|string|exists:items,id',
        ]);

        $this->checkParents($item->team_id, $data);
        $this->ensureNoCycle($item, Arr::get($data, 'parent_id'));

        return DB::transaction(function () use ($item, $data, $request) {
            $this->recordItemChanges($item, $data, $request->user(), true);
            $item->update($data);
            return $item->refresh();
        });
    }

    /**
     * POST api/item/{item}/assign — set status and location (the Transport
     * payload). Touches only status_id and location_id.
     *
     * @param Request $request
     * @param Item $item
     * @return Item
     * @throws AuthorizationException|ValidationException
     */
    public function assign(Request $request, Item $item): Item
    {
        $this->authorizeIntentVerb($request, $item);

        $data = $request->validate([
            /* API-027: trashed-EXPLICIT exists — a deleted status/location
               id is a 422 (plain exists is scope-blind) */
            'status_id' => ['required', 'string', Rule::exists('statuses', 'id')->whereNull('deleted_at')],
            'location_id' => ['required', 'string', Rule::exists('locations', 'id')->whereNull('deleted_at')],
        ]);

        $this->checkParents($item->team_id, $data);

        return DB::transaction(function () use ($item, $data, $request) {
            $this->recordItemChanges($item, $data, $request->user(), true);
            $item->update($data);
            return $item->refresh();
        });
    }

    /**
     * POST api/item/{item}/rename — change the item name. Touches only name;
     * renaming to the same name saves nothing (no history row, updated_at
     * unchanged).
     *
     * @param Request $request
     * @param Item $item
     * @return Item
     * @throws AuthorizationException|ValidationException
     */
    public function rename(Request $request, Item $item): Item
    {
        $this->authorizeIntentVerb($request, $item);

        $data = $request->validate([
            'name' => 'required|string',
        ]);

        return DB::transaction(function () use ($item, $data, $request) {
            $this->recordItemChanges($item, $data, $request->user(), true);
            $item->update($data);
            return $item->refresh();
        });
    }

    /**
     * POST api/item/{item}/pick — mark the item as TEMPORARILY out of its
     * box (API-032). Touches only picked_at (set to now()); body-less.
     * Containment (parent_id) is unchanged — this is the "temporary pick,
     * I'll put it back later" state. Re-picking an already-picked item
     * refreshes the timestamp (one history row per call).
     *
     * @param Request $request
     * @param Item $item
     * @return Item
     * @throws AuthorizationException
     */
    public function pick(Request $request, Item $item): Item
    {
        $this->authorizeIntentVerb($request, $item);

        return DB::transaction(function () use ($item, $request) {
            $data = ['picked_at' => now()];
            $this->recordItemChanges($item, $data, $request->user(), true);
            $item->update($data);
            return $item->refresh();
        });
    }

    /**
     * POST api/item/{item}/unpick — put the item back (API-032): clears
     * picked_at. Unpicking an item that was never picked saves nothing
     * (no history row, updated_at unchanged — the rename same-value rule;
     * the `updated` event only fires on real changes, so no revision bump
     * either).
     *
     * @param Request $request
     * @param Item $item
     * @return Item
     * @throws AuthorizationException
     */
    public function unpick(Request $request, Item $item): Item
    {
        $this->authorizeIntentVerb($request, $item);

        return DB::transaction(function () use ($item, $request) {
            $data = ['picked_at' => null];
            $this->recordItemChanges($item, $data, $request->user(), true);
            $item->update($data);
            return $item->refresh();
        });
    }

    /**
     * POST api/item/{item}/transfer (API-016) — move an item to another team.
     * POST only: the host does not support PATCH.
     *
     * The item's WHOLE SUBTREE transfers with it (children, grandchildren…):
     * a parent link never crosses teams, so leaving any child behind is not
     * an option. Within the subtree parent links are preserved; only the
     * root is detached from its (source-team) parent, reported as
     * `detached_parent`. Trashed descendants are tombstones — they stay
     * behind (they are invisible everywhere, like any soft-deleted item).
     *
     * Permission checks on BOTH sides: the requester needs `item:write` on
     * the root's own (source) team and on the destination team, via team
     * permission and token ability. The optional destination `location_id`/
     * `status_id` belong to the ROOT only, must belong to the destination
     * team and are applied in the same transaction as the team move; when
     * omitted the current values carry over. Descendants keep their
     * location/status (a follow-up bulk-assign can re-point them).
     *
     * Source-team labels are detached from every transferred item
     * (destination labels are never auto-attached) and reported as
     * `detached_label_ids`. Barcodes and attachments follow their item:
     * their `team_id` is rewritten in the same transaction, but per-team
     * registry uniqueness (API-011) is enforced across the whole subtree
     * first — any code already held by the destination team on an item
     * OUTSIDE the moved subtree refuses the whole transfer with 409 and
     * NOTHING changes.
     *
     * Everything (item updates, label detachments, history rows, both team
     * revision bumps) runs in one transaction. One history row per
     * transferred item records the `team_id` move (+ the root's parent
     * detach, API-008 semantics). The two revision bumps (API-003) are the
     * signal for both teams' sync clients to re-pull — the source's delta
     * feed never carries the rows (they no longer match the source's team
     * scope), source clients see them disappear on their next full pull.
     *
     * @param Request $request
     * @param Item $item
     * @return JsonResponse
     * @throws AuthorizationException|ValidationException
     */
    public function transfer(Request $request, Item $item): JsonResponse
    {
        $user = $request->user();

        /* Source side: the item's own team (API-001 pattern) */
        if (!$user->hasTeamPermission($item->team, 'item:write') ||
            !$user->tokenCan('item:write')
        ) {
            throw new AuthorizationException();
        }

        $data = $request->validate([
            'team_id' => 'required|string|exists:teams,id',
            'location_id' => 'nullable|string|exists:locations,id',
            'status_id' => 'nullable|string|exists:statuses,id',
        ]);

        /* Destination side: same permission on the receiving team */
        $destination = Team::findOrFail($data['team_id']);
        if ($destination->id === $item->team_id) {
            throw ValidationException::withMessages([
                'team_id' => 'The item already belongs to this team.',
            ]);
        }
        if (!$user->hasTeamPermission($destination, 'item:write') ||
            !$user->tokenCan('item:write')
        ) {
            throw new AuthorizationException();
        }

        /* Optional relocation of the ROOT — both references must belong to
           the DESTINATION team (not the item's current one) */
        $errors = [];
        $locationId = Arr::get($data, 'location_id');
        $statusId = Arr::get($data, 'status_id');
        if ($locationId &&
            Location::where('id', $locationId)->where('team_id', $destination->id)->doesntExist()
        ) {
            $errors['location_id'] = 'The selected location does not belong to the destination team.';
        }
        if ($statusId &&
            Status::where('id', $statusId)->where('team_id', $destination->id)->doesntExist()
        ) {
            $errors['status_id'] = 'The selected status does not belong to the destination team.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $conflict = false;
        [$detachedParent, $detachedLabelIds] = DB::transaction(function () use ($item, $user, $destination, $locationId, $statusId, &$conflict) {
            $oldTeamId = $item->team_id;
            $oldParentId = $item->parent_id;
            $transferredAt = now();

            /* The whole subtree moves: a parent link never crosses teams. */
            $ids = [$item->id];
            $queue = [$item->id];
            while ($queue) {
                $next = Item::whereIn('parent_id', $queue)->pluck('id')->all();
                $ids = array_merge($ids, $next);
                $queue = $next;
            }

            /* Registry uniqueness is per team (API-011): any code held by the
               destination team on an item OUTSIDE the moved subtree refuses
               the transfer — nothing has been written yet, everything below
               never runs. */
            $codes = ItemBarcode::whereIn('item_id', $ids)->pluck('code');
            if ($codes->isNotEmpty() &&
                ItemBarcode::where('team_id', $destination->id)
                    ->whereIn('code', $codes)
                    ->whereNotIn('item_id', $ids)
                    ->exists()
            ) {
                $conflict = true;

                return [false, []];
            }

            /* History: one team_id row per transferred item; the root
               additionally gets the parent detach (old parent -> null,
               API-008 semantics) and the optional location/status move. */
            foreach ($ids as $id) {
                History::create([
                    'item_id' => $id,
                    'user_id' => $user->id,
                    'field_name' => 'team_id',
                    'old_value' => $oldTeamId,
                    'new_value' => $destination->id,
                    'changed_at' => $transferredAt,
                ]);
            }
            $detachedParent = $oldParentId !== null;
            if ($detachedParent) {
                History::create([
                    'item_id' => $item->id,
                    'user_id' => $user->id,
                    'field_name' => 'parent_id',
                    'old_value' => $oldParentId,
                    'new_value' => null,
                    'changed_at' => $transferredAt,
                ]);
            }
            if ($locationId && $locationId !== $item->location_id) {
                History::create([
                    'item_id' => $item->id,
                    'user_id' => $user->id,
                    'field_name' => 'location_id',
                    'old_value' => $item->location_id,
                    'new_value' => $locationId,
                    'changed_at' => $transferredAt,
                ]);
            }
            if ($statusId && $statusId !== $item->status_id) {
                History::create([
                    'item_id' => $item->id,
                    'user_id' => $user->id,
                    'field_name' => 'status_id',
                    'old_value' => $item->status_id,
                    'new_value' => $statusId,
                    'changed_at' => $transferredAt,
                ]);
            }

            /* Source-team labels are detached from every transferred item;
               others (already cross-team) stay — destination labels are
               never auto-attached. */
            $detachedLabelIds = [];
            foreach (Item::whereIn('id', $ids)->get() as $row) {
                $detached = $row->labels()
                    ->where('labels.team_id', $oldTeamId)
                    ->pluck('labels.id');
                if ($detached->isNotEmpty()) {
                    $row->labels()->detach($detached->all());
                    $detachedLabelIds = array_merge($detachedLabelIds, $detached->all());
                }
            }

            /* Barcodes + attachments follow their item (their team
               reservation / ownership is rewritten). Mass updates fire no
               model events. */
            ItemBarcode::whereIn('item_id', $ids)->update(['team_id' => $destination->id]);
            Attachment::whereIn('item_id', $ids)->update(['team_id' => $destination->id]);

            /* API-003 bump on BOTH teams — the source's bump is how its
               clients learn something left (the rows themselves no longer
               match the source's team scope in delta pulls). The destination
               bump doubles as the API-033 stamp source: every transferred
               row carries the destination's post-increment counter, so its
               next `since_revision` delta delivers the subtree. */
            Team::whereKey($oldTeamId)->increment('revision');
            $stamp = TeamRevision::nextForId($destination->id);

            Item::whereIn('id', $ids)->update([
                'team_id' => $destination->id,
                'updated_at' => $transferredAt,
                'sync_revision' => $stamp,
            ]);
            /* Root only: detach from the source-team parent + optional
               destination location/status. */
            Item::whereKey($item->id)->update([
                'parent_id' => null,
                'location_id' => $locationId ?? $item->location_id,
                'status_id' => $statusId ?? $item->status_id,
                'sync_revision' => $stamp,
            ]);

            return [$detachedParent, $detachedLabelIds];
        });

        if ($conflict) {
            return response()->json([
                'error' => 'A code of this item is already registered in the destination team',
            ], 409);
        }

        /* show() shape of the root + additive transfer metadata */
        $item->refresh()->load(['labels', 'barcodes']);
        $item->setRelation('attachments',
            $item->attachments->map(fn ($attachment) => $attachment->metadata())->values());

        return response()->json(array_merge(
            $item->toArray(),
            [
                'detached_label_ids' => $detachedLabelIds,
                'detached_parent' => $detachedParent,
            ],
        ));
    }

    /**
     * POST api/item/bulk-move (API-007) — move many items under one parent in
     * a single request (`parent_id: null` detaches to root). POST only: the
     * host does not support PATCH.
     *
     * Per-row results in request order: `{id, ok: true, updated_at}` on
     * success, `{id, ok: false, error}` with `error` one of `not_found`,
     * `foreign_team`, `cycle` otherwise — a bad row never aborts the batch
     * (HTTP 200 even with partial failures). Rows are applied inside one
     * transaction; history keeps per-item granularity (only actually-changed
     * fields); the team revision (API-003) is bumped once per affected team —
     * mass updates fire no model events, so no per-item bumps happen.
     *
     * @param Request $request
     * @return JsonResponse
     * @throws AuthorizationException|ValidationException
     */
    public function bulkMove(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user->tokenCan('item:write')) {
            throw new AuthorizationException();
        }

        $data = $request->validate([
            'ids' => 'required|array|min:1|max:500',
            'ids.*' => 'string|distinct',
            'parent_id' => 'nullable|string|exists:items,id',
        ]);

        $ids = $data['ids'];
        $parentId = Arr::get($data, 'parent_id');
        /* Trashed parents 404 here too (global scope), matching the single
           move verb. */
        $parent = $parentId ? Item::findOrFail($parentId) : null;

        /* The destination may not be part of the moved set — the batch would
           be self-referential; every row reports `cycle`, nothing applies. */
        $parentInBatch = $parent !== null && in_array($parent->id, $ids, true);

        $appliedAt = now();
        $results = [];
        /* API-033: one stamp per touched team — the first successful row of
           a team takes the post-increment counter, every row of that batch
           carries it (one logical write = one bump = one stamp value). */
        $stampsByTeamId = [];

        DB::transaction(function () use ($user, $ids, $parent, $parentId, $parentInBatch, $appliedAt, &$results, &$stampsByTeamId) {
            foreach ($ids as $id) {
                $item = Item::find($id);

                $error = null;
                if (!$item) {
                    $error = 'not_found';
                } elseif (!$user->hasTeamPermission($item->team, 'item:write')) {
                    $error = 'foreign_team';
                } elseif ($parent && $item->team_id !== $parent->team_id) {
                    $error = 'foreign_team';
                } elseif ($parentInBatch || $this->wouldCycle($item, $parentId)) {
                    $error = 'cycle';
                }

                if ($error !== null) {
                    $results[] = ['id' => $id, 'ok' => false, 'error' => $error];
                    continue;
                }

                $this->recordItemChanges($item, ['parent_id' => $parentId], $user, true);
                /* Mass update: fires no model events (no per-item bump) —
                   the stamp below is the delta cursor (API-033). */
                $stamp = $stampsByTeamId[$item->team_id]
                    ??= TeamRevision::nextForId($item->team_id);
                Item::whereKey($item->id)->update([
                    'parent_id' => $parentId,
                    'updated_at' => $appliedAt,
                    'sync_revision' => $stamp,
                ]);
                $results[] = ['id' => $id, 'ok' => true, 'updated_at' => $appliedAt];
            }
        });

        return response()->json(['results' => $results]);
    }

    /**
     * POST api/item/bulk-assign (API-007) — set status + location (the
     * Transport payload) on many items in a single request. Same per-row
     * result contract as bulk-move (`not_found`, `foreign_team`).
     *
     * @param Request $request
     * @return JsonResponse
     * @throws AuthorizationException|ValidationException
     */
    public function bulkAssign(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user->tokenCan('item:write')) {
            throw new AuthorizationException();
        }

        $data = $request->validate([
            'ids' => 'required|array|min:1|max:500',
            'ids.*' => 'string|distinct',
            /* API-027: trashed-EXPLICIT exists — a deleted status/location
               id is a 422 (plain exists is scope-blind; bulk-assign used to
               die on a confusing 404 from the findOrFail below instead) */
            'status_id' => ['required', 'string', Rule::exists('statuses', 'id')->whereNull('deleted_at')],
            'location_id' => ['required', 'string', Rule::exists('locations', 'id')->whereNull('deleted_at')],
        ]);

        $status = Status::findOrFail($data['status_id']);
        $location = Location::findOrFail($data['location_id']);
        if ($status->team_id !== $location->team_id) {
            throw ValidationException::withMessages([
                'location_id' => 'Status and location must belong to the same team.',
            ]);
        }

        $appliedAt = now();
        $results = [];
        /* API-033: one stamp per touched team, as in bulk-move. */
        $stampsByTeamId = [];

        DB::transaction(function () use ($user, $data, $status, $location, $appliedAt, &$results, &$stampsByTeamId) {
            foreach ($data['ids'] as $id) {
                $item = Item::find($id);

                $error = null;
                if (!$item) {
                    $error = 'not_found';
                } elseif (!$user->hasTeamPermission($item->team, 'item:write')) {
                    $error = 'foreign_team';
                } elseif ($item->team_id !== $status->team_id) {
                    $error = 'foreign_team';
                }

                if ($error !== null) {
                    $results[] = ['id' => $id, 'ok' => false, 'error' => $error];
                    continue;
                }

                $this->recordItemChanges(
                    $item,
                    ['status_id' => $status->id, 'location_id' => $location->id],
                    $user,
                    true
                );
                /* Mass update: fires no model events (no per-item bump) —
                   the stamp below is the delta cursor (API-033). */
                $stamp = $stampsByTeamId[$item->team_id]
                    ??= TeamRevision::nextForId($item->team_id);
                Item::whereKey($item->id)->update([
                    'status_id' => $status->id,
                    'location_id' => $location->id,
                    'updated_at' => $appliedAt,
                    'sync_revision' => $stamp,
                ]);
                $results[] = ['id' => $id, 'ok' => true, 'updated_at' => $appliedAt];
            }
        });

        return response()->json(['results' => $results]);
    }

    /**
     * Display a listing of the resource.
     *
     * Adds the team's current revision counter as `X-Revision` (API-003) so a
     * client that just pulled can remember it without a second call.
     *
     * API-006 delta sync: with `?since=<ISO-8601>` the response becomes
     * `{ changed: [...], deleted_ids: [...] }` — full rows (same shape as the
     * plain list) whose `updated_at > since`, plus the team-scoped ids
     * soft-deleted after `since`. Creations are included in `changed`
     * (`updated_at >= created_at`); tombstones never surface as bodies (the
     * SoftDeletes global scope keeps them out of `changed`). Without `since`
     * the plain array is returned, unchanged. Timestamps have second
     * precision — clients pair this with the API-003 revision counter and
     * re-pull when it moves.
     *
     * API-033 revision-keyed delta: with `?since_revision=N` the same
     * `{ changed, deleted_ids }` envelope keys on `items.sync_revision`
     * (the post-increment team counter stamped on every write) — exact
     * windows with no timestamp precision, current revision in the
     * X-Revision header. `?since=` stays for older clients.
     *
     * @param Request $request
     * @return JsonResponse
     * @throws AuthorizationException
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        /* API-015: the token's current team when set, the user's otherwise */
        $team = $user->effectiveTeam();

        /* We check that the user is allowed to read list of items */
        if (!$user->hasTeamPermission($team, 'item:read') ||
            !$user->tokenCan('item:read')
        ) {
            throw new AuthorizationException();
        }

        /* API-033 revision-keyed delta — `since_revision` is checked first
           (a client sending both wants the counter window). Rows carry the
           post-increment team counter as `sync_revision` (stamped on every
           app-representation write), so the window is exact — no timestamp
           precision, no re-delivery; bump-only writes (barcodes, uploads)
           answer empty and still advance the cursor through X-Revision.
           Tombstones key on their own last stamp. */
        if ($request->filled('since_revision')) {
            $request->validate(['since_revision' => ['integer', 'min:0']]);
            $sinceRevision = (int) $request->input('since_revision');

            return response()
                ->json([
                    'changed' => Item::where('team_id', $team->id)
                        ->where('sync_revision', '>', $sinceRevision)
                        ->get(),
                    'deleted_ids' => Item::onlyTrashed()
                        ->where('team_id', $team->id)
                        ->where('sync_revision', '>', $sinceRevision)
                        ->pluck('id'),
                ])
                ->header('X-Revision', (string) $team->revision);
        }

        /* Timestamp delta (API-006) — kept unchanged for older clients
           (API-033 deprecates it later, once no client sends it). */
        if ($request->filled('since')) {
            $request->validate(['since' => 'date']);
            $since = $request->date('since');

            return response()
                ->json([
                    'changed' => Item::where('team_id', $team->id)
                        ->where('updated_at', '>', $since)
                        ->get(),
                    'deleted_ids' => Item::onlyTrashed()
                        ->where('team_id', $team->id)
                        ->where('deleted_at', '>', $since)
                        ->pluck('id'),
                ])
                ->header('X-Revision', (string) $team->revision);
        }

        return response()
            ->json(Item::where('team_id', $team->id)->get())
            ->header('X-Revision', (string) $team->revision);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param Request $request
     * @return Item
     * @throws AuthorizationException
     */
    public function store(Request $request): Item
    {
        $data = $request->validate([
            "name" => "required|string",
            "team_id" => "required|string",
            /* API-027: trashed-EXPLICIT exists — a deleted status/location
               id is a 422 (plain exists is scope-blind) */
            "location_id" => ["required", "string", Rule::exists('locations', 'id')->whereNull('deleted_at')],
            "status_id" => ["required", "string", Rule::exists('statuses', 'id')->whereNull('deleted_at')],
            "parent_id" => "nullable|string|exists:items,id"
        ]);

        /* We fetch the user from the request */
        $user = $request->user();

        $team = Team::findOrFail($request['team_id']);

        /* We check that the user can create a box in the team */
        if (!$user->hasTeamPermission($team, 'item:write') ||
            !$user->tokenCan('item:write')
        ) {
            throw new AuthorizationException();
        }

        $this->checkParents($data['team_id'], $data);

        return Item::create([
            "name" => $data['name'],
            "team_id" => $data['team_id'],
            "location_id" => $data['location_id'],
            "status_id" => $data['status_id'],
            "parent_id" => Arr::get($data, 'parent_id', null),
        ]);
    }

    /**
     * Display the specified resource.
     *
     * @param Request $request
     * @param Item $item
     * @return Item
     * @throws AuthorizationException
     */
    public function show(Request $request, Item $item): Item
    {
        $user = $request->user();
        /* We check that the user is allowed to read this item, in ITS team
           (not the user's current team, so cross-team ids are rejected) */
        if (!$user->hasTeamPermission($item->team, 'item:read') ||
            !$user->tokenCan('item:read')
        ) {
            throw new AuthorizationException();
        }

        /* We can fetch childrens directly if we pass childrens boolean into request */
        if (isset($request->childrens)) {
            $item->childrens;
        }
        
        /* Load labels + barcode registry relationships (API-011) */
        $item->load('labels', 'barcodes');

        /* API-013: attachments metadata only — replace the loaded rows (raw
           rows carry the storage path, which must not be serialized) */
        $item->load('attachments');
        $item->setRelation('attachments',
            $item->attachments->map(fn ($attachment) => $attachment->metadata())->values());

        return $item;
    }

    /**
     * Update the specified resource in storage.
     *
     * @param Request $request
     * @param Item $item
     * @return Item
     * @throws AuthorizationException
     */
    public function update(Request $request, Item $item): Item
    {
        $user = $request->user();
        /* We check that the user can update an item in the team */
        if (!$user->hasTeamPermission($item->team, 'item:write') ||
            (!$user->tokenCan('item:write') && $request->is('api/*'))
        ) {
            throw new AuthorizationException();
        }

        $data = $request->validate([
            "name" => "string",
            /* API-027: trashed-EXPLICIT exists — a deleted status/location
               id is a 422 (plain exists is scope-blind) */
            "location_id" => ["string", Rule::exists('locations', 'id')->whereNull('deleted_at')],
            "status_id" => ["string", Rule::exists('statuses', 'id')->whereNull('deleted_at')],
            "parent_id" => "nullable|string|exists:items,id",
        ]);

        $this->checkParents($item->team_id, $data);

        // Track changes before updating
        $this->recordItemChanges($item, $data, $user);

        $item->update($data);
        return $item->refresh();
    }

    /**
     * Remove the specified resource from storage.
     *
     * API-008 deletion policy (decided): deleting a box re-parents its direct
     * children to root (they survive as roots) and reports their ids in the
     * additive `detached_ids` response field. One history row per detached
     * child (`parent_id: <box id> -> null`), everything in one transaction.
     * Grandchildren are untouched (they keep their own parents); trashed
     * children are never modified (the soft-delete scope skips them). The
     * mass detach fires no events but stamps the children (API-033) with
     * one counter bump; the trashed row itself gets its own bump + stamp
     * from the observer's `deleted` event (API-003), so revision-keyed
     * deltas report the children as `changed` and the box in `deleted_ids`.
     *
     * @param Request $request
     * @param Item $item
     * @return JsonResponse
     * @throws AuthorizationException
     */
    public function destroy(Request $request, Item $item): JsonResponse
    {
        $user = $request->user();

        /* We check that the user can create a box in the team */
        if (!$user->hasTeamPermission($item->team, 'item:write') ||
            !$user->tokenCan('item:write')
        ) {
            throw new AuthorizationException();
        }

        $detachedIds = DB::transaction(function () use ($item, $user) {
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

            /* Mass update: no model events, one query for the whole detach.
               API-033: the detached children are stamped too (their app
               representation changed — the re-parent), each batch of rows
               carrying the post-increment counter this write contributes;
               the trashed row itself is stamped by the observer's deleted
               event (one more bump). */
            if ($children->isNotEmpty()) {
                Item::where('parent_id', $item->id)->update([
                    'parent_id' => null,
                    'updated_at' => $detachedAt,
                    'sync_revision' => TeamRevision::nextForId($item->team_id),
                ]);
            }

            $item->delete();

            return $children->pluck('id')->all();
        });

        return response()->json(['success' => 'success', 'detached_ids' => $detachedIds]);
    }
}
