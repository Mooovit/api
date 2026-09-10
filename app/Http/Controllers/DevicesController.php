<?php

namespace App\Http\Controllers;

use App\Models\EnrollmentCode;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;

/**
 * Device management (server UI) — the API-012/017 features behind a web
 * page: mint a device token (plain text shown exactly once via a session
 * flash, then gone), list the fleet (metadata only, never secrets), revoke
 * a token, and mint enrollment codes for enrolling new devices by hand.
 * Token minting is user-level (the same rule as the API endpoints): the
 * token inherits its issuer's team permissions, so a read-only member
 * gains nothing by minting.
 */
class DevicesController extends Controller
{
    /**
     * GET /devices.
     *
     * @param Request $request
     * @return \Inertia\Response
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $tokens = $user->tokens()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get(['id', 'name', 'last_used_at', 'created_at', 'current_team_id']);

        $teamNames = Team::whereIn('id', $tokens->pluck('current_team_id')->filter()->unique())
            ->pluck('name', 'id');

        return Inertia::render('Devices/Index', [
            'tokens' => $tokens->map(fn ($token) => [
                'id' => $token->id,
                'name' => $token->name,
                'current_team_id' => $token->current_team_id,
                'team' => $teamNames[$token->current_team_id] ?? null,
                'last_used_at' => $token->last_used_at,
                'created_at' => $token->created_at,
            ])->values(),
            'codes' => EnrollmentCode::where('user_id', $user->id)
                ->whereNull('used_at')
                ->where('expires_at', '>', now())
                ->orderByDesc('created_at')
                ->get()
                ->map(fn (EnrollmentCode $code) => [
                    'code' => $code->code,
                    'expires_at' => $code->expires_at,
                ])
                ->values(),
        ]);
    }

    /**
     * POST /devices/tokens — mint a device token with the exact API-012
     * ability set; the plain text secret is flashed for the single next
     * render (the page shows it once, then the flash is consumed).
     *
     * @param Request $request
     * @return RedirectResponse
     */
    public function storeToken(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:191',
        ]);

        $token = $request->user()->createToken(
            $data['name'],
            DeviceTokenController::DEVICE_ABILITIES
        );

        return Redirect::route('devices.index')
            ->with('device_token', $token->plainTextToken);
    }

    /**
     * DELETE /devices/tokens/{token} — revoke one of the current user's
     * tokens (another user's id → 404).
     *
     * @param Request $request
     * @param int $token
     * @return RedirectResponse
     */
    public function destroyToken(Request $request, int $token): RedirectResponse
    {
        $request->user()->tokens()->where('id', $token)->firstOrFail()->delete();

        return Redirect::route('devices.index')->with('success', 'Device token revoked.');
    }

    /**
     * DELETE /devices/tokens — revoke ALL of the current user's device
     * tokens at once (fleet reset). Only this user's tokens; the web
     * session itself is not a token, so the browser stays logged in.
     *
     * @param Request $request
     * @return RedirectResponse
     */
    public function destroyAllTokens(Request $request): RedirectResponse
    {
        $count = $request->user()->tokens()->delete();

        return Redirect::route('devices.index')
            ->with('success', "Revoked {$count} device " . ($count === 1 ? 'token' : 'tokens') . '.');
    }

    /**
     * POST /devices/tokens/{token}/team — re-point one of the current
     * user's device tokens to a team (`team_id: null` → follow the user's
     * default). Same rule as API-015: membership is the only gate, the
     * switch changes no team data and bumps no revision.
     *
     * @param Request $request
     * @param int $token
     * @return RedirectResponse
     */
    public function updateTokenTeam(Request $request, int $token): RedirectResponse
    {
        $data = $request->validate([
            'team_id' => ['nullable', 'string', 'exists:teams,id'],
        ]);

        $user = $request->user();

        if ($data['team_id'] ?? null) {
            $team = Team::findOrFail($data['team_id']);
            if (!$user->belongsToTeam($team)) {
                return Redirect::route('devices.index')
                    ->withErrors(['team_id' => 'You do not belong to this team.']);
            }
        }

        $user->tokens()->where('id', $token)->firstOrFail()
            ->forceFill(['current_team_id' => $data['team_id'] ?? null])
            ->save();

        return Redirect::route('devices.index')->with('success', 'Device team updated.');
    }

    /**
     * POST /devices/enrollment-codes — mint a one-time, 15-minute
     * enrollment code (shared mint logic with the API-017 endpoint).
     *
     * @param Request $request
     * @return RedirectResponse
     */
    public function mintCode(Request $request): RedirectResponse
    {
        $code = EnrollmentCode::mintFor($request->user());

        return Redirect::route('devices.index')->with('device_code', [
            'code' => $code->code,
            'expires_at' => $code->expires_at->toISOString(),
        ]);
    }
}
