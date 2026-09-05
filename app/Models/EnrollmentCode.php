<?php

namespace App\Models;

use App\Traits\Uuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A one-time, short-lived code that lets an already-authenticated device or
 * user mint a device token on a NEW device (API-017) — the owner's password
 * never touches it. The code is scanned from the web's QR / typed by hand;
 redemption is atomic single-use (`used_at` set by a guarded update) and
 codes expire minutes after minting.
 */
class EnrollmentCode extends Model
{
    use Uuids;

    public $fillable = ['code', 'user_id', 'expires_at', 'used_at', 'used_by_token_id'];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    /**
     * The issuing user — the enrolled token belongs to them and inherits
     * their permissions.
     *
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Whether the code can still be redeemed.
     *
     * @return bool
     */
    public function isRedeemable(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }
}
