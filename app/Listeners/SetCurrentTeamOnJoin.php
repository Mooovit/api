<?php

namespace App\Listeners;

use App\Models\Team;
use Laravel\Jetstream\Events\TeamMemberAdded;

/**
 * API-023: a member who just joined keeps whatever `current_team_id` they
 * already had — NULL for a fresh account (dashboard and API scoping stay
 * broken until they find the team switcher) or a team they may have left
 * since. This listener repairs a NULL or dangling pointer to the team they
 * just joined. A pointer to a team they still belong to is left alone —
 * switching is the user's own move.
 */
class SetCurrentTeamOnJoin
{
    /**
     * Handle the event.
     *
     * @param  \Laravel\Jetstream\Events\TeamMemberAdded  $event
     * @return void
     */
    public function handle(TeamMemberAdded $event): void
    {
        $member = $event->user;

        $current = $member->current_team_id ? Team::find($member->current_team_id) : null;

        if ($current && $member->belongsToTeam($current)) {
            return;
        }

        $member->forceFill(['current_team_id' => $event->team->id])->save();
    }
}
