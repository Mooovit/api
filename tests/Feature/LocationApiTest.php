<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithApi;
use Tests\TestCase;

class LocationApiTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithApi;

    public function test_index_returns_team_scoped_locations_with_timestamps(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        [$stranger] = $this->newUserWithTeam();

        Location::factory()->onTeam($team)->count(2)->create();
        Location::factory()->onTeam($stranger->ownedTeams()->first())->create();

        $this->actingAsApi($user, ['location:read'])
            ->getJson('/api/location')
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonStructure([['id', 'name', 'team_id', 'created_at', 'updated_at']]);
    }

    public function test_store_creates_location(): void
    {
        [$user, $team] = $this->newUserWithTeam();

        $this->actingAsApi($user, ['location:write'])
            ->postJson('/api/location', ['name' => 'Aisle 12', 'team_id' => $team->id])
            ->assertStatus(201)
            ->assertJsonPath('name', 'Aisle 12');

        $this->assertDatabaseHas('locations', ['name' => 'Aisle 12', 'team_id' => $team->id]);
    }

    public function test_read_only_member_cannot_create_location(): void
    {
        [$owner, $team] = $this->newUserWithTeam();
        $viewer = $this->addTeamMember(User::factory()->create(), $team, 'Read Only');

        $this->actingAsApi($viewer, ['location:write'])
            ->postJson('/api/location', ['name' => 'Aisle 13', 'team_id' => $team->id])
            ->assertStatus(403);
    }

    public function test_show_rejects_locations_from_another_team(): void
    {
        [$user] = $this->newUserWithTeam();
        [$stranger] = $this->newUserWithTeam();
        $foreign = Location::factory()->onTeam($stranger->ownedTeams()->first())->create();

        $this->actingAsApi($user, ['location:read'])
            ->getJson("/api/location/{$foreign->id}")
            ->assertStatus(403);
    }

    public function test_update_requires_name(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $location = Location::factory()->onTeam($team)->create(['name' => 'Old']);

        $this->actingAsApi($user, ['location:write'])
            ->patchJson("/api/location/{$location->id}", ['name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('name', 'Renamed');
    }

    public function test_destroy_returns_success(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $location = Location::factory()->onTeam($team)->create();

        $this->actingAsApi($user, ['location:write'])
            ->deleteJson("/api/location/{$location->id}")
            ->assertOk()
            ->assertJsonPath('success', 'success');

        $this->assertDatabaseMissing('locations', ['id' => $location->id]);
    }
}
