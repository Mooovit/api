<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateTeamTest extends TestCase
{
    use RefreshDatabase;

    public function test_teams_can_be_created()
    {
        $this->actingAs($user = User::factory()->withPersonalTeam()->create());
        $this->assertCount(1, $user->fresh()->ownedTeams);
        $this->assertFalse($user->fresh()->ownedTeams()->where("name", "Test Team")->exists());

        $response = $this->post('/teams', [
            'name' => 'Test Team',
        ]);
        $this->assertCount(2, $user->fresh()->ownedTeams);
        $this->assertTrue($user->fresh()->ownedTeams()->where("name", "Test Team")->exists());
    }
}
