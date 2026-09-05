<?php

namespace App\Http\Controllers;

use App\Models\Team;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * API-015: per-device (per-token) current team.
 *
 * A PDA is a shared object that may work for several teams over a day; its
 * device token can be re-pointed without touching the user's own current
 * team. `GET api/device-team` returns the token's effective team plus every
 * team the user belongs to (so a device can present a picker and switch
 * itself); `POST api/device-team {team_id|null}` switches (null resets to
 * the user's default). Any valid token may read/switch — a read-only device
 * may still need to switch to a team it can read; membership is the only
 * gate. The switch itself changes no team data and bumps no revision.
 */
class DeviceTeamController extends Controller
{
    /**
     * The effective team for this token + the teams the user has access to.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $team = $user->effectiveTeam();

        return response()->json([
            'current' => $team ? ['id' => $team->id, 'name' => $team->name] : null,
            'teams' => $user->allTeams()
                ->map(fn (Team $team) => ['id' => $team->id, 'name' => $team->name])
                ->values(),
        ]);
    }

    /**
     * Switch this token's current team (`team_id: null` resets to the user's
     * default). Returns the same shape as `show()` with `current` updated.
     *
     * @param Request $request
     * @return JsonResponse
     * @throws AuthorizationException|ValidationException
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'team_id' => 'nullable|string|exists:teams,id',
        ]);

        $user = $request->user();
        $token = $user->currentAccessToken();

        /* Session users carry a TransientToken — nothing to re-point */
        if (!$token instanceof PersonalAccessToken) {
            throw new AuthorizationException();
        }

        if ($data['team_id'] ?? null) {
            $team = Team::findOrFail($data['team_id']);
            if (!$user->belongsToTeam($team)) {
                throw ValidationException::withMessages([
                    'team_id' => 'You do not belong to this team.',
                ]);
            }
        }

        $token->current_team_id = $data['team_id'] ?? null;
        $token->save();

        return $this->show($request);
    }
}
