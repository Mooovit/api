<?php

namespace App\Http\Controllers;

use App\Models\Label;
use App\Models\Item;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LabelController extends Controller
{
    /**
     * Display a listing of labels for the current team.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $labels = Label::where('team_id', $user->current_team_id)
            ->orderBy('name')
            ->get();

        return response()->json($labels);
    }

    /**
     * Store a newly created label.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'color' => 'required|string|regex:/^#[0-9A-Fa-f]{6}$/',
        ]);

        $user = $request->user();

        if (!$user->hasTeamPermission($user->current_team, 'item:write') ||
            !$user->tokenCan('item:write')
        ) {
            throw new AuthorizationException();
        }

        $label = Label::create([
            'name' => $data['name'],
            'color' => $data['color'],
            'team_id' => $user->current_team_id,
        ]);

        return response()->json($label, 201);
    }

    /**
     * Update the specified label.
     */
    public function update(Request $request, Label $label): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'color' => 'required|string|regex:/^#[0-9A-Fa-f]{6}$/',
        ]);

        $user = $request->user();

        // Check if label belongs to user's team
        if ($label->team_id !== $user->current_team_id) {
            throw new AuthorizationException();
        }

        if (!$user->hasTeamPermission($user->current_team, 'item:write') ||
            !$user->tokenCan('item:write')
        ) {
            throw new AuthorizationException();
        }

        $label->update($data);
        return response()->json($label);
    }

    /**
     * Remove the specified label.
     */
    public function destroy(Request $request, Label $label): JsonResponse
    {
        $user = $request->user();

        // Check if label belongs to user's team
        if ($label->team_id !== $user->current_team_id) {
            throw new AuthorizationException();
        }

        if (!$user->hasTeamPermission($user->current_team, 'item:write') ||
            !$user->tokenCan('item:write')
        ) {
            throw new AuthorizationException();
        }

        $label->delete();
        return response()->json(['success' => true]);
    }

    /**
     * Attach label to item
     */
    public function attachToItem(Request $request, Item $item): JsonResponse
    {
        $data = $request->validate([
            'label_id' => 'required|string|exists:labels,id',
        ]);

        $user = $request->user();

        // Check if item belongs to user's team
        if ($item->team_id !== $user->current_team_id) {
            throw new AuthorizationException();
        }

        // Don't allow setting labels on items with parent_id
        if ($item->parent_id) {
            return response()->json(['error' => 'Cannot set labels on items that have a parent. Labels can only be set on top-level boxes.'], 422);
        }

        $label = Label::where('id', $data['label_id'])
            ->where('team_id', $user->current_team_id)
            ->firstOrFail();

        if (!$user->hasTeamPermission($user->current_team, 'item:write') ||
            !$user->tokenCan('item:write')
        ) {
            throw new AuthorizationException();
        }

        // Check if label is already attached
        if ($item->labels()->where('label_id', $data['label_id'])->exists()) {
            return response()->json(['error' => 'Label already attached to item'], 409);
        }

        $item->labels()->attach($data['label_id']);

        return response()->json([
            'success' => true,
            'item' => $item->fresh(['labels'])
        ]);
    }

    /**
     * Detach label from item
     */
    public function detachFromItem(Request $request, Item $item, Label $label): JsonResponse
    {
        $user = $request->user();

        // Check if item belongs to user's team
        if ($item->team_id !== $user->current_team_id) {
            throw new AuthorizationException();
        }

        // Check if label belongs to user's team
        if ($label->team_id !== $user->current_team_id) {
            throw new AuthorizationException();
        }

        if (!$user->hasTeamPermission($user->current_team, 'item:write') ||
            !$user->tokenCan('item:write')
        ) {
            throw new AuthorizationException();
        }

        $item->labels()->detach($label->id);

        return response()->json([
            'success' => true,
            'item' => $item->fresh(['labels'])
        ]);
    }
}
