<?php

namespace App\Http\Controllers;

use App\Models\History;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ActivityController extends Controller
{
    /**
     * GET api/activity (API-009) — team-wide activity feed: the same change
     * rows as per-item history, newest first (`changed_at` desc, `id` desc as
     * tiebreaker), paginated.
     *
     * Filters: `since` (ISO-8601), `item_id`, `type` (= field_name:
     * name|location_id|status_id|parent_id), `location_id` (= "moves INTO
     * that location": `field_name='location_id' AND new_value=<id>`), plus
     * `page` / `per_page` (default 50, max 200).
     *
     * Team-scoped through the owning item; authorization matches the other
     * read endpoints (item:read team permission + token ability). Histories
     * of soft-deleted (trashed) items are excluded by the same scope —
     * consistent with `api/item/:id/history`, which 404s for trashed items.
     * `old_value`/`new_value` stay raw (UUIDs for *_id fields) — the client
     * resolves names, exactly like item history today.
     *
     * @param Request $request
     * @return JsonResponse
     * @throws AuthorizationException|ValidationException
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $team = $user->currentTeam;

        if (!$team instanceof \App\Models\Team ||
            !$user->hasTeamPermission($team, 'item:read') ||
            !$user->tokenCan('item:read')
        ) {
            throw new AuthorizationException();
        }

        $data = $request->validate([
            'since' => 'date',
            'item_id' => 'string',
            'location_id' => 'string',
            'type' => 'string|in:name,location_id,status_id,parent_id',
            'page' => 'integer|min:1',
            'per_page' => 'integer|min:1|max:200',
        ]);

        $query = History::query()
            ->whereHas('item', function ($q) use ($user) {
                $q->where('team_id', $user->current_team_id);
            })
            ->with(['item:id,name', 'user:id,name'])
            ->orderByDesc('changed_at')
            ->orderByDesc('id');

        if (isset($data['since'])) {
            $query->where('changed_at', '>', $request->date('since'));
        }
        if (isset($data['item_id'])) {
            $query->where('item_id', $data['item_id']);
        }
        if (isset($data['type'])) {
            $query->where('field_name', $data['type']);
        }
        if (isset($data['location_id'])) {
            /* "Recent moves in location X": rows describing moves INTO it */
            $query->where('field_name', 'location_id')
                ->where('new_value', $data['location_id']);
        }

        $paginator = $query->paginate((int) ($data['per_page'] ?? 50))->through(
            fn (History $history) => [
                'id' => $history->id,
                'item_id' => $history->item_id,
                'item_name' => $history->item->name ?? null,
                'user_id' => $history->user_id,
                'user_name' => $history->user->name ?? null,
                'field_name' => $history->field_name,
                'old_value' => $history->old_value,
                'new_value' => $history->new_value,
                'changed_at' => $history->changed_at,
            ]
        );

        return response()->json($paginator);
    }
}
