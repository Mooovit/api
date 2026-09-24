<?php

namespace App\Models;

use App\Traits\Uuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * API-039: one requested sticker. Statuses: pending (waiting for a station),
 * claimed (a station took it and is printing), printed, failed (terminal —
 * after PrintQueue::MAX_ATTEMPTS). `target_printer` is the optional DYMO
 * printer name a pusher demanded; null lets any live station claim the job.
 * Jobs persist until printed/failed — no TTL in v1.
 *
 * Plumbing row: not revision-observed, no SoftDeletes (cascades with the
 * team) — see the API-039 migration docblock.
 *
 * @property string $id
 * @property string $team_id
 * @property string|null $user_id
 * @property string $item_id
 * @property string $template
 * @property string|null $target_printer
 * @property string $status
 * @property string|null $claimed_by
 * @property \Illuminate\Support\Carbon|null $claimed_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property string|null $error
 * @property int $attempts
 */
class PrintJob extends Model
{
    use HasFactory;
    use Uuids;

    public const STATUS_PENDING = 'pending';
    public const STATUS_CLAIMED = 'claimed';
    public const STATUS_PRINTED = 'printed';
    public const STATUS_FAILED = 'failed';

    public $fillable = [
        'team_id', 'user_id', 'item_id', 'template', 'target_printer', 'status',
        'claimed_by', 'claimed_at', 'completed_at', 'error', 'attempts',
    ];

    protected $casts = [
        'claimed_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * Team Relation
     * @return BelongsTo
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Item Relation
     * @return BelongsTo
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * The station that claimed/printed this job (nullable).
     * Explicit FK: the column is `claimed_by`.
     * @return BelongsTo
     */
    public function claimer(): BelongsTo
    {
        return $this->belongsTo(PrintStation::class, 'claimed_by');
    }
}
