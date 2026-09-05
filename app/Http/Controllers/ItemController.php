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
use Illuminate\Validation\ValidationException;

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
        $trackableFields = ['name', 'location_id', 'status_id', 'parent_id'];

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
            'status_id' => 'required|string|exists:statuses,id',
            'location_id' => 'required|string|exists:locations,id',
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

            Item::whereIn('id', $ids)->update([
                'team_id' => $destination->id,
                'updated_at' => $transferredAt,
            ]);
            /* Root only: detach from the source-team parent + optional
               destination location/status. */
            Item::whereKey($item->id)->update([
                'parent_id' => null,
                'location_id' => $locationId ?? $item->location_id,
                'status_id' => $statusId ?? $item->status_id,
            ]);

            /* API-003 bump on BOTH teams — the source's bump is how its
               clients learn something left (the rows themselves no longer
               match the source's team scope in delta pulls). */
            Team::whereKey($oldTeamId)->increment('revision');
            Team::whereKey($destination->id)->increment('revision');

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
        $touchedTeamIds = [];

        DB::transaction(function () use ($user, $ids, $parent, $parentId, $parentInBatch, $appliedAt, &$results, &$touchedTeamIds) {
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
                /* Mass update: fires no model events (no per-item bump). */
                Item::whereKey($item->id)->update([
                    'parent_id' => $parentId,
                    'updated_at' => $appliedAt,
                ]);
                $touchedTeamIds[$item->team_id] = true;
                $results[] = ['id' => $id, 'ok' => true, 'updated_at' => $appliedAt];
            }

            if ($touchedTeamIds) {
                /* API-003 bump, once per request per affected team. */
                Team::whereIn('id', array_keys($touchedTeamIds))->increment('revision');
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
            'status_id' => 'required|string|exists:statuses,id',
            'location_id' => 'required|string|exists:locations,id',
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
        $touchedTeamIds = [];

        DB::transaction(function () use ($user, $data, $status, $location, $appliedAt, &$results, &$touchedTeamIds) {
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
                /* Mass update: fires no model events (no per-item bump). */
                Item::whereKey($item->id)->update([
                    'status_id' => $status->id,
                    'location_id' => $location->id,
                    'updated_at' => $appliedAt,
                ]);
                $touchedTeamIds[$item->team_id] = true;
                $results[] = ['id' => $id, 'ok' => true, 'updated_at' => $appliedAt];
            }

            if ($touchedTeamIds) {
                /* API-003 bump, once per request per affected team. */
                Team::whereIn('id', array_keys($touchedTeamIds))->increment('revision');
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

        /* Delta feed — strict `>`: the client remembers the max updated_at
           it saw. Tombstones surface only in `deleted_ids`. */
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
            "location_id" => "required|string|exists:locations,id",
            "status_id" => "required|string|exists:statuses,id",
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
            "location_id" => "string|exists:locations,id",
            "status_id" => "string|exists:statuses,id",
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
     * `deleted` event bumps the team revision once (API-003 observer); the
     * mass detach fires no events but does bump the children's `updated_at`,
     * so API-006 deltas report them as changed.
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

            /* Mass update: no model events, one query for the whole detach */
            if ($children->isNotEmpty()) {
                Item::where('parent_id', $item->id)->update([
                    'parent_id' => null,
                    'updated_at' => $detachedAt,
                ]);
            }

            $item->delete();

            return $children->pluck('id')->all();
        });

        return response()->json(['success' => 'success', 'detached_ids' => $detachedIds]);
    }
}
