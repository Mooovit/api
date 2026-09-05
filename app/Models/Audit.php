<?php

namespace App\Models;

use App\Traits\Uuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A stocktake (audit) of one location (API-010): the server-side snapshot of
 * an MV-050 local report. Counts are computed/validated server-side
 * (`found_count = count(found_ids)`, `extra_count = count(extra)`);
 * `missing_count` is a declared client input (the expected set lives in the
 * client's local cache). `payload` mirrors the exact MV-050 report keys.
 */
class Audit extends Model
{
    use HasFactory;
    use Uuids;

    public $fillable = [
        'team_id',
        'location_id',
        'user_id',
        'found_count',
        'missing_count',
        'extra_count',
        'payload',
    ];

    protected $casts = [
        'payload' => 'json',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
