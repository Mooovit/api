<?php

namespace App\Http\Controllers;

use App\Models\BackupSchedule;
use App\Models\Team;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Auto-backup schedule management from the server UI (API-020, web only —
 * no API surface by design). One schedule per team: POST is an upsert
 * (create or update — no PATCH, host load-balancer constraint), DELETE
 * removes it. `next_run_at` is computed from now at save time; disabling
 * clears it until the next save.
 */
class BackupScheduleController extends Controller
{
    /**
     * The requesting user may touch the team's schedule at the given
     * permission level: team membership + Jetstream permission +
     * token ability (a session's TransientToken grants every ability, so
     * session-authenticated UI requests pass — same convention as the
     * other controllers).
     *
     * @param Request $request
     * @param Team $team
     * @param string $permission 'item:read' or 'item:write'
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
     * GET /teams/{team}/backups/schedule — the Inertia page with the
     * team's schedule (if any) and the frequency options.
     *
     * @param Request $request
     * @param Team $team
     * @return Response
     * @throws AuthorizationException
     */
    public function show(Request $request, Team $team): Response
    {
        $this->authorizeTeam($request, $team, 'item:read');

        $schedule = $team->backupSchedule()->first();

        return Inertia::render('Backups/Schedules', [
            'team' => $team->only(['id', 'name']),
            'frequencies' => BackupSchedule::FREQUENCIES,
            'schedule' => $schedule ? $schedule->only(
                ['frequency', 'enabled', 'last_run_at', 'next_run_at']
            ) : null,
        ]);
    }

    /**
     * POST /teams/{team}/backups/schedule — upsert the schedule (POST, not
     * PATCH — host constraint). Enabling (re)computes `next_run_at` from
     * now; disabling clears it (a disabled schedule is never due).
     *
     * @param Request $request
     * @param Team $team
     * @return RedirectResponse
     * @throws AuthorizationException
     */
    public function store(Request $request, Team $team): RedirectResponse
    {
        $user = $request->user();
        $this->authorizeTeam($request, $team, 'item:write');

        $data = $request->validate([
            'frequency' => ['required', Rule::in(BackupSchedule::FREQUENCIES)],
            'enabled' => ['required', 'boolean'],
        ]);

        $team->backupSchedule()->firstOrNew()->forceFill([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'frequency' => $data['frequency'],
            'enabled' => $data['enabled'],
            'next_run_at' => $data['enabled']
                ? BackupSchedule::nextRunFor($data['frequency'])
                : null,
        ])->save();

        return Redirect::route('backups.schedules.show', ['team' => $team->id])
            ->with('success', 'Auto-backup schedule saved.');
    }

    /**
     * DELETE /teams/{team}/backups/schedule — remove the schedule (the
     * already-created savepoints stay; retention still applies).
     *
     * @param Request $request
     * @param Team $team
     * @return RedirectResponse
     * @throws AuthorizationException
     */
    public function destroy(Request $request, Team $team): RedirectResponse
    {
        $this->authorizeTeam($request, $team, 'item:write');

        optional($team->backupSchedule()->first())->delete();

        return Redirect::route('backups.schedules.show', ['team' => $team->id])
            ->with('success', 'Auto-backup schedule removed.');
    }
}
