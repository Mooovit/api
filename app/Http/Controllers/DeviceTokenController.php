<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceTokenController extends Controller
{
    /**
     * The ability set minted for device tokens (API-012): everything the
     * warehouse flows need — items, statuses, locations, labels, read+write —
     * nothing account/settings-level. Pinned by DeviceTokenTest.
     */
    public const DEVICE_ABILITIES = [
        'item:read', 'item:write',
        'status:read', 'status:write',
        'location:read', 'location:write',
        'label:read', 'label:write',
    ];

    /**
     * POST api/device-tokens (API-012) — mint a named, restricted device
     * token for the authenticated user (any valid token may call this,
     * including a sibling device token — acceptable for a single-warehouse
     * team, noted in server.md §10). The plain text token is returned exactly
     * once, whole — no `id|token` split like the legacy authenticate endpoint.
     *
     * @param Request $request
     * @return JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:191',
        ]);

        $token = $request->user()->createToken($data['name'], self::DEVICE_ABILITIES);

        return response()->json([
            'id' => $token->accessToken->id,
            'name' => $token->accessToken->name,
            'token' => $token->plainTextToken,
        ], 201);
    }

    /**
     * GET api/device-tokens (API-012) — the current user's fleet: metadata
     * only (`id`, `name`, `last_used_at`, `created_at`), never the secret —
     * powers the profile screen ("Bluebird #3, last seen 2 h ago").
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json(
            $request->user()->tokens()->get(['id', 'name', 'last_used_at', 'created_at'])
        );
    }

    /**
     * DELETE api/device-tokens/{id} (API-012) — revoke one of the current
     * user's tokens (row delete; another user's id → 404). The revoked token
     * gets 401 on its next use (Sanctum behavior); the login password keeps
     * working.
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $token = $request->user()->tokens()->where('id', $id)->firstOrFail();
        $token->delete();

        return response()->json(['success' => true]);
    }
}
