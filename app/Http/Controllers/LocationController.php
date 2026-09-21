<?php

namespace App\Http\Controllers;

use App\Models\Location;
use App\Models\Team;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LocationController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * API-027: the catalogue is trashed-INCLUSIVE — soft-deleted rows are
     * returned too (serializing a non-null `deleted_at`; live rows keep
     * `deleted_at: null`) so clients holding history rows that reference a
     * deleted location can still resolve its name. Writes and route bindings
     * (show/update/destroy) stay default-scoped: a trashed id is a plain 404.
     *
     * @param Request $request
     * @return Collection
     * @throws AuthorizationException
     */
    public function index(Request $request): Collection
    {
        $user = $request->user();
        /* API-015: the token's current team when set, the user's otherwise */
        $team = $user->effectiveTeam();

        /* We check that the user can create a box in the team */
        if (!$user->hasTeamPermission($team, 'location:read') ||
            !$user->tokenCan('location:read')
        ) {
            throw new AuthorizationException();
        }
        return Location::where('team_id', $team->id)->withTrashed()->get();
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param Request $request
     * @return Location
     * @throws AuthorizationException
     */
    public function store(Request $request): Location
    {
        $data = $request->validate([
            "name" => "required|string",
            "team_id" => "required|string",
            /* API-034: optional parent — must be a live, same-team location
               (a foreign-team or trashed parent is a 422, probing leaks
               nothing: the exists failure message is generic) */
            "parent_id" => [
                "nullable",
                Rule::exists('locations', 'id')
                    ->whereNull('deleted_at')
                    ->where('team_id', $request->input('team_id')),
            ],
        ]);

        /* We get the user from the request */
        $user = $request->user();

        /* We fetch the foreign keys from the request */
        $team = Team::findOrFail($request['team_id']);

        /* We check that the user can create a new location in that team */
        if (!$user->hasTeamPermission($team, 'location:write') ||
            !$user->tokenCan('location:write')
        ) {
            throw new AuthorizationException();
        }

        return Location::create([
            "name" => $data['name'],
            "team_id" => $team->id,
            "parent_id" => $data['parent_id'] ?? null,
        ]);
    }

    /**
     * Display the specified resource.
     *
     * @param Request $request
     * @param Location $location
     * @return Location
     * @throws AuthorizationException
     */
    public function show(Request $request, Location $location): Location
    {
        $user = $request->user();

        /* We check that the user can get a location in that team */
        if (!$user->hasTeamPermission($location->team, 'location:read') ||
            !$user->tokenCan('location:read')
        ) {
            throw new AuthorizationException();
        }

        return $location;
    }


    /**
     * Update the specified resource in storage.
     *
     * @param Request $request
     * @param Location $location
     * @return Location
     * @throws AuthorizationException
     */
    public function update(Request $request, Location $location) : Location
    {
        $user = $request->user();

        /* We check that the user can update an item in the team */
        if (!$user->hasTeamPermission($location->team, 'location:write') ||
            !$user->tokenCan('location:write')
        ) {
            throw new AuthorizationException();
        }

        $data = $request->validate([
            "name" => "required|string",
            /* API-034: same rules as store — the parent must be a live,
               same-team location. The key is OPTIONAL: absent → the
               hierarchy is untouched; present `null` → the location
               becomes a root. Rides the legacy resource PATCH. */
            "parent_id" => [
                "nullable",
                Rule::exists('locations', 'id')
                    ->whereNull('deleted_at')
                    ->where('team_id', $location->team_id),
            ],
        ]);

        /* API-034: the cycle check is imperative (like the item endpoints)
           because it needs the id of the row being moved — the validator
           only covers exists/team/trashed. */
        if (array_key_exists('parent_id', $data)) {
            $this->ensureNoCycle($location, $data['parent_id']);
        }

        $location->update($data);
        return $location->refresh();
    }

    /**
     * Whether moving $location under $newParentId would create a cycle (the
     * destination is the location itself or one of its descendants) —
     * mirrors ItemController::wouldCycle(): walk up the candidate parent's
     * ancestor chain with a visited set (bounded against corrupt chains);
     * reaching the location being moved means the candidate lives in its
     * own subtree.
     *
     * @param Location $location
     * @param string|null $newParentId
     * @return bool
     */
    private function wouldCycle(Location $location, ?string $newParentId): bool
    {
        if ($newParentId === $location->id) {
            return true;
        }

        $seen = [];
        $cursor = $newParentId ? Location::find($newParentId) : null;
        while ($cursor && !isset($seen[$cursor->id])) {
            if ($cursor->id === $location->id) {
                return true;
            }
            $seen[$cursor->id] = true;
            $cursor = $cursor->parent_id ? Location::find($cursor->parent_id) : null;
        }

        return false;
    }

    /**
     * Ensure the proposed parent is not the location itself nor one of its
     * descendants (moving would create a cycle). Re-parenting to a root
     * (`parent_id: null`) is always allowed.
     *
     * @param Location $location
     * @param string|null $newParentId
     * @throws ValidationException
     */
    private function ensureNoCycle(Location $location, ?string $newParentId): void
    {
        if ($this->wouldCycle($location, $newParentId)) {
            throw ValidationException::withMessages([
                'parent_id' => 'Cannot move a location into one of its own descendants.',
            ]);
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * API-034 deletion policy (mirrors the API-008 / server.md §5 item
     * policy): in one transaction — soft delete the location and re-parent
     * its DIRECT children to root (`parent_id → null`). Grandchildren keep
     * their own parents; trashed children are skipped by the soft-delete
     * scope (never modified). The response keeps `{success: 'success'}` and
     * additively reports the re-parented children as `detached_ids`. The
     * trashed row itself stays in the trashed-INCLUSIVE index (API-027).
     * No history rows exist for locations; the team revision bumps once
     * from the observer's `deleted` event (the mass detach fires no events).
     *
     * @param Request $request
     * @param Location $location
     * @return JsonResponse
     * @throws AuthorizationException
     */
    public function destroy(Request $request, Location $location): JsonResponse
    {
        $user = $request->user();
        /* We check that the user can update an item in the team */
        if (!$user->hasTeamPermission($location->team, 'location:write') ||
            !$user->tokenCan('location:write')
        ) {
            throw new AuthorizationException();
        }

        $detachedIds = DB::transaction(function () use ($location) {
            $children = Location::where('parent_id', $location->id)->get();

            /* Mass update: one query for the whole detach, no model events. */
            if ($children->isNotEmpty()) {
                Location::where('parent_id', $location->id)->update([
                    'parent_id' => null,
                ]);
            }

            $location->delete();

            return $children->pluck('id')->all();
        });

        return response()->json(['success' => 'success', 'detached_ids' => $detachedIds]);
    }
}
