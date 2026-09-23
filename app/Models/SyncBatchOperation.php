<?php

namespace App\Models;

use App\Traits\Uuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * API-037: one operation inside a sync batch — the exact submission
 * (`payload`: op-specific fields + `base_revision` + per-field `base`
 * values) and the server-side outcome (`result` + `detail`).
 *
 * `result` is one of: applied | conflict | noop | error.
 *
 * Audit row only: no revision observer, no SoftDeletes (cascades with the
 * batch), and `detail` is opaque JSON shaped per op type.
 *
 * @property string $id
 * @property string $batch_id
 * @property string $op
 * @property int $index
 * @property string|null $item_id
 * @property array $payload
 * @property string $result
 * @property array|null $detail
 * @property \Illuminate\Support\Carbon|null $applied_at
 */
class SyncBatchOperation extends Model
{
    public const RESULT_APPLIED = 'applied';
    public const RESULT_CONFLICT = 'conflict';
    public const RESULT_NOOP = 'noop';
    public const RESULT_ERROR = 'error';

    use HasFactory;
    use Uuids;

    public $fillable = [
        'batch_id', 'op', 'index', 'item_id', 'payload', 'result',
        'detail', 'applied_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'detail' => 'array',
        'applied_at' => 'datetime',
    ];

    /**
     * Parent batch Relation
     * @return BelongsTo
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(SyncBatch::class, 'batch_id');
    }
}
