<?php

namespace App\Observers;

use App\Support\TeamRevision;
use Illuminate\Database\Eloquent\Model;

/**
 * Bumps the owning team's `revision` counter whenever a team-scoped resource
 * is created, updated or deleted (API-003). See App\Support\TeamRevision.
 *
 * Note: `updated` only fires when attributes actually changed, so no-op
 * writes leave the counter untouched. Pivot writes (item_label attach/detach)
 * fire no model events on a stock pivot class — LabelController bumps
 * explicitly for those.
 */
class BumpsTeamRevisionObserver
{
    /**
     * Handle the model "created" event.
     *
     * @param Model $model
     * @return void
     */
    public function created(Model $model): void
    {
        TeamRevision::bump($model);
    }

    /**
     * Handle the model "updated" event (only fired for real changes).
     *
     * @param Model $model
     * @return void
     */
    public function updated(Model $model): void
    {
        TeamRevision::bump($model);
    }

    /**
     * Handle the model "deleted" event.
     *
     * @param Model $model
     * @return void
     */
    public function deleted(Model $model): void
    {
        TeamRevision::bump($model);
    }
}
