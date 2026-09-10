<?php

namespace Tests\Feature;

use App\Models\History;
use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithApi;
use Tests\TestCase;

/**
 * API-032: picked state — POST pick/unpick intent verbs over the nullable
 * `picked_at` timestamp. Same contract as the API-004 verbs: POST-only,
 * touches exactly its own field, one history row per effective change,
 * revision bumps only on real writes.
 */
class ItemPickStateTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithApi;

    /* ------------------------------------------------------------------ */
    /* POST api/item/{item}/pick                                           */
    /* ------------------------------------------------------------------ */

    public function test_pick_sets_picked_at_and_records_history(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->inLocation()->withStatus()->create();
        $revisionBefore = $team->fresh()->revision;

        $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/item/{$item->id}/pick")
            ->assertOk()
            ->assertJsonPath('parent_id', $item->parent_id);

        $item->refresh();
        $this->assertNotNull($item->picked_at, 'pick must set picked_at');
        $this->assertSame(
            $item->updated_at->getTimestamp(),
            $item->picked_at->getTimestamp(),
            'pick must bump updated_at (delta sync piggybacks on it)'
        );

        /* Exactly one history row for the one field that changed */
        $this->assertDatabaseCount('histories', 1);
        $this->assertDatabaseHas('histories', [
            'item_id' => $item->id,
            'user_id' => $user->id,
            'field_name' => 'picked_at',
            'new_value' => $item->picked_at->format('Y-m-d H:i:s'),
        ]);

        /* API-003: the write bumps the team revision exactly once */
        $this->assertSame(
            $revisionBefore + 1,
            $team->fresh()->revision,
            'pick must bump the team revision once'
        );
    }

    public function test_pick_touches_only_picked_at(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->inLocation()->withStatus()->create();

        $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/item/{$item->id}/pick")
            ->assertOk()
            ->assertJsonPath('name', $item->name)
            ->assertJsonPath('status_id', $item->status_id)
            ->assertJsonPath('location_id', $item->location_id);

        $item->refresh();
        $this->assertNull($item->parent_id, 'pick must not touch parent_id');
        $this->assertDatabaseMissing('histories', [
            'item_id' => $item->id,
            'field_name' => 'parent_id',
        ]);
    }

    public function test_repick_refreshes_timestamp_with_a_new_history_row(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();

        $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/item/{$item->id}/pick")->assertOk();
        $firstPick = $item->fresh()->picked_at;

        $this->travel(1)->minutes();
        $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/item/{$item->id}/pick")->assertOk();

        $item->refresh();
        $this->assertTrue(
            $item->picked_at->gt($firstPick),
            're-pick must refresh the timestamp'
        );
        $this->assertDatabaseCount('histories', 2);
    }

    /* ------------------------------------------------------------------ */
    /* POST api/item/{item}/unpick                                         */
    /* ------------------------------------------------------------------ */

    public function test_unpick_clears_picked_at_and_records_history(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();
        $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/item/{$item->id}/pick")->assertOk();
        $pickedAt = $item->fresh()->picked_at;

        $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/item/{$item->id}/unpick")
            ->assertOk();

        $item->refresh();
        $this->assertNull($item->picked_at, 'unpick must clear picked_at');
        $this->assertDatabaseCount('histories', 2);
        $this->assertDatabaseHas('histories', [
            'item_id' => $item->id,
            'field_name' => 'picked_at',
            'old_value' => $pickedAt->format('Y-m-d H:i:s'),
            'new_value' => null,
        ]);
    }

    public function test_unpick_without_being_picked_writes_nothing(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();
        $updatedAt = $item->fresh()->updated_at;
        $revision = $team->fresh()->revision;

        $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/item/{$item->id}/unpick")
            ->assertOk();

        $this->assertDatabaseCount('histories', 0);
        $this->assertSame(
            $updatedAt->toISOString(),
            $item->fresh()->updated_at->toISOString(),
            'A no-op unpick must not bump updated_at'
        );
        $this->assertSame(
            $revision,
            $team->fresh()->revision,
            'A no-op unpick must not bump the team revision'
        );
    }

    /* ------------------------------------------------------------------ */
    /* Payload distribution                                                */
    /* ------------------------------------------------------------------ */

    public function test_picked_at_is_serialized_in_list_and_show(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $picked = Item::factory()->onTeam($team)->create();
        $plain = Item::factory()->onTeam($team)->create();

        $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/item/{$picked->id}/pick")->assertOk();

        $list = $this->actingAsApi($user, ['item:read'])
            ->getJson('/api/item')
            ->assertOk()
            ->json();
        $rows = collect($list)->keyBy('id');
        $this->assertNotNull($rows[$picked->id]['picked_at']);
        $this->assertNull($rows[$plain->id]['picked_at']);

        $show = $this->actingAsApi($user, ['item:read'])
            ->getJson("/api/item/{$picked->id}")
            ->assertOk()
            ->json();
        $this->assertNotNull($show['picked_at']);
    }

    public function test_picked_item_surfaces_in_delta_feed(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();
        $since = now()->subMinutes(5)->toISOString();

        $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/item/{$item->id}/pick")->assertOk();

        $delta = $this->actingAsApi($user, ['item:read'])
            ->getJson("/api/item?since={$since}")
            ->assertOk()
            ->json();
        $changed = collect($delta['changed'])->keyBy('id');
        $this->assertArrayHasKey($item->id, $changed);
        $this->assertNotNull($changed[$item->id]['picked_at']);
        $this->assertSame([], $delta['deleted_ids']);
    }

    /* ------------------------------------------------------------------ */
    /* Authorization + soft-delete scoping                                 */
    /* ------------------------------------------------------------------ */

    public function test_pick_requires_item_write_ability(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();

        $this->actingAsApi($user, ['item:read'])
            ->postJson("/api/item/{$item->id}/pick")
            ->assertStatus(403);

        $this->actingAsApi($user, ['item:read'])
            ->postJson("/api/item/{$item->id}/unpick")
            ->assertStatus(403);
    }

    public function test_pick_rejects_items_from_another_team(): void
    {
        [$user] = $this->newUserWithTeam();
        [$stranger] = $this->newUserWithTeam();
        $foreignTeam = $stranger->ownedTeams()->first();
        $item = Item::factory()->onTeam($foreignTeam)->create();

        $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/item/{$item->id}/pick")
            ->assertStatus(403);

        $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/item/{$item->id}/unpick")
            ->assertStatus(403);
    }

    public function test_pick_on_trashed_item_is_404(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();
        $item->delete();

        $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/item/{$item->id}/pick")
            ->assertStatus(404);

        $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/item/{$item->id}/unpick")
            ->assertStatus(404);
    }

    /* ------------------------------------------------------------------ */
    /* Deletion interplay                                                  */
    /* ------------------------------------------------------------------ */

    public function test_deleting_a_picked_item_still_soft_deletes(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $parent = Item::factory()->onTeam($team)->create();
        $child = Item::factory()->onTeam($team)->childOf($parent)->create();
        $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/item/{$child->id}/pick")->assertOk();

        $this->actingAsApi($user, ['item:write'])
            ->deleteJson("/api/item/{$child->id}")
            ->assertOk();

        $this->assertSoftDeleted('items', ['id' => $child->id]);
    }
}
