<?php

namespace App\Models;

use App\Traits\Uuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One scanned code bound to an item (API-011). Codes are stored verbatim
 * (case-sensitive, whitespace-trimmed) and unique per team — enforced by the
 * `team_id`+`code` unique index, so a code stays reserved even while its item
 * is soft-deleted (rows cascade on hard delete only). Registry writes are
 * pivot-like: they bump the team revision but never the item's `updated_at`.
 */
class ItemBarcode extends Model
{
    use HasFactory;
    use Uuids;

    public $fillable = ['item_id', 'team_id', 'code', 'type'];

    /**
     * The scanned item.
     *
     * @return BelongsTo
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * The owning team.
     *
     * @return BelongsTo
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
