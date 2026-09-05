<?php

namespace Tests\Feature;

use App\Models\History;
use App\Models\Item;
use App\Models\Location;
use App\Models\Status;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithApi;
use Tests\TestCase;

/**
 * API-004: intent verbs (POST move/assign/rename) — anti-clobber writes.
 * Each verb touches exactly its own field(s) and records exactly its own
 * history rows, so concurrent clients can never clobber sibling fields.
 */
class ItemIntentVerbsTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithApi;

    /**
     * Create an item with all four trackable fields populated.
     */
    private function fullItem($team): Item
    {
        return Item::factory()->onTeam($team)
            ->inLocation()->withStatus()
            ->create(['name' => 'Original']);
    }

    /* ------------------------------------------------------------------ */
    /* POST api/item/{item}/move                                           */
    /* ------------------------------------------------------------------ */

    public function test_move_changes_only_parent(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = $this->fullItem($team);
        $newParent = Item::factory()->onTeam($team)->create();

        $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/item/{$item->id}/move", ['parent_id' => $newParent->id])
            ->assertOk()
            ->assertJsonPath('parent_id', $newParent->id)
            ->assertJsonPath('name', 'Original');

        $item->refresh();
        $this->assertSame('Original', $item->name, 'move must not touch name');
        $this->assertNotNull($item->status_id, 'move must not touch status_id');
        $this->assertNotNull($item->location_id, 'move must not touch location_id');

        /* Exactly one history row, for the field that actually changed */
        $this->assertDatabaseCount('histories', 1);
        $this->assertDatabaseHas('histories', [
            'item_id' => $item->id,
            'field_name' => 'parent_id',
            'new_value' => $newParent->id,
        ]);
    }

    public function test_move_to_root_with_null_parent(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $parent = Item::factory()->onTeam($team)->create();
        $child = Item::factory()->onTeam($team)->childOf($parent)->create();

        $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/item/{$child->id}/move", ['parent_id' => null])
            ->assertOk()
            ->assertJsonPath('parent_id', null);

        $this->assertDatabaseCount('histories', 1);
        $this->assertDatabaseHas('histories', [
            'item_id' => $child->id,
            'field_name' => 'parent_id',
            'old_value' => $parent->id,
            'new_value' => null,
        ]);
    }

    public function test_move_rejects_moving_into_own_descendant(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $grandParent = Item::factory()->onTeam($team)->create();
        $parent = Item::factory()->onTeam($team)->childOf($grandParent)->create();
        $child = Item::factory()->onTeam($team)->childOf($parent)->create();

        /* Move the grand-parent under its own grandchild → cycle */
        $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/item/{$grandParent->id}/move", ['parent_id' => $child->id])
            ->assertStatus(422);

        $grandParent->refresh();
        $this->assertNull($grandParent->parent_id, 'The rejected move must not be applied');
        $this->assertDatabaseCount('histories', 0);
    }

    public function test_move_rejects_becoming_its_own_parent(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();

        $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/item/{$item->id}/move", ['parent_id' => $item->id])
            ->assertStatus(422);
    }

    public function test_move_rejects_parent_from_another_team(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        [$stranger] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();
        $foreignParent = Item::factory()->onTeam($stranger->ownedTeams()->first())->create();

        $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/item/{$item->id}/move", ['parent_id' => $foreignParent->id])
            ->assertStatus(404);
    }

    /* ------------------------------------------------------------------ */
    /* POST api/item/{item}/assign                                         */
    /* ------------------------------------------------------------------ */

    public function test_assign_changes_only_status_and_location(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = $this->fullItem($team);
        $newStatus = Status::factory()->onTeam($team)->create();
        $newLocation = Location::factory()->onTeam($team)->create();
        $oldParent = $item->parent_id;

        $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/item/{$item->id}/assign", [
                'status_id' => $newStatus->id,
                'location_id' => $newLocation->id,
            ])
            ->assertOk()
            ->assertJsonPath('status_id', $newStatus->id)
            ->assertJsonPath('location_id', $newLocation->id);

        $item->refresh();
        $this->assertSame('Original', $item->name, 'assign must not touch name');
        $this->assertSame($oldParent, $item->parent_id, 'assign must not touch parent_id');

        /* Exactly two history rows, for the two fields that changed */
        $this->assertDatabaseCount('histories', 2);
        $this->assertDatabaseHas('histories', [
            'item_id' => $item->id,
            'field_name' => 'status_id',
            'new_value' => $newStatus->id,
        ]);
        $this->assertDatabaseHas('histories', [
            'item_id' => $item->id,
            'field_name' => 'location_id',
            'new_value' => $newLocation->id,
        ]);
    }

    public function test_assign_requires_both_fields(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = $this->fullItem($team);
        $newStatus = Status::factory()->onTeam($team)->create();

        $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/item/{$item->id}/assign", ['status_id' => $newStatus->id])
            ->assertStatus(422);
    }

    public function test_assign_rejects_status_from_another_team(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        [$stranger] = $this->newUserWithTeam();
        $item = $this->fullItem($team);
        $foreignStatus = Status::factory()->onTeam($stranger->ownedTeams()->first())->create();
        $ownLocation = Location::factory()->onTeam($team)->create();

        $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/item/{$item->id}/assign", [
                'status_id' => $foreignStatus->id,
                'location_id' => $ownLocation->id,
            ])
            ->assertStatus(404);
    }

    /* ------------------------------------------------------------------ */
    /* POST api/item/{item}/rename                                         */
    /* ------------------------------------------------------------------ */

    public function test_rename_changes_only_name(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = $this->fullItem($team);
        $oldStatus = $item->status_id;
        $oldLocation = $item->location_id;
        $oldParent = $item->parent_id;

        $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/item/{$item->id}/rename", ['name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('name', 'Renamed');

        $item->refresh();
        $this->assertSame($oldStatus, $item->status_id, 'rename must not touch status_id');
        $this->assertSame($oldLocation, $item->location_id, 'rename must not touch location_id');
        $this->assertSame($oldParent, $item->parent_id, 'rename must not touch parent_id');

        $this->assertDatabaseCount('histories', 1);
        $this->assertDatabaseHas('histories', [
            'item_id' => $item->id,
            'field_name' => 'name',
            'old_value' => 'Original',
            'new_value' => 'Renamed',
        ]);
    }

    public function test_rename_to_same_name_writes_no_history_and_touches_nothing(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create(['name' => 'Same']);
        $updatedAt = $item->fresh()->updated_at;

        $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/item/{$item->id}/rename", ['name' => 'Same'])
            ->assertOk()
            ->assertJsonPath('name', 'Same');

        $this->assertDatabaseCount('histories', 0);
        $this->assertSame(
            $updatedAt->toISOString(),
            $item->fresh()->updated_at->toISOString(),
            'A no-op rename must not bump updated_at'
        );
    }

    public function test_rename_requires_a_name(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();

        $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/item/{$item->id}/rename", [])
            ->assertStatus(422);
    }

    /* ------------------------------------------------------------------ */
    /* Authorization matrix                                                */
    /* ------------------------------------------------------------------ */

    public function test_verbs_require_item_write_ability(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = $this->fullItem($team);
        $other = Item::factory()->onTeam($team)->create();

        $this->actingAsApi($user, ['item:read'])
            ->postJson("/api/item/{$item->id}/move", ['parent_id' => null])
            ->assertStatus(403);

        $this->actingAsApi($user, ['item:read'])
            ->postJson("/api/item/{$item->id}/assign", [
                'status_id' => $item->status_id,
                'location_id' => $item->location_id,
            ])
            ->assertStatus(403);

        $this->actingAsApi($user, ['item:read'])
            ->postJson("/api/item/{$other->id}/rename", ['name' => 'X'])
            ->assertStatus(403);
    }

    public function test_verbs_reject_items_from_another_team(): void
    {
        [$user] = $this->newUserWithTeam();
        [$stranger] = $this->newUserWithTeam();
        $foreignTeam = $stranger->ownedTeams()->first();
        $item = Item::factory()->onTeam($foreignTeam)->create();

        $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/item/{$item->id}/move", ['parent_id' => null])
            ->assertStatus(403);

        $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/item/{$item->id}/assign", [
                'status_id' => 'x',
                'location_id' => 'y',
            ])
            ->assertStatus(403);

        $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/item/{$item->id}/rename", ['name' => 'X'])
            ->assertStatus(403);
    }

    /* ------------------------------------------------------------------ */
    /* Legacy update() stays sparse (documented via tests)                 */
    /* ------------------------------------------------------------------ */

    public function test_plain_update_is_still_sparse(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = $this->fullItem($team);
        $newStatus = Status::factory()->onTeam($team)->create();

        $this->actingAsApi($user, ['item:write'])
            ->patchJson("/api/item/{$item->id}", ['status_id' => $newStatus->id])
            ->assertOk();

        $item->refresh();
        $this->assertSame('Original', $item->name);
        $this->assertNotNull($item->location_id);
        $this->assertDatabaseCount('histories', 1);
        $this->assertDatabaseHas('histories', ['field_name' => 'status_id']);
    }
}
