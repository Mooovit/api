<?php

namespace App\Http\Controllers;

use App\Models\Audit;
use App\Models\History;
use App\Models\Team;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class ActivityController extends Controller
{
    /**
     * GET api/activity (API-009, unioned with audits since API-010) —
     * team-wide activity feed: change rows plus one synthetic `audit` row per
     * stocktake, newest first (`changed_at`/`created_at` desc, `id` desc as
     * tiebreaker inside each source), paginated (default 50, max 200).
     *
     * Filters: `since` (ISO-8601), `item_id` (excludes audits), `type`
     * (name|location_id|status_id|parent_id|audit — a non-audit type excludes
     * audit rows), `location_id` (history: "moves INTO that location";
     * audits: taken at that location), `page` / `per_page`.
     *
     * Team-scoped through the owning item / audit team; authorization matches
     * the other read endpoints (item:read team permission + token ability).
     * Histories of soft-deleted (trashed) items are excluded by the same
     * scope — consistent with `api/item/:id/history`, which 404s for trashed
     * items. Audit rows keep the same keys as history rows (one parser
     * client-side): `field_name: "audit"`, the computed summary in
     * `new_value`, null `item_*`/`old_value`, plus `location_id` and
     * `location_name`.
     *
     * @param Request $request
     * @return JsonResponse
     * @throws AuthorizationException|ValidationException
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        /* API-015: the token's current team when set, the user's otherwise */
        $team = $user->effectiveTeam();

        if (!$team instanceof Team ||
            !$user->hasTeamPermission($team, 'item:read') ||
            !$user->tokenCan('item:read')
        ) {
            throw new AuthorizationException();
        }

        $data = $request->validate([
            'since' => 'date',
            'item_id' => 'string',
            'location_id' => 'string',
            'type' => 'string|in:name,location_id,status_id,parent_id,audit',
            'page' => 'integer|min:1',
            'per_page' => 'integer|min:1|max:200',
        ]);

        $perPage = (int) ($data['per_page'] ?? 50);
        $page = (int) ($data['page'] ?? 1);
        $includeHistory = !isset($data['type']) || $data['type'] !== 'audit';
        $includeAudits = !isset($data['type']) || $data['type'] === 'audit';

        /* Ordered (id, timestamp) slices of both sources — cheap columns
           only; the page's rows are hydrated below. */
        $historyEntries = collect();
        if ($includeHistory) {
            $historyQuery = History::query()
                ->select('id', 'changed_at')
                ->whereHas('item', function ($q) use ($team) {
                    $q->where('team_id', $team->id);
                });

            if (isset($data['since'])) {
                $historyQuery->where('changed_at', '>', $request->date('since'));
            }
            if (isset($data['item_id'])) {
                $historyQuery->where('item_id', $data['item_id']);
            }
            if (isset($data['type'])) {
                $historyQuery->where('field_name', $data['type']);
            }
            if (isset($data['location_id'])) {
                /* "Recent moves in location X": rows describing moves INTO it */
                $historyQuery->where('field_name', 'location_id')
                    ->where('new_value', $data['location_id']);
            }

            $historyEntries = $historyQuery->orderByDesc('changed_at')->orderByDesc('id')
                ->get()
                ->map(fn (History $history) => [
                    'kind' => 'history', 'id' => $history->id, 'at' => $history->changed_at,
                ]);
        }

        $auditEntries = collect();
        if ($includeAudits && !isset($data['item_id'])) {
            $auditQuery = Audit::query()
                ->select('id', 'created_at')
                ->where('team_id', $team->id);

            if (isset($data['since'])) {
                $auditQuery->where('created_at', '>', $request->date('since'));
            }
            if (isset($data['location_id'])) {
                $auditQuery->where('location_id', $data['location_id']);
            }

            $auditEntries = $auditQuery->orderByDesc('created_at')->orderByDesc('id')
                ->get()
                ->map(fn (Audit $audit) => [
                    'kind' => 'audit', 'id' => $audit->id, 'at' => $audit->created_at,
                ]);
        }

        /* Merged, newest first; at identical timestamps histories come first
           (stable sort over the concat order). */
        $entries = $historyEntries->concat($auditEntries)
            ->sortByDesc(fn (array $entry) => $entry['at'])
            ->values();

        $slice = $entries->slice(($page - 1) * $perPage, $perPage)->values();

        /* Hydrate just the page's rows */
        $historyIds = $slice->where('kind', 'history')->pluck('id')->all();
        $auditIds = $slice->where('kind', 'audit')->pluck('id')->all();

        $histories = History::with(['item:id,name', 'user:id,name'])
            ->whereIn('id', $historyIds)->get()->keyBy('id');
        $audits = Audit::with(['user:id,name', 'location:id,name'])
            ->whereIn('id', $auditIds)->get()->keyBy('id');

        $rows = $slice->map(function (array $entry) use ($histories, $audits) {
            if ($entry['kind'] === 'history') {
                $history = $histories->get($entry['id']);

                return [
                    'id' => $history->id,
                    'item_id' => $history->item_id,
                    'item_name' => $history->item->name ?? null,
                    'user_id' => $history->user_id,
                    'user_name' => $history->user->name ?? null,
                    'field_name' => $history->field_name,
                    'old_value' => $history->old_value,
                    'new_value' => $history->new_value,
                    'changed_at' => $history->changed_at,
                ];
            }

            $audit = $audits->get($entry['id']);
            $summary = $audit->found_count . ' found'
                . ($audit->missing_count > 0 ? ", {$audit->missing_count} missing declared" : '')
                . ($audit->extra_count > 0 ? ", {$audit->extra_count} extra" : '');

            return [
                'id' => $audit->id,
                'item_id' => null,
                'item_name' => null,
                'user_id' => $audit->user_id,
                'user_name' => $audit->user->name ?? null,
                'field_name' => 'audit',
                'old_value' => null,
                'new_value' => $summary,
                'changed_at' => $audit->created_at,
                'location_id' => $audit->location_id,
                'location_name' => $audit->location->name ?? null,
            ];
        })->values();

        return response()->json(
            new LengthAwarePaginator($rows, $entries->count(), $perPage, $page)
        );
    }
}
