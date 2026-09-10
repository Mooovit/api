<?php

namespace App\Observers;

use App\Models\Item;
use App\Support\TeamRevision;
use Illuminate\Database\Eloquent\Model;

/**
 * Bumps the owning team's `revision` counter whenever a team-scoped resource
 * is created, updated or deleted (API-003). See App\Support\TeamRevision.
 *
 * API-033: item writes also STAMP the row with the post-increment counter
 * (`items.sync_revision`) so `GET api/item?since_revision=N` can key the
 * delta on the counter instead of `updated_at` precision.
 *
 * Note: `updating` only fires when attributes actually changed, so no-op
 * writes leave the counter untouched. Pivot writes (item_label attach/detach)
 * fire no model events on a stock pivot class — LabelController and the
 * kanban endpoints bump + stamp explicitly for those. Mass updates fire no
 * events either — bulk move/assign, delete-detach and transfer stamp
 * explicitly with TeamRevision::nextForId().
 */
class BumpsTeamRevisionObserver
{
    /**
     * Handle the model "creating" event: bump once and carry the stamp in
     * the same INSERT as the row itself.
     *
     * @param Model $model
     * @return void
     */
    public function creating(Model $model): void
    {
        $this->bumpAndMark($model);
    }

    /**
     * Handle the model "updating" event (only fired for real changes): bump
     * once and carry the stamp in the same UPDATE as the change.
     *
     * @param Model $model
     * @return void
     */
    public function updating(Model $model): void
    {
        $this->bumpAndMark($model);
    }

    /**
     * Handle the model "deleted" event: bump once, then stamp. The stamp
     * cannot ride the delete (a soft-delete UPDATE writes only `deleted_at`),
     * so it is a quiet follow-up query — events stay off it.
     *
     * @param Model $model
     * @return void
     */
    public function deleted(Model $model): void
    {
        $this->stampQuietly($model, TeamRevision::bumpAndGet($model));
    }

    /**
     * Handle the model "restored" event (API-033): an un-deleted item must
     * reappear in the revision delta, so a restore bumps + stamps too (the
     * restore UPDATE only clears `deleted_at` and fires no updating event).
     *
     * @param Model $model
     * @return void
     */
    public function restored(Model $model): void
    {
        $this->stampQuietly($model, TeamRevision::bumpAndGet($model));
    }

    /**
     * Bump once and remember the stamp on the model — for Items the
     * attribute becomes part of the ongoing INSERT/UPDATE.
     *
     * @param Model $model
     * @return void
     */
    private function bumpAndMark(Model $model): void
    {
        $revision = TeamRevision::bumpAndGet($model);

        if ($revision !== null && $model instanceof Item) {
            $model->sync_revision = $revision;
        }
    }

    /**
     * Stamp an already-written Item row (query-builder update: no events,
     * no revision side effects). `withTrashed` — the deleted event fires
     * once the row is ALREADY trashed, invisible to the default scope.
     *
     * @param Model $model
     * @param int|null $revision
     * @return void
     */
    private function stampQuietly(Model $model, ?int $revision): void
    {
        if ($revision !== null && $model instanceof Item) {
            Item::withTrashed()->whereKey($model->getKey())
                ->update(['sync_revision' => $revision]);
        }
    }
}
