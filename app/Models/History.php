<?php

namespace App\Models;

use App\Traits\Uuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class History extends Model
{
    use HasFactory;
    use Uuids;

    protected $fillable = [
        'item_id',
        'user_id',
        'field_name',
        'old_value',
        'new_value',
        'changed_at'
    ];

    protected $casts = [
        'changed_at' => 'datetime'
    ];

    /**
     * Get the item that was changed
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * Get the user who made the change
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
