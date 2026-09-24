<?php

namespace App\Http\Controllers;

use App\Exceptions\PrintTargetOfflineException;
use App\Models\Item;
use App\Models\PrintJob;
use App\Models\PrintStation;
use App\Models\Team;
use App\Support\PrintQueue;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * API-039: session flavor of the print queue — what the kanban page and the
 * /label-print station call (they cannot use /api/* — no stateful session
 * group there; precedent: the API-024 share trio in KanbanController).
 * Queue semantics all live in App\Support\PrintQueue; these endpoints only
 * resolve the team, authorize, and shape the responses.
 *
 * A print station identifies itself with a browser-local `client_id`
 * (localStorage uuid, localStorage key `dymo.station`) — server-side rows
 * are upserted on [team_id, client_id].
 */
class PrintQueueController extends Controller
{
    /**
     * POST /print/jobs (API-039) — enqueue an item label from the web UI.
     * Same contract as the token flavor (PrintApiController::storeJob):
     * team item or 404, template whitelist or 422, dead target printer 409.
     *
     * @param Request $request
     * @return JsonResponse
     * @throws AuthorizationException|ValidationException
     */
    public function storeJob(Request $request): JsonResponse
    {
        [$user, $team] = $this->authorizeTeam($request, 'item:write');

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
     * GET /print/claim (API-039) — the station worker's poll: heart-beats
     * the station (upsert by client_id) and hands out the next claimable
     * job, exclusively (FIFO, jobs targeted at this station's printer
     * first). Params are query-string (GET bodies are unreliable):
     * `client_id` (required), `printer_name` (required), `label`/`template`
     * (optional, stored on the station). Response carries the print-ready
     * payload (item uuid + current name + template) so the station never
     * has to re-fetch the item; `{job: null}` when the queue is drained.
     *
     * @param Request $request
     * @return JsonResponse
     * @throws AuthorizationException|ValidationException
     */
    public function claim(Request $request): JsonResponse
    {
        [$user, $team] = $this->authorizeTeam($request, 'item:read');

        $data = $request->validate([
            'client_id' => 'required|string|max:191',
            'printer_name' => 'required|string|max:191',
            'label' => 'nullable|string|max:191',
            'template' => 'nullable|string|max:8',
        ]);

        $station = PrintQueue::heartbeat(
            $team,
            $data['client_id'],
            $data['printer_name'],
            $data['label'] ?? null,
            $data['template'] ?? null
        );

        $job = PrintQueue::claim($team, $station);

        if ($job === null) {
            return response()->json(['job' => null]);
        }

        return response()->json([
            'job' => [
                'id' => $job->id,
                'item_id' => $job->item_id,
                'item_name' => $job->item->name,
                'template' => $job->template,
                'target_printer' => $job->target_printer,
            ],
        ]);
    }

    /**
     * POST /print/jobs/{job}/complete (API-039) — a station reports its
     * claim outcome. Body: `client_id` (identifies the reporting station —
     * only the owner's report counts; a mismatch is a no-op success:false,
     * not an error), `ok` boolean, optional `error` text. On failure the
     * job returns to pending until PrintQueue::MAX_ATTEMPTS, then goes
     * terminal failed with the error kept.
     *
     * @param Request $request
     * @param string $jobId
     * @return JsonResponse
     * @throws AuthorizationException|ValidationException
     */
    public function complete(Request $request, string $jobId): JsonResponse
    {
        [$user, $team] = $this->authorizeTeam($request, 'item:read');

        $data = $request->validate([
            'client_id' => 'required|string|max:191',
            'ok' => 'required|boolean',
            'error' => 'nullable|string|max:500',
        ]);

        /* Team-scoped lookups by hand — a job from another team is a plain
           404, no existence leak (house rule for {id} paths). */
        $job = PrintJob::where('id', $jobId)
            ->where('team_id', $team->id)
            ->firstOrFail();

        $station = PrintStation::where('team_id', $team->id)
            ->where('client_id', $data['client_id'])
            ->first();

        $applied = $station !== null
            && PrintQueue::complete($job, $station, (bool) $data['ok'], $data['error'] ?? null);

        return response()->json([
            'success' => $applied,
            'status' => $job->fresh()->status,
        ]);
    }

    /**
     * POST /print/station (API-039) — declare/refresh a printer for this
     * workstation: upserts the station row and stamps the heartbeat. Sent
     * once when a page's DYMO detection succeeds, and again by the worker's
     * claim polls (GET /print/claim does the same heartbeat inline).
     *
     * @param Request $request
     * @return JsonResponse
     * @throws AuthorizationException|ValidationException
     */
    public function station(Request $request): JsonResponse
    {
        [$user, $team] = $this->authorizeTeam($request, 'item:read');

        $data = $request->validate([
            'client_id' => 'required|string|max:191',
            'printer_name' => 'required|string|max:191',
            'label' => 'nullable|string|max:191',
            'template' => 'nullable|string|max:8',
        ]);

        $station = PrintQueue::heartbeat(
            $team,
            $data['client_id'],
            $data['printer_name'],
            $data['label'] ?? null,
            $data['template'] ?? null
        );

        return response()->json([
            'success' => true,
            'station' => [
                'id' => $station->id,
                'printer_name' => $station->printer_name,
                'template' => $station->template,
                'last_seen_at' => optional($station->last_seen_at)->toIso8601String(),
            ],
        ]);
    }

    /**
     * Session authz shared by all four endpoints: the user's current team
     * (session requests carry no Sanctum ability list, tokenCan() is
     * vacuously true) + the given Jetstream team permission.
     *
     * @param Request $request
     * @param string $permission
     * @return array{0: \App\Models\User, 1: Team}
     * @throws AuthorizationException
     */
    private function authorizeTeam(Request $request, string $permission): array
    {
        $user = $request->user();
        $team = $user->current_team;

        if (!$team instanceof Team || !$user->hasTeamPermission($team, $permission)) {
            throw new AuthorizationException();
        }

        return [$user, $team];
    }
}
