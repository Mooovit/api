<?php

namespace App\Models;

use App\Traits\Uuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tombstone contract (API-005): deleting an item soft-deletes it. Trashed
 * rows disappear from every default query (global scope), so the API and
 * kanban behave exactly as with hard deletes; the row survives with
 * `deleted_at` set so delta sync (API-006) can report deleted ids. Deletion
 * policy (API-008): direct children are re-parented to root, with one
 * history row each, and reported as `detached_ids` by DELETE api/item/:id.
 */
class Item extends Model
{
    use HasFactory;
    use Uuids;
    use SoftDeletes;

    public $fillable = ['name', 'team_id', 'location_id', 'status_id', 'parent_id'];

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


    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the status for this item
     */
    public function status(): BelongsTo
    {
        return $this->belongsTo(Status::class);
    }

    /**
     * Get the location for this item
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * Get the history records for this item
     */
    public function histories(): HasMany
    {
        return $this->hasMany(History::class);
    }

    /**
     * Get the labels for this item
     */
    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(Label::class, 'item_label')
                    ->withTimestamps();
    }
}
