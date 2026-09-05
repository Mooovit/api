<?php

namespace Tests\Feature;

use App\Models\History;
use App\Models\Item;
use App\Models\ItemBarcode;
use App\Models\Label;
use App\Models\Location;
use App\Models\Status;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithApi;
use Tests\TestCase;

/**
 * API-016: cross-team item transfer — the item's whole subtree moves with
 * it, with permission checks on both teams, history + revision bookkeeping
 * on both sides, label/parent detachment, per-team barcode registry
 * collision refusal, delta coherence.
 */
class ItemTransferTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithApi;

    public function test_happy_path_moves_item_with_destination_location_and_status(): void
    {
        [$user, $teamA] = $this->newUserWithTeam();
        [$other, $teamB] = $this->newUserWithTeam();
        $this->addTeamMember($user, $teamB);

        $locationA = Location::factory()->onTeam($teamA)->create();
        $statusA = Status::factory()->onTeam($teamA)->create();
        $parent = Item::factory()->onTeam($teamA)->create();
        $item = Item::factory()
            ->onTeam($teamA)
            ->inLocation($locationA)
            ->withStatus($statusA)
            ->childOf($parent)
            ->create();
        $child = Item::factory()->onTeam($teamA)->childOf($item)->create();

        $locationB = Location::factory()->onTeam($teamB)->create();
        $statusB = Status::factory()->onTeam($teamB)->create();

        $revisionA = $teamA->fresh()->revision;
        $revisionB = $teamB->fresh()->revision;

        $response = $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/item/{$item->id}/transfer", [
                'team_id' => $teamB->id,
                'location_id' => $locationB->id,
                'status_id' => $statusB->id,
            ])
            ->assertOk();

        /* Fresh root in the show() shape + additive transfer metadata */
        $this->assertSame($teamB->id, $response->json('team_id'));
        $this->assertSame($locationB->id, $response->json('location_id'));
        $this->assertSame($statusB->id, $response->json('status_id'));
        $this->assertNull($response->json('parent_id'));
        $this->assertTrue($response->json('detached_parent'));
        $this->assertSame([], $response->json('detached_label_ids'));

        $fresh = $item->fresh();
        $this->assertSame($teamB->id, $fresh->team_id);
        $this->assertNull($fresh->parent_id);

        /* The subtree came along: the child changed team but keeps its
           parent link into the root */
        $freshChild = $child->fresh();
        $this->assertSame($teamB->id, $freshChild->team_id);
        $this->assertSame($item->id, $freshChild->parent_id);

        /* One history row per changed field per item: team_id on root and
           child, plus the root's parent detach + location/status move */
        $fieldNames = History::where('item_id', $item->id)->pluck('field_name')->all();
        sort($fieldNames);
        $this->assertSame(
            ['location_id', 'parent_id', 'status_id', 'team_id'],
            $fieldNames
        );
        $this->assertSame(1, History::where('item_id', $child->id)->count());
        $this->assertDatabaseHas('histories', [
            'item_id' => $item->id,
            'user_id' => $user->id,
            'field_name' => 'team_id',
            'old_value' => $teamA->id,
            'new_value' => $teamB->id,
        ]);
        $parentRow = History::where('item_id', $item->id)->where('field_name', 'parent_id')->first();
        $this->assertNotNull($parentRow);
        $this->assertSame($parent->id, $parentRow->old_value);
        $this->assertNull($parentRow->new_value);
        $this->assertDatabaseHas('histories', [
            'item_id' => $item->id,
            'field_name' => 'location_id',
            'old_value' => $locationA->id,
            'new_value' => $locationB->id,
        ]);
        $this->assertDatabaseHas('histories', [
            'item_id' => $item->id,
            'field_name' => 'status_id',
            'old_value' => $statusA->id,
            'new_value' => $statusB->id,
        ]);
        $this->assertDatabaseHas('histories', [
            'item_id' => $child->id,
            'field_name' => 'team_id',
            'old_value' => $teamA->id,
            'new_value' => $teamB->id,
        ]);

        /* Both teams' revisions moved exactly once (API-003) */
        $this->assertSame($revisionA + 1, $teamA->fresh()->revision);
        $this->assertSame($revisionB + 1, $teamB->fresh()->revision);
    }

    public function test_whole_subtree_moves_with_all_its_possessions(): void
    {
        [$user, $teamA] = $this->newUserWithTeam();
        [$other, $teamB] = $this->newUserWithTeam();
        $this->addTeamMember($user, $teamB);

        $root = Item::factory()->onTeam($teamA)->create();
        $child = Item::factory()->onTeam($teamA)->childOf($root)->create();
        $grandchild = Item::factory()->onTeam($teamA)->childOf($child)->create();
        /* A trashed descendant is a tombstone — it stays behind */
        $trashed = Item::factory()->onTeam($teamA)->childOf($root)->create();
        $trashed->delete();

        $labelA = Label::factory()->onTeam($teamA)->create();
        $labelB = Label::factory()->onTeam($teamB)->create();
        $child->labels()->attach([$labelA->id, $labelB->id]);
        ItemBarcode::create(['item_id' => $grandchild->id, 'team_id' => $teamA->id, 'code' => 'KID-1']);

        $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/item/{$root->id}/transfer", ['team_id' => $teamB->id])
            ->assertOk()
            ->assertJsonPath('detached_label_ids', [$labelA->id]);

        /* Every living descendant landed in team B, links preserved */
        $this->assertSame($teamB->id, $root->fresh()->team_id);
        $this->assertSame($teamB->id, $child->fresh()->team_id);
        $this->assertSame($teamB->id, $grandchild->fresh()->team_id);
        $this->assertSame($root->id, $child->fresh()->parent_id);
        $this->assertSame($child->id, $grandchild->fresh()->parent_id);

        /* The child keeps its destination label (no auto-attach, no
           destination-label detach) */
        $this->assertSame(
            [$labelB->id],
            $child->fresh()->labels()->pluck('labels.id')->all()
        );

        /* The grandchild's registry reservation moved with it */
        $this->assertDatabaseHas('item_barcodes', [
            'item_id' => $grandchild->id,
            'code' => 'KID-1',
            'team_id' => $teamB->id,
        ]);

        /* The trashed descendant stayed in team A untouched */
        $this->assertSame($teamA->id, Item::withTrashed()->find($trashed->id)->team_id);

        /* One team_id history row per transferred item (3) */
        $this->assertSame(3, History::where('field_name', 'team_id')->count());
    }

    public function test_permission_matrix_403(): void
    {
        [$owner, $teamA] = $this->newUserWithTeam();
        [$stranger, $teamB] = $this->newUserWithTeam();
        [$outsider, $teamC] = $this->newUserWithTeam();

        $item = Item::factory()->onTeam($teamA)->create();

        /* Not a member of the source team at all (destination is the
           stranger's own team — irrelevant, the source check fires first) */
        $this->actingAsApi($stranger, ['item:write'])
            ->postJson("/api/item/{$item->id}/transfer", ['team_id' => $teamB->id])
            ->assertStatus(403);

        /* Source permission ok, but not a member of the destination team
           (team C belongs to the outsider) */
        $this->addTeamMember($stranger, $teamA);
        $this->actingAsApi($stranger, ['item:write'])
            ->postJson("/api/item/{$item->id}/transfer", ['team_id' => $teamC->id])
            ->assertStatus(403);

        /* Read-only membership on the destination team */
        $this->addTeamMember($owner, $teamB, 'Read Only');
        $this->actingAsApi($owner, ['item:write'])
            ->postJson("/api/item/{$item->id}/transfer", ['team_id' => $teamB->id])
            ->assertStatus(403);

        /* Token without item:write, even with full team permissions */
        $teamB->users()->updateExistingPivot($owner->id, ['role' => 'admin']);
        $this->actingAsApi($owner, ['item:read'])
            ->postJson("/api/item/{$item->id}/transfer", ['team_id' => $teamB->id])
            ->assertStatus(403);

        /* Nothing changed */
        $this->assertSame($teamA->id, $item->fresh()->team_id);
    }

    public function test_validation_matrix_422(): void
    {
        [$user, $teamA] = $this->newUserWithTeam();
        [$other, $teamB] = $this->newUserWithTeam();
        [$third, $teamC] = $this->newUserWithTeam();
        $this->addTeamMember($user, $teamB);

        $locationA = Location::factory()->onTeam($teamA)->create();
        $statusA = Status::factory()->onTeam($teamA)->create();
        $item = Item::factory()
            ->onTeam($teamA)
            ->inLocation($locationA)
            ->withStatus($statusA)
            ->create();

        /* Unknown team */
        $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/item/{$item->id}/transfer", [
                'team_id' => '00000000-0000-0000-0000-000000000000',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('team_id');

        /* Self-transfer */
        $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/item/{$item->id}/transfer", ['team_id' => $teamA->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('team_id');

        /* Source-team location supplied as the destination location */
        $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/item/{$item->id}/transfer", [
                'team_id' => $teamB->id,
                'location_id' => $locationA->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('location_id');

        /* Status of a third team (exists, but not the destination's) */
        $statusC = Status::factory()->onTeam($teamC)->create();
        $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/item/{$item->id}/transfer", [
                'team_id' => $teamB->id,
                'status_id' => $statusC->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status_id');

        $this->assertSame($teamA->id, $item->fresh()->team_id);
    }

    public function test_destination_code_collision_on_subtree_409_and_nothing_changed(): void
    {
        [$user, $teamA] = $this->newUserWithTeam();
        [$other, $teamB] = $this->newUserWithTeam();
        $this->addTeamMember($user, $teamB);

        $parent = Item::factory()->onTeam($teamA)->create();
        $item = Item::factory()->onTeam($teamA)->childOf($parent)->create();
        $child = Item::factory()->onTeam($teamA)->childOf($item)->create();
        /* The clashing code sits on a CHILD of the moved subtree */
        ItemBarcode::create(['item_id' => $child->id, 'team_id' => $teamA->id, 'code' => 'CLASH-1']);

        $holder = Item::factory()->onTeam($teamB)->create();
        ItemBarcode::create(['item_id' => $holder->id, 'team_id' => $teamB->id, 'code' => 'CLASH-1']);

        $revisionA = $teamA->fresh()->revision;
        $revisionB = $teamB->fresh()->revision;

        $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/item/{$item->id}/transfer", ['team_id' => $teamB->id])
            ->assertStatus(409);

        /* The whole transfer rolled back */
        $this->assertSame($teamA->id, $item->fresh()->team_id);
        $this->assertSame($parent->id, $item->fresh()->parent_id);
        $this->assertSame($teamA->id, $child->fresh()->team_id);
        $this->assertDatabaseHas('item_barcodes', [
            'item_id' => $child->id,
            'code' => 'CLASH-1',
            'team_id' => $teamA->id,
        ]);
        $this->assertSame(0, History::count());
        $this->assertSame($revisionA, $teamA->fresh()->revision);
        $this->assertSame($revisionB, $teamB->fresh()->revision);
    }

    public function test_transfer_to_root_item_reports_no_parent_detach(): void
    {
        [$user, $teamA] = $this->newUserWithTeam();
        [$other, $teamB] = $this->newUserWithTeam();
        $this->addTeamMember($user, $teamB);

        $item = Item::factory()->onTeam($teamA)->create();

        $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/item/{$item->id}/transfer", ['team_id' => $teamB->id])
            ->assertOk()
            ->assertJsonPath('detached_parent', false);

        /* Only the team_id history row */
        $this->assertSame(['team_id'], History::where('item_id', $item->id)->pluck('field_name')->all());
    }

    public function test_destination_delta_sees_the_subtree_and_source_revision_moved(): void
    {
        [$user, $teamA] = $this->newUserWithTeam();
        [$other, $teamB] = $this->newUserWithTeam();
        /* Membership WITHOUT re-pointing current_team_id: the transfer is
           resource-addressed, and $user's default stays team A for reads */
        $teamB->users()->attach($user->id, ['role' => 'admin']);

        $root = Item::factory()->onTeam($teamA)->create();
        $child = Item::factory()->onTeam($teamA)->childOf($root)->create();

        $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/item/{$root->id}/transfer", ['team_id' => $teamB->id])
            ->assertOk();

        $since = urlencode(now()->subHour()->toIso8601String());

        /* Destination team's delta feed carries root + child as changed */
        $deltaB = $this->actingAsApi($other, ['item:read'])
            ->getJson("/api/item?since={$since}")
            ->assertOk()
            ->json();
        $this->assertEqualsCanonicalizing([$root->id, $child->id], array_column($deltaB['changed'], 'id'));

        /* Source team's delta never carries the rows (they left the team
           scope); the bumped revision is what sends source clients
           re-pulling */
        $deltaA = $this->actingAsApi($user, ['item:read'])
            ->getJson("/api/item?since={$since}")
            ->assertOk()
            ->json();
        $this->assertSame([], array_column($deltaA['changed'], 'id'));
        $this->assertSame([], $deltaA['deleted_ids']);
    }

    public function test_unknown_item_404_and_unauthenticated_401(): void
    {
        [$user, $teamA] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($teamA)->create();

        /* Implicit binding runs before auth — a known id is required for
           the 401 case, an authenticated request for the 404 case */
        $this->actingAsApi($user, ['item:write'])
            ->postJson('/api/item/00000000-0000-0000-0000-000000000000/transfer', [
                'team_id' => $teamA->id,
            ])
            ->assertStatus(404);

        /* flushHeaders drops the Authorization header; forgetGuards is also
           required — the sanctum RequestGuard memoizes its resolved user
           across requests in one test, and a memoized user survives even a
           headerless request */
        $this->app['auth']->forgetGuards();
        $this->flushHeaders()
            ->postJson("/api/item/{$item->id}/transfer", ['team_id' => $teamA->id])
            ->assertStatus(401);
    }
}
