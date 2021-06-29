<?php

namespace App\Http\Controllers;

use App\Models\Box;
use App\Models\Item;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;

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
            "box_id" => "required|string",
        ]);
        /* We fetch the user from the request */
        $user = $request->user();

        $box = Box::find($data['box_id']);

        /* We check that the user can create a box in the team */
        if(
            !$user->hasTeamPermission($box->team, 'item:write') ||
            !$user->tokenCan('item:write')
        ) {
            throw new AuthorizationException();
        }

        return Item::forceCreate([
            'name' => $data['name'],
            'box_id' => $data['box_id']
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
        if(
            !$user->hasTeamPermission($box->team, 'item:write') ||
            !$user->tokenCan('item:write')
        ) {
            throw new AuthorizationException();
        }

        $item->delete();
    }
}
