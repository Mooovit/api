<?php

namespace App\Support;

use App\Models\Item;
use App\Models\Team;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-team revision counter (API-003).
 *
 * Clients poll `GET api/revision` (or the `X-Revision` header on
 * `GET api/item`) to decide whether anything changed before pulling data.
 * Every team-scoped write must bump the counter exactly once, atomically —
 * a single SQL `increment`, never read-modify-write.
 *
 * API-033: the counter is also the sync cursor key. Writes that change an
 * item's app-representation stamp the touched item rows with the
 * post-increment counter (`sync_revision`), so `GET api/item?since_revision=N`
 * returns an exact delta — no timestamp-precision re-delivery.
 */
class TeamRevision
{
    /**
     * Atomically increment the revision counter of the team the model
     * belongs to. No-op when the model carries no team_id.
     *
     * @param Model $model A team-scoped model (Item, Status, Location, Label…)
     * @return void
     */
    public static function bump(Model $model): void
    {
        if (!$model->team_id) {
            return;
        }

        Team::whereKey($model->team_id)->increment('revision');
    }

    /**
     * API-033 — bump the counter and return the NEW value: the stamp a write
     * puts on the item rows it touched. Writers serialize on the team row
     * (single UPDATE), so the value read right after the increment is the
     * one this write contributed; read it inside the caller's transaction.
     *
     * @param Model $model A team-scoped model (its `team_id` selects the team)
     * @return int|null The post-increment counter, or null when there is no team
     */
    public static function bumpAndGet(Model $model): ?int
    {
        return self::nextForId($model->team_id);
    }

    /**
     * API-033 — `bumpAndGet` by raw team id: for writes that pass through
     * raw increments (mass updates firing no model events, or rows that
     * change teams — transfer), where there is no `team_id` on the model.
     *
     * @param int|string|null $teamId
     * @return int|null The post-increment counter, or null when there is no team
     */
    public static function nextForId($teamId): ?int
    {
        if (!$teamId) {
            return null;
        }

        Team::whereKey($teamId)->increment('revision');

        return (int) Team::whereKey($teamId)->value('revision');
    }

    /**
     * API-033 — bump the team counter once and stamp THIS item row with the
     * post-increment value: the pivot-write flavor (label attach/detach),
     * where the item row itself is untouched but its app-representation
     * changed and the revision-keyed delta must deliver it.
     *
     * @param Model $model The item whose representation changed
     * @return void
     */
    public static function bumpAndStamp(Model $model): void
    {
        $revision = self::bumpAndGet($model);

        if ($revision !== null && $model instanceof Item) {
            Item::whereKey($model->getKey())->update(['sync_revision' => $revision]);
        }
    }
}
