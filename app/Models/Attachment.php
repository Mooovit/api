<?php

namespace App\Models;

use App\Traits\Uuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An image attached to an item (API-013). The binary lives on a Storage
 * disk at `attachments/{team_id}/{item_id}/{uuid}.{ext}` (`disk` + unique
 * `path`); the row is metadata only and every serialized shape goes through
 * `metadata()` — the storage path never leaves the server.
 */
class Attachment extends Model
{
    use HasFactory;
    use Uuids;

    public $fillable = [
        'item_id',
        'team_id',
        'user_id',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size',
        'caption',
    ];

    /**
     * The safe, client-facing shape: names, sizes, a download URL — never
     * `disk`/`path`.
     *
     * @return array
     */
    public function metadata(): array
    {
        return [
            'id' => $this->id,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size' => (int) $this->size,
            'caption' => $this->caption,
            'user_id' => $this->user_id,
            'url' => url("/api/attachment/{$this->id}"),
            'created_at' => $this->created_at,
        ];
    }

    /**
     * The attached item.
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

    /**
     * The uploader.
     *
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
