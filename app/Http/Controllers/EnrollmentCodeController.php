<?php

namespace App\Http\Controllers;

use App\Models\EnrollmentCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class EnrollmentCodeController extends Controller
{
    /**
     * POST api/enrollment-codes (API-017) — mint a one-time enrollment code
     * for the calling user (any valid token may call this, the same sibling
     * rule as API-012). Codes live 15 minutes, are single-use, and the
     * issuer's stale (used or expired) codes are pruned at mint — no
     * scheduler. The mint (prune + code generation) lives on the model and
     * is shared with the web devices page.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        $enrollmentCode = EnrollmentCode::mintFor($request->user());

        return response()->json([
            'code' => $enrollmentCode->code,
            'expires_at' => $enrollmentCode->expires_at,
        ], 201);
    }

    /**
     * POST api/enroll (API-017, unauthenticated) — exchange a scan/typed
     * enrollment code for a device token. The token belongs to the code's
     * issuer and carries exactly DeviceTokenController::DEVICE_ABILITIES
     * (same restriction as API-012). Redemption is atomic single-use: a
     * guarded conditional update claims the code — a concurrent second
     * redeemer loses and the freshly minted token is deleted again.
     *
     * Errors: unknown/malformed code or expired code → 422; an already-used
     * code → 410 Gone (pinned).
     *
     * @param Request $request
     * @return JsonResponse
     * @throws ValidationException
     */
    public function enroll(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => 'required|string',
            'name' => 'required|string|max:191',
        ]);

        $enrollmentCode = EnrollmentCode::where('code', trim($data['code']))->first();

        if (!$enrollmentCode) {
            throw ValidationException::withMessages([
                'code' => 'This enrollment code is unknown.',
            ]);
        }

        if ($enrollmentCode->used_at !== null) {
            abort(410, 'This enrollment code has already been used.');
        }

        if ($enrollmentCode->expires_at->isPast()) {
            throw ValidationException::withMessages([
                'code' => 'This enrollment code has expired.',
            ]);
        }

        /* The enrolled token belongs to the ISSUER */
        $token = $enrollmentCode->user->createToken(
            $data['name'],
            DeviceTokenController::DEVICE_ABILITIES
        );

        /* Race-safe claim: only an unused, unexpired row can be marked used.
           Losing the race (a concurrent redeemer claimed it first) rolls the
           mint back. */
        $claimed = EnrollmentCode::where('id', $enrollmentCode->id)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->update([
                'used_at' => now(),
                'used_by_token_id' => $token->accessToken->id,
            ]);

        if ($claimed === 0) {
            $token->accessToken->delete();

            abort(410, 'This enrollment code has already been used.');
        }

        return response()->json([
            'id' => $token->accessToken->id,
            'name' => $token->accessToken->name,
            'token' => $token->plainTextToken,
        ], 201);
    }
}
