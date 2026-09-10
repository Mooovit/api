<?php

namespace App\Support;

use App\Models\Item;
use App\Models\ItemShareLink;

/**
 * API-024: the share-link rules shared by the API controller
 * (ItemShareController, token-flavored) and the kanban session flavor
 * (KanbanController::shareItem/unshareItem) — one source of truth for the
 * pinned semantics:
 *  - ONE row per item (item_id unique);
 *  - activating while active is idempotent (same token, no revision churn);
 *  - re-activating a REVOKED link mints a FRESH token (a revoked URL stays
 *    dead forever) and bumps the revision;
 *  - revoking stamps deactivated_at (idempotent, bumps only when a link was
 *    actually revoked);
 *  - a revoked link's payload exposes neither the token nor the URL.
 */
class ItemShare
{
    /**
     * Activate (or re-issue) the item's public link.
     *
     * @return array{payload: array, created: bool} — `created` is true on the
     *         first activation (API answers 201), false for idempotent
     *         re-activation and revocation re-issue (both 200).
     */
    public static function activate(Item $item): array
    {
        $existing = ItemShareLink::where('item_id', $item->id)->first();

        if ($existing && $existing->isActive()) {
            return ['payload' => self::payload($existing), 'created' => false];
        }

        if ($existing) {
            $existing->fill([
                'token' => ItemShareLink::freshToken(),
                'activated_at' => now(),
                'deactivated_at' => null,
            ])->save();

            TeamRevision::bump($item);

            return ['payload' => self::payload($existing), 'created' => false];
        }

        $link = ItemShareLink::create([
            'item_id' => $item->id,
            'team_id' => $item->team_id,
            'token' => ItemShareLink::freshToken(),
            'activated_at' => now(),
        ]);

        TeamRevision::bump($item);

        return ['payload' => self::payload($link), 'created' => true];
    }

    /**
     * Revoke the item's public link. Idempotent.
     *
     * @return bool true when an active link was actually revoked (and the
     *              revision bumped).
     */
    public static function revoke(Item $item): bool
    {
        $link = ItemShareLink::where('item_id', $item->id)
            ->whereNull('deactivated_at')
            ->first();

        if (!$link) {
            return false;
        }

        $link->fill(['deactivated_at' => now()])->save();
        TeamRevision::bump($item);

        return true;
    }

    /**
     * The flat link payload. A revoked link exposes nothing usable: both the
     * URL and the token come back null.
     */
    public static function payload(ItemShareLink $link): array
    {
        $active = $link->isActive();

        return [
            'share_url' => $active ? url('/share/' . $link->token) : null,
            'token' => $active ? $link->token : null,
            'activated_at' => optional($link->activated_at)->toISOString(),
            'deactivated_at' => optional($link->deactivated_at)->toISOString(),
        ];
    }

    /**
     * The state shape for an item that has no link row yet.
     */
    public static function emptyPayload(): array
    {
        return [
            'share_url' => null,
            'token' => null,
            'activated_at' => null,
            'deactivated_at' => null,
        ];
    }

    /**
     * The item's current link state, ready to serialize.
     */
    public static function state(Item $item): array
    {
        $link = ItemShareLink::where('item_id', $item->id)->first();

        return $link ? self::payload($link) : self::emptyPayload();
    }
}
