<?php

namespace App\Models;

use App\Traits\Uuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The public share link of one item (API-024). The token is a random
 * capability (NOT the item uuid) carried by `/share/{token}`; the link is
 * active while `deactivated_at` is NULL. Deactivation keeps the row, and
 * re-activation issues a fresh token — revoked URLs stay dead.
 */
class ItemShareLink extends Model
{
    use HasFactory;
    use Uuids;

    public $fillable = ['item_id', 'team_id', 'token', 'activated_at', 'deactivated_at'];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'activated_at' => 'datetime',
        'deactivated_at' => 'datetime',
    ];

    /**
     * The shared item.
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
     * Whether the link currently resolves on the public page.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->deactivated_at === null;
    }

    /**
     * Restrict to links that currently resolve.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->whereNull('deactivated_at');
    }

    /**
     * A fresh random capability token (≥32 chars, not derivable from ids).
     *
     * @return string
     */
    public static function freshToken(): string
    {
        return \Illuminate\Support\Str::random(64);
    }
}
