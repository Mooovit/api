<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Support\ItemShare;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API-024: public share links on items — activate, read the state, revoke.
 * The link is a random capability token (never the item uuid) served on the
 * unauthenticated read-only page `/share/{token}`. Writes require
 * `item:write` on the item's own team + the token ability (same matrix as
 * the barcode registry). The semantics live in App\Support\ItemShare, shared
 * with the kanban session flavor (API-025); each mutation bumps the team
 * revision once so the kanban live view notices.
 */
class ItemShareController extends Controller
{
    /**
     * POST api/item/{item}/share — activate (or re-activate) the public
     * link. Idempotent while active (200, same token); the first activation
     * returns 201; re-activating a revoked link issues a FRESH token (the
     * old URL stays dead). Never touches the item's `updated_at` (pivot-like
     * write — deltas carry no item body for it).
     *
     * @param  Request  $request
     * @param  Item  $item
     * @return JsonResponse
     * @throws AuthorizationException
     */
    public function share(Request $request, Item $item): JsonResponse
    {
        $this->authorizeShareWrite($request, $item);

        $result = ItemShare::activate($item);

        return response()->json(
            $result['payload'],
            $result['created'] ? 201 : 200
        );
    }

    /**
     * GET api/item/{item}/share — the current link state. Read requires the
     * usual read ability + token. No link yet → all-null shape.
     *
     * @param  Request  $request
     * @param  Item  $item
     * @return JsonResponse
     * @throws AuthorizationException
     */
    public function show(Request $request, Item $item): JsonResponse
    {
        $user = $request->user();
        if (!$user->hasTeamPermission($item->team, 'item:read') ||
            !$user->tokenCan('item:read')
        ) {
            throw new AuthorizationException();
        }

        return response()->json(ItemShare::state($item));
    }

    /**
     * DELETE api/item/{item}/share — revoke the public URL (stamps
     * `deactivated_at`; the token immediately stops resolving). Idempotent:
     * revoking without an active link still succeeds. Bumps the revision
     * only when a link was actually revoked.
     *
     * @param  Request  $request
     * @param  Item  $item
     * @return JsonResponse
     * @throws AuthorizationException
     */
    public function unshare(Request $request, Item $item): JsonResponse
    {
        $this->authorizeShareWrite($request, $item);

        ItemShare::revoke($item);

        return response()->json(['success' => true]);
    }

    /**
     * Authorization shared by share/unshare (API-024): the item's own team +
     * item:write token ability — mirrors the barcode registry matrix.
     *
     * @param  Request  $request
     * @param  Item  $item
     * @return void
     * @throws AuthorizationException
     */
    private function authorizeShareWrite(Request $request, Item $item): void
    {
        $user = $request->user();
        if (!$user->hasTeamPermission($item->team, 'item:write') ||
            !$user->tokenCan('item:write')
        ) {
            throw new AuthorizationException();
        }
    }
}
