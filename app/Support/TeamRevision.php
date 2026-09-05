<?php

namespace App\Support;

use App\Models\Team;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-team revision counter (API-003).
 *
 * Clients poll `GET api/revision` (or the `X-Revision` header on
 * `GET api/item`) to decide whether anything changed before pulling data.
 * Every team-scoped write must bump the counter exactly once, atomically —
 * a single SQL `increment`, never read-modify-write.
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
}
