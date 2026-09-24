<?php

namespace App\Http\Controllers;

use App\Exceptions\PrintTargetOfflineException;
use App\Models\Item;
use App\Models\Team;
use App\Support\PrintQueue;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * API-039: token flavor of the print queue — what the Android app (or any
 * token client) uses. Pushing a label job and listing the live print
 * stations both require the `print:write` token ability PLUS the team's
 * `item:write` / `item:read` Jetstream permission, resolved through the
 * token's effective team (API-015). Session-based web flavors of the same
 * queue live in PrintQueueController.
 */
class PrintApiController extends Controller
{
    /**
     * POST api/print/jobs (API-039) — enqueue an item label. Body: `item`
     * (uuid, must belong to the effective team → 404 otherwise), `template`
     * (one of 30334/30252/30336 → 422 otherwise), optional `target_printer`
     * (DYMO printer name). An untargeted job always enqueues and waits for
     * the first live station to claim it; a targeted one requires a live
     * station serving that printer RIGHT NOW — otherwise 409 with the
     * reason ("Printer "X" is not available"), so the app can tell an
     * offline printer apart from other failures.
     *
     * Queue writes do not bump the team revision (printing is plumbing).
     *
     * @param Request $request
     * @return JsonResponse
     * @throws AuthorizationException|ValidationException
     */
    public function storeJob(Request $request): JsonResponse
    {
        $user = $request->user();
        $team = $user->effectiveTeam();

        if (!$team instanceof Team ||
            !$user->hasTeamPermission($team, 'item:write') ||
            !$user->tokenCan('print:write')
        ) {
            throw new AuthorizationException();
        }

        $data = $request->validate([
            'item' => 'required|string|max:191',
            'template' => 'required|string|in:' . implode(',', PrintQueue::TEMPLATES),
            'target_printer' => 'nullable|string|max:191',
        ]);

        $item = Item::where('id', $data['item'])
            ->where('team_id', $team->id)
            ->firstOrFail();

        try {
            $job = PrintQueue::enqueue($team, $user, $item, $data['template'], $data['target_printer'] ?? null);
        } catch (PrintTargetOfflineException $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        }

        return response()->json([
            'success' => true,
            'job' => [
                'id' => $job->id,
                'status' => $job->status,
                'target_printer' => $job->target_printer,
            ],
        ], 201);
    }

    /**
     * GET api/print/stations (API-039) — the team's LIVE print stations
     * (heartbeat inside the liveness window), freshest first: the list an
     * app printer picker offers. A station whose workstation tab was closed
     * or hidden drops out automatically ~90s after its last heartbeat.
     *
     * @param Request $request
     * @return JsonResponse
     * @throws AuthorizationException
     */
    public function stations(Request $request): JsonResponse
    {
        $user = $request->user();
        $team = $user->effectiveTeam();

        if (!$team instanceof Team ||
            !$user->hasTeamPermission($team, 'item:read') ||
            !$user->tokenCan('print:write')
        ) {
            throw new AuthorizationException();
        }

        return response()->json([
            'stations' => PrintQueue::liveStations($team)->map(fn ($station) => [
                'id' => $station->id,
                'printer_name' => $station->printer_name,
                'label' => $station->label,
                'template' => $station->template,
                'last_seen_at' => optional($station->last_seen_at)->toIso8601String(),
            ])->values(),
        ]);
    }
}
