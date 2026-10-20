<?php

namespace App\Models;

use App\Support\PrintQueue;
use App\Traits\Uuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * API-039: a workstation that declared a printer to the server. The browser
 * keeps a `client_id` in localStorage (upsert identity alongside the team)
 * and keeps `last_seen_at` fresh by heart-beating through its claim polling —
 * a station is "live" only while that stamp is inside the liveness window.
 *
 * Plumbing row: not revision-observed, no SoftDeletes (cascades with the
 * team) — see the API-039 migration docblock.
 *
 * @property string $id
 * @property string $team_id
 * @property string $client_id
 * @property string $printer_name
 * @property string|null $label
 * @property string|null $template
 * @property \Illuminate\Support\Carbon|null $last_seen_at
 */
class PrintStation extends Model
{
    use HasFactory;
    use Uuids;

    public $fillable = [
        'team_id', 'client_id', 'printer_name', 'label', 'template', 'last_seen_at',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
    ];

    /**
     * Whether the station has heart-beated inside the liveness window
     * (App\Support\PrintQueue::LIVE_WINDOW_SECONDS).
     * @return bool
     */
    public function isLive(): bool
    {
        return $this->last_seen_at !== null
            && $this->last_seen_at->gt(now()->subSeconds(PrintQueue::LIVE_WINDOW_SECONDS));
    }

    /**
     * Team Relation
     * @return BelongsTo
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
