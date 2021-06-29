<?php

namespace App\Http\Controllers;

use App\Models\Box;
use App\Models\Location;
use App\Models\Status;
use App\Models\Team;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;

class BoxController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        return Box::where('team_id', $request->user()->currentTeam->id)->get();
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {

    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            "name" => "required|string",
            "team_id" => "required|string",
            "location_id" => "required|string",
            "status_id" => "required|string"
        ]);

        /* We get the user from the request */
        $user = $request->user();

        /* We fetch the foreign keys from the request */
        $team = Team::findOrFail($request['team_id']);
        $location = Location::findOrFail($request['location_id']);
        $status = Status::findOrFail($request['status_id']);

        /* We check that the user can create a box in the team */
        if(
            !$user->hasTeamPermission($team, 'box:write') ||
            !$user->tokenCan('box:write')
        ) {
            throw new AuthorizationException();
        }

        return Box::forceCreate([
            "name" => $data['name'],
            "team_id" => $team->id,
            "location_id" => $location->id,
            "status_id" => $status->id,
        ]);
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Box  $box
     * @return \Illuminate\Http\Response
     */
    public function show(Request $request, Box $box)
    {
        $user = $request->user();
        /* We check that the user can create a box in the team */
        if(
            !$user->hasTeamPermission($box->team, 'box:read') ||
            !$user->tokenCan('box:read')
        ) {
            throw new AuthorizationException();
        }
        $box->items;
        return $box;
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\Box  $box
     * @return \Illuminate\Http\Response
     */
    public function edit(Box $box)
    {

    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Box  $box
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Box $box)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Box  $box
     * @return \Illuminate\Http\Response
     */
    public function destroy(Box $box)
    {
        //
    }
}
