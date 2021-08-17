<?php

namespace App\Models;

use App\Traits\Uuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Item extends Model
{
    use HasFactory;
    use Uuids;

    public $fillable = ['name', 'team_id', 'location_id', 'status_id', 'parent_id'];
    /**
     * @var mixed
     */
    public $team;
    /**
     * @var mixed
     */
    public $team_id;

    /**
     * Children Relations
     * @return HasMany
     */
    public function childrens(): HasMany
    {
        return $this->hasMany(Item::class, "parent_id");
    }

    /**
     * Item Relation
     * @return BelongsTo
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Item::class);
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
