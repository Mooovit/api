<?php

namespace Tests\Feature;

use App\Models\Audit;
use App\Models\History;
use App\Models\Item;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithApi;
use Tests\TestCase;

/**
 * API-010: POST/GET api/location/{location}/audits — server-side stocktake
 * snapshots (MV-050 reports), surfaced in the API-009 activity feed as
 * synthetic `audit` events.
 */
class LocationAuditTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithApi;

    /**
     * Create an audit row for a location with a controlled `created_at`
     * (columns have second precision, so backdate through the query builder).
     */
    private function makeAudit(Location $location, User $user, array $attributes = [], int $minutesAgo = 0): Audit
    {
        $audit = Audit::factory()->atLocation($location, $user)->create($attributes);

        if ($minutesAgo > 0) {
            DB::table('audits')->where('id', $audit->id)
                ->update(['created_at' => now()->subMinutes($minutesAgo)]);
        }

        return $audit->refresh();
    }

    public function test_submit_persists_computed_counts_and_payload(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $location = Location::factory()->onTeam($team)->create();

        /* The ticket's acceptance matrix: expected 45, 42 scanned found,
           3 declared missing, 2 extra rows, 1 unknown code. */
        $found = Item::factory()->onTeam($team)->count(42)->create()->pluck('id')->all();
        $extraIds = Item::factory()->onTeam($team)->count(2)->create()->pluck('id')->all();
        $extra = array_map(fn (string $id) => ['id' => $id], $extraIds);

        $response = $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/location/{$location->id}/audits", [
                'found_ids' => $found,
                'extra' => $extra,
                'unknown_codes' => ['ZZ-77'],
                'missing_count' => 3,
            ])
            ->assertStatus(201);

        /* Counts computed server-side from the payload, not trusted */
        $this->assertSame(42, $response->json('found_count'));
        $this->assertSame(3, $response->json('missing_count'));
        $this->assertSame(2, $response->json('extra_count'));

        /* Exact MV-050 payload round-trip */
        $this->assertSame($found, $response->json('payload.found_ids'));
        $this->assertSame($extra, $response->json('payload.extra'));
        $this->assertSame(['ZZ-77'], $response->json('payload.unknown_codes'));

        $this->assertSame($location->id, $response->json('location_id'));
        $this->assertSame($user->id, $response->json('user_id'));
        $this->assertDatabaseCount('audits', 1);
    }

    public function test_submit_defaults_to_empty_extra_unknown_and_missing(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $location = Location::factory()->onTeam($team)->create();
        $found = Item::factory()->onTeam($team)->count(3)->create()->pluck('id')->all();

        $response = $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/location/{$location->id}/audits", ['found_ids' => $found])
            ->assertStatus(201);

        $this->assertSame(3, $response->json('found_count'));
        $this->assertSame(0, $response->json('missing_count'));
        $this->assertSame(0, $response->json('extra_count'));
        $this->assertSame([], $response->json('payload.extra'));
        $this->assertSame([], $response->json('payload.unknown_codes'));
    }

    public function test_trashed_items_still_validate_snapshot_semantics(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $location = Location::factory()->onTeam($team)->create();
        $kept = Item::factory()->onTeam($team)->create();
        $trashed = Item::factory()->onTeam($team)->create();
        $trashed->delete();

        /* The scan happened before the deletion: team membership only —
           never existence-in-live-table, never current location */
        $response = $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/location/{$location->id}/audits", [
                'found_ids' => [$kept->id, $trashed->id],
            ])
            ->assertStatus(201);

        $this->assertSame(2, $response->json('found_count'));
    }

    public function test_rejects_ids_belonging_to_another_team(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        [$stranger, $otherTeam] = $this->newUserWithTeam();
        $location = Location::factory()->onTeam($team)->create();
        $mine = Item::factory()->onTeam($team)->create();
        $foreign = Item::factory()->onTeam($otherTeam)->create();

        $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/location/{$location->id}/audits", ['found_ids' => [$foreign->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('found_ids');

        /* Same team check applies inside `extra` */
        $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/location/{$location->id}/audits", [
                'found_ids' => [$mine->id],
                'extra' => [['id' => $foreign->id]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('found_ids');

        $this->assertDatabaseCount('audits', 0);
    }

    public function test_store_requires_item_write_permission(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $location = Location::factory()->onTeam($team)->create();
        $found = Item::factory()->onTeam($team)->create();

        /* Admin team permission, but the token lacks the ability */
        $this->actingAsApi($user, ['item:read'])
            ->postJson("/api/location/{$location->id}/audits", ['found_ids' => [$found->id]])
            ->assertStatus(403);

        /* Read-Only team member with a full-ability token */
        [$member] = $this->newUserWithTeam();
        $this->addTeamMember($member, $team, 'Read Only');
        $this->actingAsApi($member, ['item:write'])
            ->postJson("/api/location/{$location->id}/audits", ['found_ids' => [$found->id]])
            ->assertStatus(403);

        $this->assertDatabaseCount('audits', 0);
    }

    public function test_store_and_list_reject_foreign_team_location(): void
    {
        [$user] = $this->newUserWithTeam();
        [$stranger, $otherTeam] = $this->newUserWithTeam();
        $foreignLocation = Location::factory()->onTeam($otherTeam)->create();

        $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/location/{$foreignLocation->id}/audits", ['found_ids' => ['whatever']])
            ->assertStatus(403);

        $this->actingAsApi($user, ['item:read'])
            ->getJson("/api/location/{$foreignLocation->id}/audits")
            ->assertStatus(403);
    }

    public function test_list_is_location_scoped_newest_first_and_paginated(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $location = Location::factory()->onTeam($team)->create();
        $otherLocation = Location::factory()->onTeam($team)->create();

        $oldest = $this->makeAudit($location, $user, [], 30);
        $middle = $this->makeAudit($location, $user, [], 20);
        $newest = $this->makeAudit($location, $user, [], 10);
        $this->makeAudit($otherLocation, $user); // must not leak into $location's list

        $page1 = $this->actingAsApi($user, ['item:read'])
            ->getJson("/api/location/{$location->id}/audits?per_page=2&page=1")
            ->assertOk();
        $page2 = $this->actingAsApi($user, ['item:read'])
            ->getJson("/api/location/{$location->id}/audits?per_page=2&page=2")
            ->assertOk();

        $this->assertSame(3, $page1->json('total'));
        $this->assertSame([$newest->id, $middle->id], array_column($page1->json('data'), 'id'));
        $this->assertSame([$oldest->id], array_column($page2->json('data'), 'id'));

        /* Row shape: actor eager-loaded, counts + payload present */
        $row = $page1->json('data.0');
        $this->assertSame($user->name, $row['user']['name']);
        $this->assertArrayHasKey('found_count', $row);
        $this->assertArrayHasKey('missing_count', $row);
        $this->assertArrayHasKey('extra_count', $row);
        $this->assertArrayHasKey('payload', $row);
    }

    public function test_list_requires_item_read_ability(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $location = Location::factory()->onTeam($team)->create();

        $this->actingAsApi($user, ['status:read'])
            ->getJson("/api/location/{$location->id}/audits")
            ->assertStatus(403);
    }

    public function test_feed_shows_audit_event_with_summary(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $location = Location::factory()->onTeam($team)->create();
        $found = Item::factory()->onTeam($team)->count(3)->create()->pluck('id')->all();
        $extra = Item::factory()->onTeam($team)->create();

        $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/location/{$location->id}/audits", [
                'found_ids' => $found,
                'extra' => [['id' => $extra->id]],
                'missing_count' => 2,
            ])
            ->assertStatus(201);

        $rows = $this->actingAsApi($user, ['item:read'])
            ->getJson('/api/activity')
            ->assertOk()
            ->json('data');

        /* One synthetic audit row, same key shape as history rows */
        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame('audit', $row['field_name']);
        $this->assertSame('3 found, 2 missing declared, 1 extra', $row['new_value']);
        $this->assertNull($row['old_value']);
        $this->assertNull($row['item_id']);
        $this->assertNull($row['item_name']);
        $this->assertSame($user->id, $row['user_id']);
        $this->assertSame($user->name, $row['user_name']);
        $this->assertSame($location->id, $row['location_id']);
        $this->assertSame($location->name, $row['location_name']);
        $this->assertArrayHasKey('changed_at', $row);
    }

    public function test_feed_summary_variants(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $location = Location::factory()->onTeam($team)->create();

        $this->makeAudit($location, $user, ['found_count' => 1, 'missing_count' => 2], 30);
        $this->makeAudit($location, $user, ['found_count' => 1, 'extra_count' => 3], 20);
        $this->makeAudit($location, $user, ['found_count' => 5], 10);

        $rows = $this->actingAsApi($user, ['item:read'])
            ->getJson('/api/activity')
            ->assertOk()
            ->json('data');

        $this->assertCount(3, $rows);
        $this->assertSame('5 found', $rows[0]['new_value']);
        $this->assertSame('1 found, 3 extra', $rows[1]['new_value']);
        $this->assertSame('1 found, 2 missing declared', $rows[2]['new_value']);
    }

    public function test_feed_merges_audits_and_history_newest_first(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();
        $location = Location::factory()->onTeam($team)->create();

        $history = History::factory()->forItem($item, $user)->create([
            'field_name' => 'name',
            'changed_at' => now()->subMinutes(30),
        ]);
        $audit = $this->makeAudit($location, $user, [], 10);

        /* Default: both sources, newest first */
        $rows = $this->actingAsApi($user, ['item:read'])
            ->getJson('/api/activity')
            ->assertOk()
            ->json('data');
        $this->assertSame([$audit->id, $history->id], array_column($rows, 'id'));

        /* type=audit → only the synthetic rows */
        $rows = $this->actingAsApi($user, ['item:read'])
            ->getJson('/api/activity?type=audit')
            ->assertOk()
            ->json('data');
        $this->assertSame([$audit->id], array_column($rows, 'id'));
        $this->assertSame('audit', $rows[0]['field_name']);

        /* A concrete type excludes audits */
        $rows = $this->actingAsApi($user, ['item:read'])
            ->getJson('/api/activity?type=name')
            ->assertOk()
            ->json('data');
        $this->assertSame([$history->id], array_column($rows, 'id'));
    }

    public function test_feed_item_filter_excludes_audits(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();
        $location = Location::factory()->onTeam($team)->create();

        $row = History::factory()->forItem($item, $user)->create(['field_name' => 'name']);
        $this->makeAudit($location, $user);

        /* Item-scoped views can never contain team-scoped audit rows */
        $rows = $this->actingAsApi($user, ['item:read'])
            ->getJson("/api/activity?item_id={$item->id}")
            ->assertOk()
            ->json('data');

        $this->assertSame([$row->id], array_column($rows, 'id'));
    }

    public function test_feed_location_filter_matches_audits_taken_there(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();
        $locX = Location::factory()->onTeam($team)->create();
        $locY = Location::factory()->onTeam($team)->create();

        $moveIntoX = History::factory()->forItem($item, $user)->create([
            'field_name' => 'location_id',
            'old_value' => null,
            'new_value' => $locX->id,
            'changed_at' => now()->subMinutes(30),
        ]);
        $auditX = $this->makeAudit($locX, $user, [], 20);
        $this->makeAudit($locY, $user, [], 15);

        /* History = moves INTO loc-x; audits = taken AT loc-x */
        $rows = $this->actingAsApi($user, ['item:read'])
            ->getJson("/api/activity?location_id={$locX->id}")
            ->assertOk()
            ->json('data');

        $this->assertSame([$auditX->id, $moveIntoX->id], array_column($rows, 'id'));
        $this->assertSame($locX->id, $rows[0]['location_id']);
    }

    public function test_feed_audits_are_team_scoped(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        [$stranger, $otherTeam] = $this->newUserWithTeam();
        $myLocation = Location::factory()->onTeam($team)->create();
        $foreignLocation = Location::factory()->onTeam($otherTeam)->create();

        $mine = $this->makeAudit($myLocation, $user, [], 5);
        $this->makeAudit($foreignLocation, $stranger, [], 4);

        $rows = $this->actingAsApi($user, ['item:read'])
            ->getJson('/api/activity')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame($mine->id, $rows[0]['id']);
    }

    public function test_store_bumps_team_revision_once(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $location = Location::factory()->onTeam($team)->create();
        $found = Item::factory()->onTeam($team)->count(2)->create()->pluck('id')->all();
        $revisionBefore = $team->fresh()->revision;

        $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/location/{$location->id}/audits", ['found_ids' => $found])
            ->assertStatus(201);

        /* Factory-created rows bump the counter too — assert relative */
        $this->assertSame($revisionBefore + 1, $team->fresh()->revision);
    }

    public function test_input_validation(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $location = Location::factory()->onTeam($team)->create();

        /* found_ids is required */
        $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/location/{$location->id}/audits", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['found_ids']);

        /* Element types and declared counters */
        $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/location/{$location->id}/audits", [
                'found_ids' => [123],
                'unknown_codes' => [42],
                'missing_count' => -1,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['found_ids.0', 'unknown_codes.0', 'missing_count']);

        /* extra rows must carry an id */
        $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/location/{$location->id}/audits", [
                'found_ids' => [],
                'extra' => [['code' => 'no-id']],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['found_ids', 'extra.0.id']);

        $this->actingAsApi($user, ['item:read'])
            ->getJson("/api/location/{$location->id}/audits?per_page=500")
            ->assertStatus(422);
    }
}
