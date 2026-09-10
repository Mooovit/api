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
     * Hand-typeable alphabet for the code: 8 chars, no ambiguous glyphs
     * (0/O, 1/I/L) — the code is typed by hand when scanning fails.
     */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /**
     * Mint a fresh code for a user: prune their stale (used or expired)
     * rows first, then create a unique 8-char code valid 15 minutes.
     * Shared by the API controller (API-017) and the web devices page.
     *
     * @param User $user
     * @return self
     */
    public static function mintFor(User $user): self
    {
        self::where('user_id', $user->id)
            ->where(function ($query) {
                $query->where('expires_at', '<=', now())
                    ->orWhereNotNull('used_at');
            })
            ->delete();

        do {
            $code = collect(range(1, 8))
                ->map(fn () => self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)])
                ->implode('');
        } while (self::where('code', $code)->exists());

        return self::create([
            'code' => $code,
            'user_id' => $user->id,
            'expires_at' => now()->addMinutes(15),
        ]);
    }

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
