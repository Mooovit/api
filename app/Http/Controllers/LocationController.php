<?php

namespace App\Http\Controllers;

use App\Models\Location;
use App\Models\Team;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class LocationController extends Controller
{
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

        /* We check that the user can create a box in the team */
        if (!$user->hasTeamPermission($user->current_team, 'location:read') ||
            !$user->tokenCan('location:read')
        ) {
            throw new AuthorizationException();
        }
        return Location::where('team_id', $request->user()->current_team_id)->get();
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
        ]);
        $location->update($data);
        return $location->refresh();
    }

    /**
     * Remove the specified resource from storage.
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
        $location->delete();
        return response()->json(['success' => 'success']);
    }
}
