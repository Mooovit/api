<?php

namespace App\Models;

use App\Traits\Uuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * API-037: one offline sync push from a phone — the batch receipt. Holds
 * the per-outcome totals the GET endpoint echoes back for reconciliation.
 *
 * Audit row only: never serialized to the mobile delta, deliberately NOT
 * watched by the team-revision observer (recording the receipt must not
 * bump the sync cursor), no SoftDeletes (cascades with the team).
 *
 * @property string $id
 * @property string $team_id
 * @property string $user_id
 * @property int $total
 * @property int $applied
 * @property int $conflicted
 * @property int $noop
 * @property int $failed
 * @property \Illuminate\Support\Carbon|null $finished_at
 */
class SyncBatch extends Model
{
    use HasFactory;
    use Uuids;

    public $fillable = [
        'team_id', 'user_id', 'total', 'applied', 'conflicted', 'noop',
        'failed', 'finished_at',
    ];

    protected $casts = [
        'finished_at' => 'datetime',
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
     * Submitted operations, in submission order (the `index` column).
     * Explicit FK: the column is `batch_id` (not the conventional
     * `sync_batch_id` Laravel would derive from this model's name).
     * @return HasMany
     */
    public function operations(): HasMany
    {
        return $this->hasMany(SyncBatchOperation::class, 'batch_id')->orderBy('index');
    }
}
