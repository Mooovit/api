<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\Team;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class ItemController extends Controller
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
            "location_id" => "required|string",
            "status_id" => "required|string",
            "parent_id" => "nullable|string"
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
     * @param  \App\Models\Item  $item
     * @return \Illuminate\Http\Response
     */
    public function show(Item $item)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\Item  $item
     * @return \Illuminate\Http\Response
     */
    public function edit(Item $item)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Item  $item
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Item $item)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Item  $item
     * @return \Illuminate\Http\Response
     */
    public function destroy(Request $request, Item $item)
    {
        $user = $request->user();

        $box = $item->box;

        /* We check that the user can create a box in the team */
        if (!$user->hasTeamPermission($box->team, 'item:write') ||
            !$user->tokenCan('item:write')
        ) {
            throw new AuthorizationException();
        }

        $item->delete();
    }
}
