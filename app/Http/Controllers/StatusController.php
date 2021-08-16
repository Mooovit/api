<?php

namespace App\Http\Controllers;

use App\Models\Status;
use App\Models\Team;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Validation\UnauthorizedException;

class StatusController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request): \Illuminate\Http\Response
    {
        return Status::where('team_id', $request->user()->current_team_id)->get();
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request): \Illuminate\Http\Response
    {
        $data = $request->validate([
            "name" => "required|string",
            "team_id" => "required|string",
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
     * @param \App\Models\Status $status
     * @return Status
     */
    public function show(Request $request, Status $status): Status
    {
        $team_id = $request->user()->current_team_id;
        if ($status->team_id !== $team_id) {
            throw new UnauthorizedException();
        }
        return $status;
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\Status  $status
     * @return \Illuminate\Http\Response
     */
    public function edit(Status $status)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Status  $status
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Status $status)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Status  $status
     * @return \Illuminate\Http\Response
     */
    public function destroy(Status $status)
    {
        //
    }
}
