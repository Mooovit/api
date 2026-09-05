<?php

namespace App\Models;

use App\Traits\Uuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * An auto-backup schedule (API-020): one per team, configured from the
 * server UI (web), executed by the `moovit:auto-backups` scheduler command.
 * Created backups are ordinary API-018 savepoints attributed to the
 * schedule's `user_id` (the configurator at creation time — provenance,
 * not an ACL).
 */
class BackupSchedule extends Model
{
    use Uuids;

    public const FREQUENCIES = ['daily', 'weekly', 'monthly', 'yearly'];

    public $fillable = [
        'team_id',
        'user_id',
        'frequency',
        'enabled',
        'last_run_at',
        'next_run_at',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'last_run_at' => 'datetime',
        'next_run_at' => 'datetime',
    ];

    /**
     * The scheduled team.
     *
     * @return BelongsTo
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * The user who configured the schedule (created or last saved it).
     *
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The next run time for a frequency, computed from $from (now at
     * configuration/run time — never from a fixed anchor). Month/year edge
     * days follow Carbon's default addMonth()/addYear() overflow semantics
     * (Jan 31 monthly → Mar 3, not Feb 28) — pinned by tests.
     *
     * @param string $frequency
     * @param Carbon|null $from
     * @return Carbon
     */
    public static function nextRunFor(string $frequency, ?Carbon $from = null): Carbon
    {
        $from = $from ?: Carbon::now();

        switch ($frequency) {
            case 'daily':
                return $from->copy()->addDay();
            case 'weekly':
                return $from->copy()->addWeek();
            case 'monthly':
                return $from->copy()->addMonth();
            case 'yearly':
                return $from->copy()->addYear();
        }

        throw new InvalidArgumentException("Unknown frequency [{$frequency}].");
    }

    /**
     * Advance this schedule after a run: stamp `last_run_at` and compute
     * `next_run_at` from the run time.
     *
     * @param Carbon|null $now
     * @return void
     */
    public function advance(?Carbon $now = null): void
    {
        $now = $now ?: Carbon::now();

        $this->forceFill([
            'last_run_at' => $now,
            'next_run_at' => static::nextRunFor($this->frequency, $now),
        ])->save();
    }
}
