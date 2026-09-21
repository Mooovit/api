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

    /* API-034 — sub-locations (nullable parent_id) */

    public function test_store_creates_location_with_a_parent_and_roots_stay_null(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $garage = Location::factory()->onTeam($team)->create(['name' => 'Garage']);

        $child = $this->actingAsApi($user, ['location:write'])
            ->postJson('/api/location', [
                'name' => 'Black shelf',
                'team_id' => $team->id,
                'parent_id' => $garage->id,
            ])
            ->assertStatus(201)
            ->assertJsonPath('name', 'Black shelf')
            ->assertJsonPath('parent_id', $garage->id)
            ->json();

        /* The index carries parent_id; root rows keep null */
        $rows = $this->actingAsApi($user, ['location:read'])
            ->getJson('/api/location')
            ->assertOk()
            ->json();
        $byId = collect($rows)->keyBy('id');
        $this->assertSame($garage->id, $byId[$child['id']]['parent_id']);
        $this->assertNull($byId[$garage->id]['parent_id']);
    }

    public function test_update_reparents_unparents_and_rejects_cycles(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $a = Location::factory()->onTeam($team)->create(['name' => 'Garage']);
        $b = Location::factory()->onTeam($team)->create(['name' => 'Black shelf', 'parent_id' => $a->id]);
        $c = Location::factory()->onTeam($team)->create(['name' => 'Drawer', 'parent_id' => $b->id]);

        /* Moving a under its own grandchild c is a cycle → 422 */
        $this->actingAsApi($user, ['location:write'])
            ->patchJson("/api/location/{$a->id}", ['name' => 'Garage', 'parent_id' => $c->id])
            ->assertStatus(422);

        /* Self-parenting is a cycle too → 422 (as is moving under the
           direct child b — chain a → b → c means every subtree member is
           an invalid target for a) */
        $this->actingAsApi($user, ['location:write'])
            ->patchJson("/api/location/{$a->id}", ['name' => 'Garage', 'parent_id' => $a->id])
            ->assertStatus(422);
        $this->actingAsApi($user, ['location:write'])
            ->patchJson("/api/location/{$a->id}", ['name' => 'Garage', 'parent_id' => $b->id])
            ->assertStatus(422);

        /* A plain re-parent on the legacy resource PATCH (Android contract):
           move a under an unrelated root */
        $d = Location::factory()->onTeam($team)->create(['name' => 'Basement']);
        $this->actingAsApi($user, ['location:write'])
            ->patchJson("/api/location/{$a->id}", ['name' => 'Garage', 'parent_id' => $d->id])
            ->assertOk()
            ->assertJsonPath('parent_id', $d->id);
        $this->assertSame($d->id, $a->fresh()->parent_id);

        /* Re-parenting to a root (parent_id: null) is always allowed */
        $this->actingAsApi($user, ['location:write'])
            ->patchJson("/api/location/{$b->id}", ['name' => 'Black shelf', 'parent_id' => null])
            ->assertOk()
            ->assertJsonPath('parent_id', null);
        $this->assertNull($b->fresh()->parent_id);
    }

    public function test_update_without_parent_id_leaves_the_hierarchy_alone(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $parent = Location::factory()->onTeam($team)->create();
        $child = Location::factory()->onTeam($team)->create(['parent_id' => $parent->id]);

        $this->actingAsApi($user, ['location:write'])
            ->patchJson("/api/location/{$child->id}", ['name' => 'Renamed'])
            ->assertOk();

        $this->assertSame($parent->id, $child->fresh()->parent_id);
    }

    public function test_parent_must_be_same_team_and_live(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        [$stranger] = $this->newUserWithTeam();
        $foreign = Location::factory()->onTeam($stranger->ownedTeams()->first())->create(['name' => 'Secret']);

        $trashed = Location::factory()->onTeam($team)->create();
        $trashed->delete();

        $own = Location::factory()->onTeam($team)->create();

        /* store */
        $this->actingAsApi($user, ['location:write'])
            ->postJson('/api/location', ['name' => 'X', 'team_id' => $team->id, 'parent_id' => $foreign->id])
            ->assertStatus(422);
        $this->actingAsApi($user, ['location:write'])
            ->postJson('/api/location', ['name' => 'X', 'team_id' => $team->id, 'parent_id' => $trashed->id])
            ->assertStatus(422);

        /* update — a foreign-team or trashed parent is a plain 422 and the
           generic message leaks nothing about the foreign row */
        $response = $this->actingAsApi($user, ['location:write'])
            ->patchJson("/api/location/{$own->id}", ['name' => 'X', 'parent_id' => $foreign->id])
            ->assertStatus(422);
        $this->assertStringNotContainsString('Secret', $response->getContent());
        $this->actingAsApi($user, ['location:write'])
            ->patchJson("/api/location/{$own->id}", ['name' => 'X', 'parent_id' => $trashed->id])
            ->assertStatus(422);

        $this->assertNull($own->fresh()->parent_id);
    }

    public function test_destroy_reparents_direct_children_to_root(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $parent = Location::factory()->onTeam($team)->create(['name' => 'Garage']);
        $child = Location::factory()->onTeam($team)->create(['name' => 'Black shelf', 'parent_id' => $parent->id]);
        $grandchild = Location::factory()->onTeam($team)->create(['name' => 'Drawer', 'parent_id' => $child->id]);

        $response = $this->actingAsApi($user, ['location:write'])
            ->deleteJson("/api/location/{$parent->id}")
            ->assertOk()
            ->assertJsonPath('success', 'success');

        /* The direct children are reported (grandchildren untouched) */
        $this->assertSame([$child->id], $response->json('detached_ids'));

        /* The trashed-inclusive index (API-027) still carries the tombstone */
        $rows = $this->actingAsApi($user, ['location:read'])
            ->getJson('/api/location')
            ->assertOk()
            ->assertJsonCount(3)
            ->json();
        $byId = collect($rows)->keyBy('id');
        $this->assertNotNull($byId[$parent->id]['deleted_at']);

        /* The child survived as a live root; the grandchild kept its parent */
        $this->assertNull($byId[$child->id]['deleted_at']);
        $this->assertNull($byId[$child->id]['parent_id']);
        $this->assertNull($byId[$grandchild->id]['deleted_at']);
        $this->assertSame($child->id, $byId[$grandchild->id]['parent_id']);
    }

    public function test_revision_moves_on_create_with_parent_and_delete_with_reparent(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $revision = function () use ($user) {
            return (int) $this->actingAsApi($user, ['location:read'])
                ->getJson('/api/revision')
                ->assertOk()
                ->json('revision');
        };
        $before = $revision();

        $garage = $this->actingAsApi($user, ['location:write'])
            ->postJson('/api/location', ['name' => 'Garage', 'team_id' => $team->id])
            ->assertStatus(201)
            ->json();
        $this->assertSame($before + 1, $revision(), 'location store must bump once');

        $this->actingAsApi($user, ['location:write'])
            ->postJson('/api/location', [
                'name' => 'Black shelf',
                'team_id' => $team->id,
                'parent_id' => $garage['id'],
            ])
            ->assertStatus(201);
        $this->assertSame($before + 2, $revision(), 'store with a parent must bump once');

        /* Deleting a parent bumps once, from the trashed row's own deleted
           event — the children detach is a mass update without events */
        $this->actingAsApi($user, ['location:write'])
            ->deleteJson("/api/location/{$garage['id']}")
            ->assertOk();
        $this->assertSame($before + 3, $revision(), 'delete-with-reparent must bump once');
    }
}
