<?php

namespace Tests\Concerns;

use App\Models\Team;
use App\Models\User;

/**
 * Shared helpers for exercising the Moovit API in feature tests.
 *
 * Authentication uses real Sanctum personal access tokens (not session
 * actingAs) because several endpoints read `currentAccessToken()->abilities`
 * and `tokenCan()`, which only behave correctly with persisted tokens.
 */
trait InteractsWithApi
{
    /**
     * Create a user owning a personal team, with `current_team_id` pointed at it.
     *
     * @param array $attributes extra user attributes (e.g. ['password' => bcrypt(...)])
     * @return array{0: User, 1: Team}
     */
    protected function newUserWithTeam(array $attributes = []): array
    {
        $user = User::factory()->withPersonalTeam()->create($attributes);
        $team = $user->ownedTeams()->first();
        $user->forceFill(['current_team_id' => $team->id])->save();

        return [$user, $team];
    }

    /**
     * Add an existing user to a team with a Jetstream role and point them at it.
     *
     * Roles are configured in JetstreamServiceProvider: 'admin' (read+write)
     * and 'Read Only' (read-only).
     *
     * @param User $user
     * @param Team $team
     * @param string $role
     * @return User
     */
    protected function addTeamMember(User $user, Team $team, string $role = 'admin'): User
    {
        $team->users()->attach($user->id, ['role' => $role]);
        $user->forceFill(['current_team_id' => $team->id])->save();

        return $user->fresh();
    }

    /**
     * Authenticate the client as a user through a real Sanctum token.
     *
     * @param User $user
     * @param array $abilities token abilities, ['*'] grants everything
     * @return self
     */
    protected function actingAsApi(User $user, array $abilities = ['*']): self
    {
        $token = $user->createToken('test-token', $abilities)->plainTextToken;

        return $this->withToken($token);
    }
}
