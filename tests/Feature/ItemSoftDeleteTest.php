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
 * API-005: soft deletes for items.
 *
 * Deleting an item leaves a tombstone row (`deleted_at`) that every default
 * query excludes, so clients see exactly the behavior hard deletes had —
 * while delta sync (API-006) can later report deleted ids.
 */
class ItemSoftDeleteTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithApi;

    public function test_delete_soft_deletes_and_hides_item(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();

        /* Response shape unchanged from the hard-delete era */
        $this->actingAsApi($user, ['item:write'])
            ->deleteJson("/api/item/{$item->id}")
            ->assertOk()
            ->assertJsonPath('success', 'success');

        /* Gone from the list */
        $this->actingAsApi($user, ['item:read'])
            ->getJson('/api/item')
            ->assertOk()
            ->assertJsonMissing(['id' => $item->id]);

        /* Gone from the single-item endpoint (binding 404s trashed rows) */
        $this->actingAsApi($user, ['item:read'])
            ->getJson("/api/item/{$item->id}")
            ->assertStatus(404);

        /* Tombstone present in the database */
        $trashed = Item::withTrashed()->find($item->id);
        $this->assertNotNull($trashed, 'The row must survive as a tombstone');
        $this->assertNotNull($trashed->deleted_at);
    }

    public function test_histories_of_deleted_item_survive(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();
        History::factory()->forItem($item, $user)->create(['field_name' => 'name']);

        $this->actingAsApi($user, ['item:write'])
            ->deleteJson("/api/item/{$item->id}")
            ->assertOk();

        $this->assertDatabaseCount('histories', 1);
    }

    public function test_history_of_deleted_item_is_404(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();
        $this->actingAsApi($user, ['item:write'])
            ->deleteJson("/api/item/{$item->id}")
            ->assertOk();

        /* Binding on trashed rows 404s — pinned interim behavior */
        $this->actingAsApi($user, ['item:read'])
            ->getJson("/api/item/{$item->id}/history")
            ->assertStatus(404);
    }

    public function test_children_of_deleted_box_keep_their_parent_id(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $parent = Item::factory()->onTeam($team)->create();
        $child = Item::factory()->onTeam($team)->childOf($parent)->create();

        $this->actingAsApi($user, ['item:write'])
            ->deleteJson("/api/item/{$parent->id}")
            ->assertOk();

        /* Interim policy (documented): children keep pointing at the trashed
           parent — API-008 decides the final deletion semantics. */
        $this->assertSame($parent->id, $child->fresh()->parent_id);
    }

    public function test_trashed_parent_is_rejected_on_store(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $parent = Item::factory()->onTeam($team)->create();
        $this->actingAsApi($user, ['item:write'])
            ->deleteJson("/api/item/{$parent->id}")
            ->assertOk();

        $location = Location::factory()->onTeam($team)->create();
        $status = Status::factory()->onTeam($team)->create();

        $this->actingAsApi($user, ['item:write'])
            ->postJson('/api/item', [
                'name' => 'Child of ghost',
                'team_id' => $team->id,
                'location_id' => $location->id,
                'status_id' => $status->id,
                'parent_id' => $parent->id,
            ])
            ->assertStatus(404);
    }

    public function test_trashed_parent_is_rejected_on_move(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $parent = Item::factory()->onTeam($team)->create();
        $item = Item::factory()->onTeam($team)->create();
        $this->actingAsApi($user, ['item:write'])
            ->deleteJson("/api/item/{$parent->id}")
            ->assertOk();

        $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/item/{$item->id}/move", ['parent_id' => $parent->id])
            ->assertStatus(404);

        $item->refresh();
        $this->assertNull($item->parent_id);
    }
}
