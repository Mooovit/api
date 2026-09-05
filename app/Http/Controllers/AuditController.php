<?php

namespace App\Http\Controllers;

use App\Models\Audit;
use App\Models\Item;
use App\Models\Location;
use App\Support\TeamRevision;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AuditController extends Controller
{
    /**
     * POST api/location/{location}/audits (API-010) — record a stocktake
     * snapshot for a location. The body is the MV-050 report:
     * `{found_ids: [], extra: [{id}], unknown_codes: [], missing_count?}`.
     *
     * found/extra counts are computed server-side; `missing_count` is a
     * declared input (the expected set is client knowledge). Every referenced
     * id must belong to the location's team — team membership only, snapshot
     * semantics: current location is not checked and soft-deleted (trashed)
     * items still validate. The audit also surfaces in the API-009 activity
     * feed as a synthetic `audit` event, and bumps the team revision once.
     *
     * @param Request $request
     * @param Location $location
     * @return Audit
     * @throws AuthorizationException|ValidationException
     */
    public function store(Request $request, Location $location): Audit
    {
        $user = $request->user();
        if (!$user->hasTeamPermission($location->team, 'item:write') ||
            !$user->tokenCan('item:write')
        ) {
            throw new AuthorizationException();
        }

        $data = $request->validate([
            'found_ids' => 'required|array',
            'found_ids.*' => 'string',
            'extra' => 'nullable|array',
            'extra.*.id' => 'required_with:extra|string',
            'unknown_codes' => 'nullable|array',
            'unknown_codes.*' => 'string',
            'missing_count' => 'nullable|integer|min:0',
        ]);

        $foundIds = array_values($data['found_ids']);
        $extra = array_values($data['extra'] ?? []);
        $unknownCodes = array_values($data['unknown_codes'] ?? []);
        $missingCount = (int) ($data['missing_count'] ?? 0);

        /* Snapshot semantics: validate team membership only (trashed rows
           included), never the items' current location */
        $referencedIds = array_values(array_unique(array_merge(
            $foundIds,
            array_map(fn (array $row) => $row['id'], $extra)
        )));

        $inTeam = Item::withTrashed()
            ->whereIn('id', $referencedIds)
            ->where('team_id', $location->team_id)
            ->count();
        if (count($referencedIds) !== $inTeam) {
            throw ValidationException::withMessages([
                'found_ids' => 'Some referenced items do not belong to this team (or do not exist).',
            ]);
        }

        return DB::transaction(function () use ($user, $location, $foundIds, $extra, $unknownCodes, $missingCount) {
            $audit = Audit::create([
                'team_id' => $location->team_id,
                'location_id' => $location->id,
                'user_id' => $user->id,
                'found_count' => count($foundIds),
                'missing_count' => $missingCount,
                'extra_count' => count($extra),
                'payload' => [
                    'found_ids' => $foundIds,
                    'extra' => $extra,
                    'unknown_codes' => $unknownCodes,
                ],
            ]);

            /* The pivot-style bump: Audit is not registered with the API-003
               observer, and teams.revision must move so sync clients re-pull
               the activity feed. */
            TeamRevision::bump($audit);

            return $audit;
        });
    }

    /**
     * GET api/location/{location}/audits (API-010) — audit history for a
     * location, newest first, paginated (default 50, max 200).
     *
     * @param Request $request
     * @param Location $location
     * @return JsonResponse
     * @throws AuthorizationException
     */
    public function index(Request $request, Location $location): JsonResponse
    {
        $user = $request->user();
        if (!$user->hasTeamPermission($location->team, 'item:read') ||
            !$user->tokenCan('item:read')
        ) {
            throw new AuthorizationException();
        }

        $data = $request->validate([
            'page' => 'integer|min:1',
            'per_page' => 'integer|min:1|max:200',
        ]);

        return response()->json(
            Audit::where('location_id', $location->id)
                ->with('user:id,name')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate((int) ($data['per_page'] ?? 50))
        );
    }
}
