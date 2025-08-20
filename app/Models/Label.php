<?php

namespace App\Models;

use App\Traits\Uuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Label extends Model
{
    use HasFactory;
    use Uuids;

    protected $fillable = ['name', 'color', 'team_id'];

    /**
     * Get the team that owns the label
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the items that have this label
     */
    public function items(): BelongsToMany
    {
        return $this->belongsToMany(Item::class, 'item_label')
                    ->withTimestamps();
    }
}