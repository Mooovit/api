<?php

namespace App\Models;

use App\Traits\Uuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Laravel\Jetstream\Events\TeamCreated;
use Laravel\Jetstream\Events\TeamDeleted;
use Laravel\Jetstream\Events\TeamUpdated;
use Laravel\Jetstream\Team as JetstreamTeam;

class Team extends JetstreamTeam
{
    use HasFactory;
    use Uuids;
    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'personal_team' => 'boolean',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'personal_team',
    ];

    /**
     * The event map for the model.
     *
     * @var array
     */
    protected $dispatchesEvents = [
        'created' => TeamCreated::class,
        'updated' => TeamUpdated::class,
        'deleted' => TeamDeleted::class,
    ];

    /**
     * The auto-backup schedule (API-020) — at most one per team.
     *
     * @return HasOne
     */
    public function backupSchedule(): HasOne
    {
        return $this->hasOne(BackupSchedule::class);
    }

    /**
     * The S3 offload credentials (API-021) — at most one per team.
     *
     * @return HasOne
     */
    public function s3Config(): HasOne
    {
        return $this->hasOne(TeamS3Config::class);
    }

    /**
     * API-023: case-insensitive membership check. Jetstream's default
     * compares emails exactly (`where('email', $email)`), which misses
     * Member@X.com vs member@x.com on SQLite and lets duplicate membership
     * slip through case variants.
     *
     * @param  string  $email
     * @return bool
     */
    public function hasUserWithEmail(string $email): bool
    {
        return $this->users()
            ->whereRaw('lower(email) = ?', [strtolower($email)])
            ->exists();
    }
}

