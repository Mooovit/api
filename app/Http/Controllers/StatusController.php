<?php

namespace App\Http\Controllers;

use App\Models\Status;
use App\Models\Team;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class StatusController extends Controller
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
        if (!$user->hasTeamPermission($user->current_team, 'status:read') ||
            !$user->tokenCan('status:read')
        ) {
            throw new AuthorizationException();
        }

        return Status::where('team_id', $user->current_team_id)->get();
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param Request $request
     * @return Status
     * @throws AuthorizationException
     */
    public function store(Request $request): Status
    {
        $data = $request->validate([
            "name" => "required|string",
            "team_id" => "required|string",
            "position" => "integer|nullable",
        ]);

        /* We get the user from the request */
        $user = $request->user();

        /* We fetch the foreign keys from the request */
        $team = Team::findOrFail($request['team_id']);

        /* We check that the user can create a box in the team */
        if (!$user->hasTeamPermission($team, 'status:write') ||
            !$user->tokenCan('status:write')
        ) {
            throw new AuthorizationException();
        }

        return Status::create([
            "name" => $data['name'],
            "team_id" => $team->id,
        ]);
    }

    /**
     * Display the specified resource.
     *
     * @param Request $request
     * @param Status $status
     * @return Status
     */
    public function show(Request $request, Status $status): Status
    {
        $user = $request->user();

        /* We check that the user can get a location in that team */
        if (!$user->hasTeamPermission($status->team, 'location:read') ||
            !$user->tokenCan('location:read')
        ) {
            throw new AuthorizationException();
        }

        return $status;
    }

    /**
     * Update the specified resource in storage.
     *
     * @param Request $request
     * @param Status $status
     * @return Status
     * @throws AuthorizationException
     */
    public function update(Request $request, Status $status): Status
    {
        $user = $request->user();

        /* We check that the user can update a status in the team */
        if (!$user->hasTeamPermission($status->team, 'location:write') ||
            !$user->tokenCan('location:write')
        ) {
            throw new AuthorizationException();
        }

        $data = $request->validate([
            "name" => "required|string",
            "position" => "integer|nullable",
        ]);

        $status->update($data);
        return $status->refresh();
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param Request $request
     * @param Status $status
     * @return JsonResponse
     * @throws AuthorizationException
     */
    public function destroy(Request $request, Status $status): JsonResponse
    {
        $user = $request->user();

        if (!$user->hasTeamPermission($status->team, 'location:write') ||
            !$user->tokenCan('location:write')
        ) {
            throw new AuthorizationException();
        }
        $status->delete();
        return response()->json(['success' => 'success']);
    }
}
