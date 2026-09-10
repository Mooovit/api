<?php

namespace App\Support\Backup;

use App\Models\Attachment;
use App\Models\Item;
use App\Models\ItemBarcode;
use App\Models\Label;
use App\Models\Location;
use App\Models\Status;
use App\Models\Team;
use Illuminate\Support\Facades\DB;

/**
 * API-029 — builds the cold-storage snapshot of one team: the PHP twin of
 * the app's MV-136 `BackupSnapshotBuilder.kt`, emitting the EXACT field
 * names of the MV-135 Kotlin `BackupSnapshot` model (camelCase — the app's
 * kotlinx parser is the consumer of the decoded JSON).
 *
 * Mapping rules (mirroring the app builder, verified against its source):
 * - **Server ids as-is** — a restore re-adopts them verbatim.
 * - Tombstoned statuses/locations ARE included (`deletedAt` rides in the
 *   snapshot, API-027 semantics) — history may still reference them.
 * - Items are LIVE-only: schema v1's `BackupItem` has no deletedAt
 *   representation, and the app builder likewise archives the live dataset
 *   (its deletion queue is skipped).
 * - `isBox = parent_id === null` — the app's own derivation
 *   (LocalInventoryRepository `isBox = parentId == null`).
 * - `locationId`/`statusId` are NON-nullable in the Kotlin model: a null
 *   server value serializes as `""` (an explicit JSON null would abort the
 *   app-side parse of the whole bundle).
 * - Attachment bytes are never archived (a printed backup cannot carry
 *   them) — metadata rows only, and no storage paths leave the server.
 * - Every list is deterministically ordered, so the same dataset always
 *   yields byte-identical bundles (pinned by tests).
 */
class BackupSnapshotBuilder
{
    /**
     * The snapshot array, keyed exactly like the Kotlin
     * `BackupSnapshot` (declaration order preserved — the JSON key order).
     *
     * @param Team $team
     * @param \DateTimeInterface $exportedAt
     * @return array
     */
    public static function build(Team $team, \DateTimeInterface $exportedAt): array
    {
        $statuses = Status::withTrashed()
            ->where('team_id', $team->id)
            ->orderBy('name')->orderBy('id')
            ->get(['id', 'name', 'deleted_at']);
        $locations = Location::withTrashed()
            ->where('team_id', $team->id)
            ->orderBy('name')->orderBy('id')
            ->get(['id', 'name', 'deleted_at']);
        $labels = Label::where('team_id', $team->id)
            ->orderBy('name')->orderBy('id')
            ->get(['id', 'name', 'color']);

        $items = Item::where('team_id', $team->id)
            ->orderBy('created_at')->orderBy('id')
            ->get(['id', 'name', 'parent_id', 'team_id', 'location_id', 'status_id']);
        $itemIds = $items->pluck('id');

        $itemLabels = DB::table('item_label')
            ->whereIn('item_id', $itemIds)
            ->orderBy('item_id')->orderBy('label_id')
            ->get(['item_id', 'label_id']);
        $barcodes = ItemBarcode::whereIn('item_id', $itemIds)
            ->orderBy('item_id')->orderBy('code')->orderBy('id')
            ->get(['id', 'item_id', 'code', 'type']);
        $attachments = Attachment::whereIn('item_id', $itemIds)
            ->orderBy('item_id')->orderBy('id')
            ->get(['id', 'item_id', 'caption']);

        return [
            'schemaVersion' => BackupCodec::SCHEMA_VERSION,
            'teamId' => $team->id,
            /* Pipe-free ISO-8601 (the Kotlin manifest parser splits on `|`
               positionally) — same shape as the app's device clock stamp. */
            'exportedAt' => $exportedAt->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s\Z'),
            'statuses' => $statuses->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'deletedAt' => $s->deleted_at?->toISOString(),
            ])->all(),
            'locations' => $locations->map(fn ($l) => [
                'id' => $l->id,
                'name' => $l->name,
                'deletedAt' => $l->deleted_at?->toISOString(),
            ])->all(),
            'labels' => $labels->map(fn ($l) => [
                'id' => $l->id,
                'name' => $l->name,
                'color' => $l->color,
                /* Forward-compat sort order (MV-135) — the server has no
                   such column; the app always writes null. */
                'order' => null,
            ])->all(),
            'items' => $items->map(fn ($i) => [
                'id' => $i->id,
                'name' => $i->name,
                'parentId' => $i->parent_id,
                'teamId' => $i->team_id,
                /* Kotlin non-nullable strings: null server values become
                   "" (never JSON null — that would kill the app parse). */
                'locationId' => $i->location_id ?? '',
                'statusId' => $i->status_id ?? '',
                'isBox' => $i->parent_id === null,
            ])->all(),
            'itemLabels' => $itemLabels->map(fn ($ref) => [
                'itemId' => $ref->item_id,
                'labelId' => $ref->label_id,
            ])->all(),
            'barcodes' => $barcodes->map(fn ($b) => [
                'id' => $b->id,
                'itemId' => $b->item_id,
                'code' => $b->code,
                'type' => $b->type,
            ])->all(),
            'attachments' => $attachments->map(fn ($a) => [
                'id' => $a->id,
                'itemId' => $a->item_id,
                'caption' => $a->caption,
            ])->all(),
        ];
    }
}
