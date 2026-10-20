<?php

namespace App\Http\Controllers;

use App\Models\Audit;
use App\Models\Item;
use App\Models\Location;
use App\Models\Team;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Audit results (server UI) — the read-only web page behind the MV-050
 * stocktake snapshots (API-010, POST api/location/{location}/audits).
 * Per team: paginated audit list with the payload decoded and the
 * found/extra ids resolved to item names. Team authorization follows the
 * BackupsController web pattern (session users carry a TransientToken, so
 * tokenCan passes). `missing_count` stays a count-only figure — the
 * expected set is client knowledge and never reaches the server.
 */
class AuditsController extends Controller
{
    /**
     * The number of audits per page.
     *
     * @var int
     */
    private const PER_PAGE = 25;

    /**
     * Team-level authorization for the web routes (the team comes from the
     * URL, not from a token's current team).
     *
     * @param Request $request
     * @param Team $team
     * @param string $permission
     * @return void
     * @throws AuthorizationException
     */
    private function authorizeTeam(Request $request, Team $team, string $permission): void
    {
        $user = $request->user();

        if (!$user->belongsToTeam($team)
            || !$user->hasTeamPermission($team, $permission)
            || !$user->tokenCan($permission)
        ) {
            throw new AuthorizationException();
        }
    }

    /**
     * Resolve found/extra item ids to `{id, name, deleted}` rows — one
     * query for the whole page, trashed rows included (an item deleted
     * after the audit must still appear in the result).
     *
     * @param array $ids
     * @param Team $team
     * @return array id => row
     */
    private function itemIndex(array $ids, Team $team): array
    {
        if (!$ids) {
            return [];
        }

        return Item::withTrashed()
            ->whereIn('id', $ids)
            ->where('team_id', $team->id)
            ->get(['id', 'name', 'deleted_at'])
            ->keyBy('id')
            ->map(fn (Item $item) => [
                'id' => $item->id,
                'name' => $item->name,
                'deleted' => $item->trashed(),
            ])
            ->all();
    }

    /**
     * The client-facing audit shape for the page.
     *
     * @param Audit $audit
     * @param array $items id => {id, name, deleted}
     * @return array
     */
    private function shape(Audit $audit, array $items): array
    {
        $payload = $audit->payload ?? [];

        $resolve = fn (array $ids) => array_values(array_map(
            fn (string $id) => $items[$id] ?? ['id' => $id, 'name' => null, 'deleted' => false],
            $ids
        ));

        return [
            'id' => $audit->id,
            'found_count' => (int) $audit->found_count,
            'missing_count' => (int) $audit->missing_count,
            'extra_count' => (int) $audit->extra_count,
            'unknown_count' => count($payload['unknown_codes'] ?? []),
            'location' => $audit->location ? $audit->location->only(['id', 'name']) : null,
            'user' => $audit->user ? ['id' => $audit->user->id, 'name' => $audit->user->name] : null,
            'found' => $resolve($payload['found_ids'] ?? []),
            'extra' => $resolve(array_map(
                fn (array $row) => $row['id'] ?? '',
                $payload['extra'] ?? []
            )),
            'missing' => $resolve($payload['missing_ids'] ?? []),
            'unknown_codes' => array_values($payload['unknown_codes'] ?? []),
            'created_at' => $audit->created_at ? $audit->created_at->toISOString() : null,
        ];
    }

    /**
     * The page props.
     *
     * @param Team $team
     * @param Request $request
     * @return array
     */
    private function props(Team $team, Request $request): array
    {
        $data = $request->validate([
            'page' => 'integer|min:1',
            'location' => 'nullable|string',
        ]);

        $query = Audit::where('team_id', $team->id)
            ->with('location:id,name', 'user:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if (!empty($data['location'])) {
            $query->where('location_id', $data['location']);
        }

        $audits = $query->paginate(self::PER_PAGE, ['*'], 'page', (int) ($data['page'] ?? 1));

        /* One item lookup for the whole page */
        $ids = [];
        foreach ($audits->items() as $audit) {
            $payload = $audit->payload ?? [];
            foreach ($payload['found_ids'] ?? [] as $id) {
                $ids[$id] = true;
            }
            foreach ($payload['missing_ids'] ?? [] as $id) {
                $ids[$id] = true;
            }
            foreach ($payload['extra'] ?? [] as $row) {
                if (!empty($row['id'])) {
                    $ids[$row['id']] = true;
                }
            }
        }
        $items = $this->itemIndex(array_keys($ids), $team);

        return [
            'team' => $team->only(['id', 'name']),
            'locations' => $this->locations($team),
            'filtered_location' => $data['location'] ?? null,
            'audits' => [
                'data' => collect($audits->items())
                    ->map(fn (Audit $audit) => $this->shape($audit, $items))
                    ->values(),
                'current_page' => $audits->currentPage(),
                'last_page' => $audits->lastPage(),
                'total' => $audits->total(),
            ],
        ];
    }

    /**
     * The team's locations for the filter dropdown.
     *
     * @param Team $team
     * @return array
     */
    private function locations(Team $team): array
    {
        return Location::where('team_id', $team->id)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($location) => $location->only(['id', 'name']))
            ->values()
            ->all();
    }

    /**
     * GET /teams/{team}/audits.
     *
     * @param Request $request
     * @param Team $team
     * @return Response
     * @throws AuthorizationException
     */
    public function index(Request $request, Team $team): Response
    {
        $this->authorizeTeam($request, $team, 'item:read');

        return Inertia::render('Audits/Index', $this->props($team, $request));
    }
}
