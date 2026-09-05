<?php

namespace Tests\Feature;

use App\Models\History;
use App\Models\Item;
use App\Models\Location;
use App\Models\Status;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithApi;
use Tests\TestCase;

/**
 * API-007: bulk move / bulk assign (POST, per-row results).
 *
 * A bad row never aborts the batch: results come back in request order with
 * `ok: false` + a short error string for the failures. One revision bump per
 * request (mass updates fire no model events), one history row per
 * actually-changed field.
 */
class ItemBulkTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithApi;

    public function test_bulk_move_applies_every_row_and_records_history(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $parent = Item::factory()->onTeam($team)->create();
        $a = Item::factory()->onTeam($team)->create();
        $b = Item::factory()->onTeam($team)->create();
        $revisionBefore = $team->fresh()->revision; /* factory creates bump it */

        $response = $this->actingAsApi($user, ['item:write'])
            ->postJson('/api/item/bulk-move', [
                'ids' => [$a->id, $b->id],
                'parent_id' => $parent->id,
            ])
            ->assertOk()
            ->assertJsonCount(2, 'results');

        /* Results in request order, all ok, with a fresh updated_at */
        $results = $response->json('results');
        $this->assertSame([$a->id, $b->id], array_column($results, 'id'));
        $this->assertTrue(collect($results)->every(fn (array $row) => $row['ok'] === true));
        $this->assertNotNull($results[0]['updated_at']);

        $this->assertSame($parent->id, $a->fresh()->parent_id);
        $this->assertSame($parent->id, $b->fresh()->parent_id);

        /* One history row per item (parent_id changed for both) */
        $this->assertDatabaseCount('histories', 2);
        $this->assertDatabaseHas('histories', [
            'item_id' => $a->id, 'field_name' => 'parent_id', 'new_value' => $parent->id,
        ]);

        /* One revision bump for the whole request (not per item) */
        $this->assertSame($revisionBefore + 1, $team->fresh()->revision);
    }

    public function test_bulk_move_to_root_with_null_parent(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $parent = Item::factory()->onTeam($team)->create();
        $child = Item::factory()->onTeam($team)->childOf($parent)->create();

        $this->actingAsApi($user, ['item:write'])
            ->postJson('/api/item/bulk-move', [
                'ids' => [$child->id],
                'parent_id' => null,
            ])
            ->assertOk()
            ->assertJsonPath('results.0.ok', true);

        $this->assertNull($child->fresh()->parent_id);
        $this->assertDatabaseHas('histories', [
            'item_id' => $child->id,
            'field_name' => 'parent_id',
            'old_value' => $parent->id,
            'new_value' => null,
        ]);
    }

    public function test_bulk_assign_sets_status_and_location_with_two_history_rows_each(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $status = Status::factory()->onTeam($team)->create();
        $location = Location::factory()->onTeam($team)->create();
        $a = Item::factory()->onTeam($team)->create();
        $b = Item::factory()->onTeam($team)->create();
        $revisionBefore = $team->fresh()->revision;

        $this->actingAsApi($user, ['item:write'])
            ->postJson('/api/item/bulk-assign', [
                'ids' => [$a->id, $b->id],
                'status_id' => $status->id,
                'location_id' => $location->id,
            ])
            ->assertOk()
            ->assertJsonCount(2, 'results')
            ->assertJsonPath('results.0.ok', true)
            ->assertJsonPath('results.1.ok', true);

        $this->assertSame($status->id, $a->fresh()->status_id);
        $this->assertSame($location->id, $a->fresh()->location_id);
        $this->assertSame($status->id, $b->fresh()->status_id);

        /* status_id + location_id rows for each of the two items */
        $this->assertSame(4, History::count());
        $this->assertSame($revisionBefore + 1, $team->fresh()->revision);
    }

    public function test_mixed_batch_reports_rows_and_applies_valid_ones(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        [$stranger, $otherTeam] = $this->newUserWithTeam();
        $status = Status::factory()->onTeam($team)->create();
        $location = Location::factory()->onTeam($team)->create();
        $mine = Item::factory()->onTeam($team)->create();
        $foreign = Item::factory()->onTeam($otherTeam)->create();
        $missing = '00000000-0000-0000-0000-000000000000';
        $revisionBefore = $team->fresh()->revision;

        $response = $this->actingAsApi($user, ['item:write'])
            ->postJson('/api/item/bulk-assign', [
                'ids' => [$mine->id, $foreign->id, $missing],
                'status_id' => $status->id,
                'location_id' => $location->id,
            ])
            ->assertOk(); /* 200 with partial failures */

        $results = $response->json('results');
        $this->assertSame([$mine->id, $foreign->id, $missing], array_column($results, 'id'));
        $this->assertTrue($results[0]['ok']);
        $this->assertFalse($results[1]['ok']);
        $this->assertSame('foreign_team', $results[1]['error']);
        $this->assertFalse($results[2]['ok']);
        $this->assertSame('not_found', $results[2]['error']);
        $this->assertArrayNotHasKey('updated_at', $results[2]);

        $this->assertSame($status->id, $mine->fresh()->status_id);
        $this->assertDatabaseCount('histories', 2); /* only my item changed */
        $this->assertSame($revisionBefore + 1, $team->fresh()->revision);
    }

    public function test_parent_among_moved_ids_is_a_batch_level_cycle(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $a = Item::factory()->onTeam($team)->create();
        $b = Item::factory()->onTeam($team)->create();
        $revisionBefore = $team->fresh()->revision;

        $this->actingAsApi($user, ['item:write'])
            ->postJson('/api/item/bulk-move', [
                'ids' => [$a->id, $b->id],
                'parent_id' => $a->id,
            ])
            ->assertOk()
            ->assertJsonPath('results.0.error', 'cycle')
            ->assertJsonPath('results.1.error', 'cycle');

        /* Nothing applied, no history, no revision bump */
        $this->assertNull($a->fresh()->parent_id);
        $this->assertNull($b->fresh()->parent_id);
        $this->assertDatabaseCount('histories', 0);
        $this->assertSame($revisionBefore, $team->fresh()->revision);
    }

    public function test_move_under_descendant_of_a_moved_item_is_a_row_cycle(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $root = Item::factory()->onTeam($team)->create();
        $child = Item::factory()->onTeam($team)->childOf($root)->create();
        $outsider = Item::factory()->onTeam($team)->create();

        $response = $this->actingAsApi($user, ['item:write'])
            ->postJson('/api/item/bulk-move', [
                'ids' => [$root->id, $outsider->id],
                'parent_id' => $child->id, /* $child lives in $root's subtree */
            ])
            ->assertOk();

        $results = $response->json('results');
        $this->assertFalse($results[0]['ok']);
        $this->assertSame('cycle', $results[0]['error']);
        $this->assertTrue($results[1]['ok']); /* unrelated item applies fine */

        $this->assertNull($root->fresh()->parent_id);
        $this->assertSame($child->id, $outsider->fresh()->parent_id);
    }

    public function test_trashed_parent_is_rejected(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $parent = Item::factory()->onTeam($team)->create();
        $item = Item::factory()->onTeam($team)->create();
        $this->actingAsApi($user, ['item:write'])
            ->deleteJson("/api/item/{$parent->id}")
            ->assertOk();

        $this->actingAsApi($user, ['item:write'])
            ->postJson('/api/item/bulk-move', [
                'ids' => [$item->id],
                'parent_id' => $parent->id,
            ])
            ->assertStatus(404);

        $this->assertNull($item->fresh()->parent_id);
    }

    public function test_cross_team_status_and_location_is_422(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        [$stranger, $otherTeam] = $this->newUserWithTeam();
        $status = Status::factory()->onTeam($team)->create();
        $foreignLocation = Location::factory()->onTeam($otherTeam)->create();
        $item = Item::factory()->onTeam($team)->create();

        $this->actingAsApi($user, ['item:write'])
            ->postJson('/api/item/bulk-assign', [
                'ids' => [$item->id],
                'status_id' => $status->id,
                'location_id' => $foreignLocation->id,
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('histories', 0);
    }

    public function test_empty_ids_is_422(): void
    {
        [$user] = $this->newUserWithTeam();

        $this->actingAsApi($user, ['item:write'])
            ->postJson('/api/item/bulk-move', ['ids' => [], 'parent_id' => null])
            ->assertStatus(422);
    }

    public function test_more_than_500_ids_is_422(): void
    {
        [$user] = $this->newUserWithTeam();

        $this->actingAsApi($user, ['item:write'])
            ->postJson('/api/item/bulk-move', [
                'ids' => array_fill(0, 501, '00000000-0000-0000-0000-000000000000'),
                'parent_id' => null,
            ])
            ->assertStatus(422);
    }

    public function test_duplicate_ids_are_422(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();

        $this->actingAsApi($user, ['item:write'])
            ->postJson('/api/item/bulk-move', [
                'ids' => [$item->id, $item->id],
                'parent_id' => null,
            ])
            ->assertStatus(422);
    }

    public function test_requires_item_write_token_ability(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();

        $this->actingAsApi($user, ['item:read'])
            ->postJson('/api/item/bulk-move', ['ids' => [$item->id], 'parent_id' => null])
            ->assertStatus(403);

        $this->actingAsApi($user, ['item:read'])
            ->postJson('/api/item/bulk-assign', [
                'ids' => [$item->id],
                'status_id' => Status::factory()->onTeam($team)->create()->id,
                'location_id' => Location::factory()->onTeam($team)->create()->id,
            ])
            ->assertStatus(403);
    }

    public function test_read_only_member_gets_foreign_team_rows(): void
    {
        [$owner, $team] = $this->newUserWithTeam();
        $viewer = $this->addTeamMember(User::factory()->create(), $team, 'Read Only');
        $item = Item::factory()->onTeam($team)->create();

        $this->actingAsApi($viewer, ['item:write'])
            ->postJson('/api/item/bulk-move', ['ids' => [$item->id], 'parent_id' => null])
            ->assertOk()
            ->assertJsonPath('results.0.ok', false)
            ->assertJsonPath('results.0.error', 'foreign_team');

        $this->assertNull($item->fresh()->parent_id);
    }
}
