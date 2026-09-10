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

    public function test_show_returns_an_own_team_location(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $location = Location::factory()->onTeam($team)->create(['name' => 'Shelf A']);

        $this->actingAsApi($user, ['location:read'])
            ->getJson("/api/location/{$location->id}")
            ->assertOk()
            ->assertJsonPath('name', 'Shelf A')
            ->assertJsonPath('team_id', $team->id)
            ->assertJsonStructure(['id', 'name', 'team_id', 'created_at', 'updated_at']);
    }

    public function test_location_routes_require_authentication(): void
    {
        $this->getJson('/api/location')->assertStatus(401);
        $this->getJson('/api/location/some-id')->assertStatus(401);
    }

    public function test_update_and_destroy_require_write_ability(): void
    {
        [$owner, $team] = $this->newUserWithTeam();
        $viewer = $this->addTeamMember(User::factory()->create(), $team, 'Read Only');
        $location = Location::factory()->onTeam($team)->create(['name' => 'Old']);

        /* A Read-Only member is 403 even with the write ability on the token */
        $this->actingAsApi($viewer, ['location:write'])
            ->patchJson("/api/location/{$location->id}", ['name' => 'New'])
            ->assertStatus(403);
        $this->actingAsApi($viewer, ['location:write'])
            ->deleteJson("/api/location/{$location->id}")
            ->assertStatus(403);

        /* The owner is 403 when the token lacks the write ability */
        $this->actingAsApi($owner, ['location:read'])
            ->patchJson("/api/location/{$location->id}", ['name' => 'New'])
            ->assertStatus(403);
        $this->actingAsApi($owner, ['location:read'])
            ->deleteJson("/api/location/{$location->id}")
            ->assertStatus(403);

        $this->assertSame('Old', $location->fresh()->name);
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

    public function test_destroy_soft_deletes_and_the_index_carries_the_tombstone(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $location = Location::factory()->onTeam($team)->create();

        $this->actingAsApi($user, ['location:write'])
            ->deleteJson("/api/location/{$location->id}")
            ->assertOk()
            ->assertJsonPath('success', 'success');

        /* Tombstone: gone from the default scope, resolvable with it —
           History rows referencing the id keep their meaning */
        $this->assertSoftDeleted('locations', ['id' => $location->id]);

        /* API-027: the catalogue index is trashed-INCLUSIVE so clients can
           still resolve names of deleted locations */
        $rows = $this->actingAsApi($user, ['location:read'])
            ->getJson('/api/location')
            ->assertOk()
            ->assertJsonCount(1)
            ->json();
        $this->assertSame($location->id, $rows[0]['id']);
        $this->assertNotNull($rows[0]['deleted_at']);

        /* A trashed location is not addressable (binding skips it) */
        $this->actingAsApi($user, ['location:read'])
            ->getJson("/api/location/{$location->id}")
            ->assertNotFound();
    }

    public function test_index_flags_live_rows_with_a_null_deleted_at(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $live = Location::factory()->onTeam($team)->create();
        $dead = Location::factory()->onTeam($team)->create();
        $dead->delete();

        $rows = $this->actingAsApi($user, ['location:read'])
            ->getJson('/api/location')
            ->assertOk()
            ->assertJsonCount(2)
            ->json();

        $byId = collect($rows)->keyBy('id');
        $this->assertNull($byId[$live->id]['deleted_at']);
        $this->assertNotNull($byId[$dead->id]['deleted_at']);
    }

    public function test_show_of_a_trashed_location_is_a_plain_404(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $location = Location::factory()->onTeam($team)->create();
        $location->delete();

        /* Clients sync from the index (which carries tombstones); the show
           route binding stays default-scoped */
        $this->actingAsApi($user, ['location:read'])
            ->getJson("/api/location/{$location->id}")
            ->assertNotFound();
    }
}
