<?php

namespace Tests\Feature;

use App\Models\History;
use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithApi;
use Tests\TestCase;

/**
 * API-008: deletion semantics for non-empty boxes.
 *
 * Decision (server.md §5, recommended option): deleting a box re-parents its
 * direct children to root and reports them in the additive `detached_ids`
 * response field, with one history row per detached child — all in one
 * transaction.
 */
class ItemDeletionPolicyTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithApi;

    public function test_deleting_a_box_detaches_children_to_root(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $box = Item::factory()->onTeam($team)->create();
        $children = Item::factory()->onTeam($team)->count(3)->create();
        $children->each(fn (Item $child) => $child->update(['parent_id' => $box->id]));

        $response = $this->actingAsApi($user, ['item:write'])
            ->deleteJson("/api/item/{$box->id}")
            ->assertOk()
            ->assertJsonPath('success', 'success');

        /* Additive response field lists exactly the detached children */
        $detachedIds = $response->json('detached_ids');
        $this->assertEqualsCanonicalizing($children->pluck('id')->all(), $detachedIds);

        /* Children are roots now, each with one parent_id history row */
        $children->each(function (Item $child) use ($box) {
            $this->assertNull($child->fresh()->parent_id);
            $this->assertDatabaseHas('histories', [
                'item_id' => $child->id,
                'field_name' => 'parent_id',
                'old_value' => $box->id,
                'new_value' => null,
            ]);
        });
        $this->assertDatabaseCount('histories', 3);

        /* The box itself is a tombstone (API-005) */
        $this->assertNotNull(Item::withTrashed()->find($box->id)->deleted_at);
    }

    public function test_grandchildren_keep_their_own_parents(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $box = Item::factory()->onTeam($team)->create();
        $child = Item::factory()->onTeam($team)->childOf($box)->create();
        $grandchild = Item::factory()->onTeam($team)->childOf($child)->create();

        $response = $this->actingAsApi($user, ['item:write'])
            ->deleteJson("/api/item/{$box->id}")
            ->assertOk();

        /* Only the direct child is detached; the grandchild follows its
           (now-root) parent */
        $this->assertSame([$child->id], $response->json('detached_ids'));
        $this->assertNull($child->fresh()->parent_id);
        $this->assertSame($child->id, $grandchild->fresh()->parent_id);
        $this->assertDatabaseCount('histories', 1);
    }

    public function test_deleting_a_childless_box_returns_an_empty_detached_ids(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $box = Item::factory()->onTeam($team)->create();

        $this->actingAsApi($user, ['item:write'])
            ->deleteJson("/api/item/{$box->id}")
            ->assertOk()
            ->assertJsonPath('success', 'success')
            ->assertJsonPath('detached_ids', []);

        $this->assertDatabaseCount('histories', 0);
    }

    public function test_detached_children_are_listed_as_roots(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $box = Item::factory()->onTeam($team)->create();
        $child = Item::factory()->onTeam($team)->childOf($box)->create();

        $this->actingAsApi($user, ['item:write'])
            ->deleteJson("/api/item/{$box->id}")
            ->assertOk();

        /* The box is gone from the list; the child remains, as a root */
        $response = $this->actingAsApi($user, ['item:read'])
            ->getJson('/api/item')
            ->assertOk()
            ->assertJsonCount(1);

        $this->assertSame($child->id, $response->json('0.id'));
        $this->assertNull($response->json('0.parent_id'));
    }

    public function test_history_rows_reference_the_deleting_user(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $box = Item::factory()->onTeam($team)->create();
        $child = Item::factory()->onTeam($team)->childOf($box)->create();

        $this->actingAsApi($user, ['item:write'])
            ->deleteJson("/api/item/{$box->id}")
            ->assertOk();

        $this->assertSame($user->id, History::first()->user_id);
    }

    public function test_mid_detach_failure_rolls_back_the_whole_delete(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $box = Item::factory()->onTeam($team)->create();
        $child = Item::factory()->onTeam($team)->childOf($box)->create();

        /* Sabotage the box delete: the listener fires inside the
           transaction, so the earlier child detach must roll back too */
        Item::deleted(function (Item $deleted) use ($box) {
            if ($deleted->id === $box->id) {
                throw new \RuntimeException('boom');
            }
        });

        $this->actingAsApi($user, ['item:write'])
            ->deleteJson("/api/item/{$box->id}")
            ->assertStatus(500);

        $this->assertSame($box->id, $child->fresh()->parent_id);
        $this->assertNull(Item::withTrashed()->find($box->id)->deleted_at);
        $this->assertDatabaseCount('histories', 0);
    }

    public function test_detached_children_surface_in_the_next_delta(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $box = Item::factory()->onTeam($team)->create();
        $child = Item::factory()->onTeam($team)->childOf($box)->create();

        /* Backdate both so only the delete + detach land in the delta window */
        DB::table('items')->whereIn('id', [$box->id, $child->id])
            ->update(['updated_at' => now()->subMinutes(10)]);
        $since = urlencode(now()->subMinutes(5)->toIso8601String());

        $this->actingAsApi($user, ['item:write'])
            ->deleteJson("/api/item/{$box->id}")
            ->assertOk();

        /* The child reports as changed (its updated_at moved), the box only
           as a deleted id */
        $response = $this->actingAsApi($user, ['item:read'])
            ->getJson("/api/item?since={$since}")
            ->assertOk()
            ->assertJsonCount(1, 'changed')
            ->assertJsonPath('deleted_ids', [$box->id]);

        $this->assertSame($child->id, $response->json('changed.0.id'));
    }

    public function test_read_only_member_cannot_delete_a_box(): void
    {
        [$owner, $team] = $this->newUserWithTeam();
        $viewer = $this->addTeamMember(User::factory()->create(), $team, 'Read Only');
        $box = Item::factory()->onTeam($team)->create();
        $child = Item::factory()->onTeam($team)->childOf($box)->create();

        $this->actingAsApi($viewer, ['item:write'])
            ->deleteJson("/api/item/{$box->id}")
            ->assertStatus(403);

        /* Nothing happened */
        $this->assertSame($box->id, $child->fresh()->parent_id);
        $this->assertNull(Item::withTrashed()->find($box->id)->deleted_at);
    }
}
