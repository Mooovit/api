<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\ItemBarcode;
use App\Support\TeamRevision;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ItemBarcodeController extends Controller
{
    /**
     * Authorization shared by attach/detach (API-011): the item's own team +
     * item:write token ability.
     *
     * @param Request $request
     * @param Item $item
     * @throws AuthorizationException
     */
    private function authorizeRegistryWrite(Request $request, Item $item): void
    {
        $user = $request->user();
        if (!$user->hasTeamPermission($item->team, 'item:write') ||
            !$user->tokenCan('item:write')
        ) {
            throw new AuthorizationException();
        }
    }

    /**
     * POST api/item/{item}/barcodes (API-011) — attach a scanned code to an
     * item. Codes are stored verbatim, whitespace-trimmed and case-sensitive;
     * uniqueness is team-scoped (database-level too) → 409 on duplicate,
     * including codes held by soft-deleted items of the same team (a trashed
     * item's codes stay reserved until hard delete).
     *
     * Registry writes are pivot-like: the team revision is bumped once but
     * the item row's `updated_at` is NOT touched (so API-006 deltas carry no
     * item body for it) — clients re-pull the item (show) to see its codes.
     *
     * @param Request $request
     * @param Item $item
     * @return JsonResponse
     * @throws AuthorizationException|ValidationException
     */
    public function attach(Request $request, Item $item): JsonResponse
    {
        $this->authorizeRegistryWrite($request, $item);

        $data = $request->validate([
            'code' => 'required|string|max:191',
            'type' => 'nullable|string|max:191',
        ]);

        $code = trim($data['code']);

        if (ItemBarcode::where('team_id', $item->team_id)->where('code', $code)->exists()) {
            return response()->json(['error' => 'Code already registered in this team'], 409);
        }

        $barcode = ItemBarcode::create([
            'item_id' => $item->id,
            'team_id' => $item->team_id,
            'code' => $code,
            'type' => $data['type'] ?? null,
        ]);

        /* Registry rows fire no revision observer — bump explicitly (API-003) */
        TeamRevision::bump($barcode);

        return response()->json([
            'success' => true,
            'item' => $item->fresh(['barcodes']),
        ], 201);
    }

    /**
     * DELETE api/item/{item}/barcodes/{barcode} (API-011) — detach a code.
     * `{barcode}` is the barcode row id; for convenience a raw code is also
     * accepted in the path, or via `?code=` (which wins when present). The
     * row must belong to the item, else 404. Bumps the team revision once.
     *
     * @param Request $request
     * @param Item $item
     * @param string $barcode row id or code
     * @return JsonResponse
     * @throws AuthorizationException
     */
    public function detach(Request $request, Item $item, string $barcode): JsonResponse
    {
        $this->authorizeRegistryWrite($request, $item);

        $row = $request->filled('code')
            ? $item->barcodes()->where('code', $request->query('code'))->firstOrFail()
            : ($item->barcodes()->whereKey($barcode)->first()
                ?? $item->barcodes()->where('code', $barcode)->firstOrFail());

        $row->delete();

        /* Registry rows fire no revision observer — bump explicitly (API-003) */
        TeamRevision::bump($item);

        return response()->json([
            'success' => true,
            'item' => $item->fresh(['barcodes']),
        ]);
    }
}
