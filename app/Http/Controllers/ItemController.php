<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\Location;
use App\Models\Status;
use App\Models\Team;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

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
                Location::class => $data['location_id'],
                Status::class => $data['status_id'],
                Item::class => $data['parent_id']
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
     * Display a listing of the resource.
     *
     * @param Request $request
     * @return Collection
     * @throws AuthorizationException
     */
    public function index(Request $request): Collection
    {
        $user = $request->user();
        /* We check that the user is allowed to read list of items */
        if (!$user->hasTeamPermission($user->current_team, 'item:read') ||
            !$user->tokenCan('item:read')
        ) {
            throw new AuthorizationException();
        }

        return Item::where('team_id', $user->current_team_id)->get();
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
        /* We check that the user is allowed to read list of items */
        if (!$user->hasTeamPermission($user->current_team, 'item:read') ||
            !$user->tokenCan('item:read')
        ) {
            throw new AuthorizationException();
        }

        /* We can fetch childrens directly if we pass childrens boolean into request */
        if (isset($request->childrens)) {
            $item->childrens;
        }
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
            !$user->tokenCan('item:write')
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
