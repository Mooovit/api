<?php

namespace App\Http\Controllers;

use App\Models\History;
use App\Models\Item;
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
     * Ensure the proposed parent is not the item itself nor one of its
     * descendants (moving would create a cycle).
     *
     * @param Item $item
     * @param string|null $newParentId
     * @throws ValidationException
     */
    private function ensureNoCycle(Item $item, ?string $newParentId): void
    {
        if ($newParentId === $item->id) {
            throw ValidationException::withMessages([
                'parent_id' => 'An item cannot become its own parent.',
            ]);
        }

        /* Walk up the chain from the proposed parent; if we reach the item,
           the proposed parent lives in its subtree (bounded loop). */
        $seen = [];
        $cursor = $newParentId ? Item::find($newParentId) : null;
        while ($cursor && !isset($seen[$cursor->id])) {
            if ($cursor->id === $item->id) {
                throw ValidationException::withMessages([
                    'parent_id' => 'Cannot move an item into one of its own descendants.',
                ]);
            }
            $seen[$cursor->id] = true;
            $cursor = $cursor->parent_id ? Item::find($cursor->parent_id) : null;
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
        /* We check that the user is allowed to read list of items */
        if (!$user->hasTeamPermission($user->current_team, 'item:read') ||
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
                    'changed' => Item::where('team_id', $user->current_team_id)
                        ->where('updated_at', '>', $since)
                        ->get(),
                    'deleted_ids' => Item::onlyTrashed()
                        ->where('team_id', $user->current_team_id)
                        ->where('deleted_at', '>', $since)
                        ->pluck('id'),
                ])
                ->header('X-Revision', (string) $user->currentTeam->revision);
        }

        return response()
            ->json(Item::where('team_id', $user->current_team_id)->get())
            ->header('X-Revision', (string) $user->currentTeam->revision);
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
        
        /* Load labels relationship */
        $item->load('labels');
        
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

        $item->delete();
        return response()->json(['success' => 'success']);
    }
}
