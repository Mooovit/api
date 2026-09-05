<?php

namespace App\Models;

use App\Traits\Uuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-team S3 offload credentials (API-021) — one per team. The secret is
 * encrypted at rest (`encrypted` cast, Crypt) and never serialized
 * (`$hidden` + explicit payload shapes in the controller). `prefix` falls
 * back to `backups/{team_id}` when not stored.
 */
class TeamS3Config extends Model
{
    use Uuids;

    public $fillable = [
        'team_id',
        'bucket',
        'region',
        'access_key',
        'secret_key',
        'endpoint',
        'prefix',
    ];

    protected $hidden = [
        'secret_key',
    ];

    protected $casts = [
        'secret_key' => 'encrypted',
    ];

    /**
     * The configured team.
     *
     * @return BelongsTo
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * The stored prefix, or the team default.
     *
     * @param string|null $value
     * @return string
     */
    public function getPrefixAttribute(?string $value): string
    {
        return $value ?: "backups/{$this->team_id}";
    }
}
