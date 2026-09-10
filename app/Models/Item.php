<?php

namespace App\Models;

use App\Traits\Uuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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

    public $fillable = ['name', 'team_id', 'location_id', 'status_id', 'parent_id', 'picked_at'];

    /**
     * API-033: the sync stamp (the team revision counter value this row was
     * last written with) is server-internal — the delta cursor is the team
     * counter, clients never need the per-row value, and the resource
     * whitelist must not grow. Hidden from every serialization.
     */
    protected $hidden = ['sync_revision'];

    protected $casts = [
        /* API-032: temporary out-of-box mark — null = in box. The datetime
           cast gives ISO-8601 serialization in every item payload. */
        'picked_at' => 'datetime',
    ];

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

    /**
     * Barcode registry entries bound to this item (API-011). Payloads:
     * `show()` lists them; registry writes don't touch this row's
     * `updated_at` (pivot-like, like labels).
     */
    public function barcodes(): HasMany
    {
        return $this->hasMany(ItemBarcode::class);
    }

    /**
     * Image attachments (API-013). Serialized only as `Attachment::metadata()`
     * — the raw rows carry the storage path, which never leaves the server.
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    /**
     * The public share link (API-024) — at most one row per item; resolves
     * on `/share/{token}` while it is active.
     *
     * @return HasOne
     */
    public function shareLink(): HasOne
    {
        return $this->hasOne(ItemShareLink::class);
    }

    /**
     * The ROOT box of this item's containment chain: walk up `parent_id`
     * until an item without a parent (a root box). A root returns itself.
     *
     * Guards: a parent that cannot be loaded (trashed box — the soft-delete
     * global scope hides it — or a dangling id) stops the walk at the item
     * itself; a corrupt cycle (a -> b -> a) stops when the next id was
     * already visited, so the walk always terminates.
     *
     * @return static
     */
    public function rootAncestor(): static
    {
        $root = $this;
        $visited = [$this->getKey() => true];

        while ($root->parent_id !== null && !isset($visited[$root->parent_id])) {
            $visited[$root->parent_id] = true;

            $parent = $root->parent;
            if ($parent === null) {
                break;
            }
            $root = $parent;
        }

        return $root;
    }

    /**
     * Effective status (model-level resolution): an item inside a box
     * presents its ROOT box's status — resolved recursively to the item
     * without `parent_id`. A root uses its own status; no value falls back
     * down the chain (a rootless status stays null even if the leaf has
     * one). Access as `$item->effective_status`.
     */
    public function getEffectiveStatusAttribute(): ?Status
    {
        return $this->rootAncestor()->status;
    }

    /**
     * Effective location — same resolution as `effective_status`: the ROOT
     * box's location. Access as `$item->effective_location`.
     */
    public function getEffectiveLocationAttribute(): ?Location
    {
        return $this->rootAncestor()->location;
    }
}
