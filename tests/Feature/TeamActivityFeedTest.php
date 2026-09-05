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
 * API-009: GET api/activity — team-wide, filterable, paginated change feed.
 */
class TeamActivityFeedTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithApi;

    /**
     * Create a history row with a controlled `changed_at` (column has second
     * precision, so backdate through the query builder).
     */
    private function makeRow(Item $item, User $user, array $attributes, int $minutesAgo = 0): History
    {
        $row = History::factory()->forItem($item, $user)->create($attributes);

        if ($minutesAgo > 0) {
            DB::table('histories')->where('id', $row->id)
                ->update(['changed_at' => now()->subMinutes($minutesAgo)]);
        }

        return $row->refresh();
    }

    public function test_feed_returns_team_rows_newest_first_with_names(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();
        $oldest = $this->makeRow($item, $user, ['field_name' => 'name', 'new_value' => 'First'], 30);
        $newest = $this->makeRow($item, $user, ['field_name' => 'parent_id'], 10);

        $response = $this->actingAsApi($user, ['item:read'])
            ->getJson('/api/activity')
            ->assertOk();

        $rows = $response->json('data');
        $this->assertCount(2, $rows);
        $this->assertSame($newest->id, $rows[0]['id']);
        $this->assertSame($oldest->id, $rows[1]['id']);

        /* Contract shape per row */
        $this->assertSame($item->id, $rows[0]['item_id']);
        $this->assertSame($item->name, $rows[0]['item_name']);
        $this->assertSame($user->id, $rows[0]['user_id']);
        $this->assertSame($user->name, $rows[0]['user_name']);
        $this->assertSame('parent_id', $rows[0]['field_name']);
        $this->assertArrayHasKey('old_value', $rows[0]);
        $this->assertArrayHasKey('new_value', $rows[0]);
        $this->assertArrayHasKey('changed_at', $rows[0]);
    }

    public function test_feed_is_paginated(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();
        History::factory()->forItem($item, $user)->count(5)->create();

        $page1 = $this->actingAsApi($user, ['item:read'])
            ->getJson('/api/activity?per_page=2&page=1')
            ->assertOk();
        $page2 = $this->actingAsApi($user, ['item:read'])
            ->getJson('/api/activity?per_page=2&page=2')
            ->assertOk();
        $page3 = $this->actingAsApi($user, ['item:read'])
            ->getJson('/api/activity?per_page=2&page=3')
            ->assertOk();

        $this->assertCount(2, $page1->json('data'));
        $this->assertSame(5, $page1->json('total'));
        $this->assertCount(2, $page2->json('data'));
        $this->assertCount(1, $page3->json('data'));

        /* Pages continue cleanly — no overlap */
        $ids1 = array_column($page1->json('data'), 'id');
        $ids2 = array_column($page2->json('data'), 'id');
        $this->assertEmpty(array_intersect($ids1, $ids2));
    }

    public function test_since_filter(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();
        $old = $this->makeRow($item, $user, ['field_name' => 'name'], 30);
        $this->makeRow($item, $user, ['field_name' => 'status_id'], 1);
        $since = urlencode(now()->subMinutes(10)->toIso8601String());

        $rows = $this->actingAsApi($user, ['item:read'])
            ->getJson("/api/activity?since={$since}")
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $rows);
        $this->assertNotContains($old->id, array_column($rows, 'id'));
    }

    public function test_item_filter(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $a = Item::factory()->onTeam($team)->create();
        $b = Item::factory()->onTeam($team)->create();
        $rowA = $this->makeRow($a, $user, ['field_name' => 'name'], 5);
        $this->makeRow($b, $user, ['field_name' => 'name'], 2);

        $rows = $this->actingAsApi($user, ['item:read'])
            ->getJson("/api/activity?item_id={$a->id}")
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame($rowA->id, $rows[0]['id']);
    }

    public function test_type_filter(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();
        $move = $this->makeRow($item, $user, ['field_name' => 'parent_id'], 5);
        $this->makeRow($item, $user, ['field_name' => 'name'], 3);

        $rows = $this->actingAsApi($user, ['item:read'])
            ->getJson('/api/activity?type=parent_id')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame($move->id, $rows[0]['id']);
    }

    public function test_location_filter_means_moves_into_that_location(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();
        $into = $this->makeRow($item, $user, [
            'field_name' => 'location_id', 'old_value' => null, 'new_value' => 'loc-x',
        ], 5);
        /* Moved out of loc-x: old_value matches but new_value doesn't */
        $this->makeRow($item, $user, [
            'field_name' => 'location_id', 'old_value' => 'loc-x', 'new_value' => 'loc-y',
        ], 3);
        $this->makeRow($item, $user, ['field_name' => 'status_id', 'new_value' => 'loc-x'], 2);

        $rows = $this->actingAsApi($user, ['item:read'])
            ->getJson('/api/activity?location_id=loc-x')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame($into->id, $rows[0]['id']);
    }

    public function test_combo_filters(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();
        $this->makeRow($item, $user, ['field_name' => 'parent_id'], 5);

        /* A move row never matches location_id=new_value */
        $rows = $this->actingAsApi($user, ['item:read'])
            ->getJson("/api/activity?location_id=loc-x&type=parent_id")
            ->assertOk()
            ->json('data');
        $this->assertCount(0, $rows);

        /* And the type alone still works alongside item_id */
        $rows = $this->actingAsApi($user, ['item:read'])
            ->getJson("/api/activity?item_id={$item->id}&type=parent_id")
            ->assertOk()
            ->json('data');
        $this->assertCount(1, $rows);
    }

    public function test_feed_is_team_scoped(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        [$stranger, $otherTeam] = $this->newUserWithTeam();
        $mine = Item::factory()->onTeam($team)->create();
        $foreign = Item::factory()->onTeam($otherTeam)->create();
        $myRow = $this->makeRow($mine, $user, ['field_name' => 'name'], 5);
        $this->makeRow($foreign, $stranger, ['field_name' => 'name'], 4);

        $rows = $this->actingAsApi($user, ['item:read'])
            ->getJson('/api/activity')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame($myRow->id, $rows[0]['id']);
    }

    public function test_requires_item_read_ability(): void
    {
        [$user] = $this->newUserWithTeam();

        $this->actingAsApi($user, ['status:read'])
            ->getJson('/api/activity')
            ->assertStatus(403);
    }

    public function test_input_validation(): void
    {
        [$user] = $this->newUserWithTeam();

        $this->actingAsApi($user, ['item:read'])
            ->getJson('/api/activity?type=bogus_field')
            ->assertStatus(422);

        $this->actingAsApi($user, ['item:read'])
            ->getJson('/api/activity?since=garbage')
            ->assertStatus(422);

        $this->actingAsApi($user, ['item:read'])
            ->getJson('/api/activity?per_page=500')
            ->assertStatus(422);
    }
}
