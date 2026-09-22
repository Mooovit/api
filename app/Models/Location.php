<?php

namespace App\Models;

use App\Traits\Uuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Location extends Model
{
    /* API-035: `barcode` is the single optional locator code — serialized
       directly (no API Resource) so it rides index/show/mutation payloads. */
    public $fillable = ['name', 'team_id', 'parent_id', 'barcode'];
    use HasFactory;
    use Uuids;
    use SoftDeletes;

    /**
     * Team Relation
     * @return BelongsTo
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Parent location relation (API-034 sub-locations) — self-referencing
     * `parent_id`, null for root locations.
     * @return BelongsTo
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'parent_id');
    }

    /**
     * Direct child locations (API-034) — one level only, not the whole
     * subtree. Trashed children are hidden by the SoftDeletes scope.
     * @return HasMany
     */
    public function children(): HasMany
    {
        return $this->hasMany(Location::class, 'parent_id');
    }

    /**
     * Items relation
     * @return HasMany
     */
    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }
}
