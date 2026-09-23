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
     * Ancestor-path display map for a team (API-036): id =>
     * "Garage > Black shelf" (roots map to their own name). Built from a
     * SINGLE trashed-inclusive query — the parent chain is walked in PHP,
     * so boards/details/selects resolve every path without N+1.
     *
     * Semantics: a trashed ancestor's name still renders (API-027
     * tombstone philosophy); a parent id missing from the team's rows
     * (dangling) stops the walk at the deepest resolvable ancestor; a
     * corrupt cycle terminates via a visited set and a hard depth cap.
     *
     * Display-only — never serialized: the mobile API contract (api/location,
     * api/item) stays untouched. Cache is per-PHP-request; clearPathsCache()
     * exists for tests that mutate locations after a first resolution.
     */
    private static array $pathsCache = [];

    public static function pathsForTeam(string $teamId): array
    {
        if (isset(self::$pathsCache[$teamId])) {
            return self::$pathsCache[$teamId];
        }

        $rows = self::query()
            ->where('team_id', $teamId)
            ->withTrashed()
            ->get(['id', 'name', 'parent_id'])
            ->keyBy('id');

        $paths = [];
        foreach ($rows as $row) {
            $segments = [];
            $visited = [];
            $current = $row;

            while (true) {
                array_unshift($segments, $current->name);
                $visited[$current->id] = true;

                if ($current->parent_id === null
                    || isset($visited[$current->parent_id])
                    || count($visited) >= 20) {
                    break;
                }

                $parent = $rows->get($current->parent_id);
                if ($parent === null) {
                    break;
                }
                $current = $parent;
            }

            $paths[$row->id] = implode(' > ', $segments);
        }

        return self::$pathsCache[$teamId] = $paths;
    }

    /**
     * Drop the pathsForTeam() memoization (test helper).
     */
    public static function clearPathsCache(): void
    {
        self::$pathsCache = [];
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
