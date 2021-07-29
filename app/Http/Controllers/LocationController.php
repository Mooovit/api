<?php

namespace App\Http\Controllers;

use App\Models\Location;
use App\Models\Team;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;

class LocationController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        //
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
    public function store(Request $request)
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
     * @param  \App\Models\Location  $location
     * @return \Illuminate\Http\Response
     */
    public function show(Location $location)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\Location  $location
     * @return \Illuminate\Http\Response
     */
    public function edit(Location $location)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Location  $location
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Location $location)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Location  $location
     * @return \Illuminate\Http\Response
     */
    public function destroy(Location $location)
    {
        //
    }
}
