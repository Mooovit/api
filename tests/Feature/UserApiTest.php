<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithApi;
use Tests\TestCase;

class UserApiTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithApi;

    public function test_user_endpoint_returns_user_team_permissions_and_token_abilities(): void
    {
        [$user, $team] = $this->newUserWithTeam();

        $this->actingAsApi($user, ['item:read', 'item:write'])
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('tokenPermissions.0', 'item:read')
            ->assertJsonPath("userPermissions.{$team->id}.0", '*');
    }

    public function test_teams_endpoint_lists_owned_teams(): void
    {
        [$user, $team] = $this->newUserWithTeam();

        $this->actingAsApi($user)
            ->getJson('/api/teams')
            ->assertOk()
            ->assertJsonFragment(['id' => $team->id]);
    }

    public function test_user_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/user')->assertStatus(401);
        $this->getJson('/api/teams')->assertStatus(401);
    }
}
