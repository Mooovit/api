<?php

namespace App\Models;

use App\Traits\Uuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A savepoint backup (API-018): the zip of CSV dumps lives on a Storage
 * disk (`disk` + unique `path`); the row is metadata only and every
 * serialized shape goes through `metadata()` — the storage path never
 * leaves the server. `user_id` is provenance ("this backup belongs to
 * that user"), not an ACL — any member with `item:read` may download.
 */
class Backup extends Model
{
    use Uuids;

    public $fillable = [
        'id',
        'team_id',
        'user_id',
        'disk',
        'path',
        'size',
        'item_count',
        'location_count',
        'status_count',
        'label_count',
    ];

    /**
     * The safe, client-facing shape: counts, size, a download URL — never
     * `disk`/`path`.
     *
     * @return array
     */
    public function metadata(): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'size' => (int) $this->size,
            'item_count' => (int) $this->item_count,
            'location_count' => (int) $this->location_count,
            'status_count' => (int) $this->status_count,
            'label_count' => (int) $this->label_count,
            'url' => url("/api/backup/{$this->id}"),
            'created_at' => $this->created_at,
        ];
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

    /**
     * The creator.
     *
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
