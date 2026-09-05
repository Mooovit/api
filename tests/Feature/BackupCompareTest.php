<?php

namespace Tests\Feature;

use App\Models\Backup;
use App\Models\Item;
use App\Models\Label;
use App\Models\Location;
use App\Models\Status;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithApi;
use Tests\TestCase;

/**
 * API-019: diff two savepoints (base → target) — added/removed/changed per
 * entity type with per-field {from, to}. Compared against the snapshots,
 * not the live tables.
 *
 * Pinned semantics: identity = row id; fields compare as trimmed strings
 * (null ≡ empty). The dumps include soft-deleted tombstones (API-018), so
 * a soft-delete between backups surfaces as `changed` on `deleted_at`;
 * only rows that truly left the table are `removed`.
 */
class BackupCompareTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithApi;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    /**
     * Create a backup through the API and return its id.
     */
    private function snapshot($user): string
    {
        return $this->actingAsApi($user, ['item:write'])
            ->postJson('/api/backups')
            ->assertStatus(201)
            ->json('id');
    }

    /**
     * Compare through the API and return the JSON payload.
     */
    private function diff($user, string $base, string $target): array
    {
        return $this->actingAsApi($user, ['item:read'])
            ->getJson("/api/backup/{$base}/compare/{$target}")
            ->assertOk()
            ->json();
    }

    public function test_mutation_fixture_reports_exactly_those_events(): void
    {
        [$user, $team] = $this->newUserWithTeam();

        /* Base world */
        $l1 = Location::factory()->onTeam($team)->create(['name' => 'Aisle 1']);
        $l3 = Location::factory()->onTeam($team)->create(['name' => 'Quarantine']);
        Status::factory()->onTeam($team)->create(['name' => 'In stock']);
        Status::factory()->onTeam($team)->create(['name' => 'Low']);
        $la1 = Label::create(['name' => 'Fragile', 'color' => '#FF0000', 'team_id' => $team->id]);
        $la0 = Label::create(['name' => 'Temp', 'color' => '#CCCCCC', 'team_id' => $team->id]);

        $keep = Item::factory()->onTeam($team)->create(['name' => 'Keep']);
        $rename = Item::factory()->onTeam($team)->create(['name' => 'Original name']);
        $move = Item::factory()->onTeam($team)->inLocation($l1)->create(['name' => 'Mover']);
        $gone = Item::factory()->onTeam($team)->create(['name' => 'Gone']);
        $soft = Item::factory()->onTeam($team)->create(['name' => 'Softly']);

        $base = $this->snapshot($user);

        /* Mutations */
        $new = Item::factory()->onTeam($team)->create(['name' => 'New']);

        $rename->update(['name' => 'Renamed']);

        $l2 = Location::factory()->onTeam($team)->create(['name' => 'Cold room']);
        $move->update(['location_id' => $l2->id]);

        $gone->forceDelete(); /* truly leaves the table → removed */
        $soft->delete(); /* tombstone stays → changed on deleted_at */

        $l3->delete();
        Label::create(['name' => 'New label', 'color' => '#00FF00', 'team_id' => $team->id]);
        $la1->update(['name' => 'Fragile 2']);
        $la0->delete();

        $target = $this->snapshot($user);

        $report = $this->diff($user, $base, $target);
        $this->assertArrayHasKey('generated_at', $report);

        /* Items */
        $items = $report['items'];
        $this->assertSame(
            ['added' => 1, 'removed' => 1, 'changed' => 3, 'unchanged' => 1],
            $items['counts']
        );

        $new = Item::where('name', 'New')->first();
        $this->assertNotNull($new);
        $this->assertSame($new->id, $items['added'][0]['id']);
        $this->assertSame($gone->id, $items['removed'][0]['id']);
        $this->assertSame('Gone', $items['removed'][0]['name']);

        $changed = collect($items['changed'])->keyBy('id');

        $renameDiff = $changed[$rename->id]['diff'];
        $this->assertSame('Original name', $renameDiff['name']['from']);
        $this->assertSame('Renamed', $renameDiff['name']['to']);

        $moveDiff = $changed[$move->id]['diff'];
        $this->assertSame($l1->id, $moveDiff['location_id']['from']);
        $this->assertSame($l2->id, $moveDiff['location_id']['to']);

        /* Tombstone: deleted_at '' → timestamp (other bumped columns like
           updated_at may ride along in the diff, depending on whether the
           timestamps straddle a second boundary) */
        $softDiff = $changed[$soft->id]['diff'];
        $this->assertSame('', $softDiff['deleted_at']['from']);
        $this->assertNotSame('', $softDiff['deleted_at']['to']);

        /* Locations: L2 added, L3 removed, L1 untouched */
        $this->assertSame(
            ['added' => 1, 'removed' => 1, 'changed' => 0, 'unchanged' => 1],
            $report['locations']['counts']
        );
        $this->assertSame($l2->id, $report['locations']['added'][0]['id']);
        $this->assertSame($l3->id, $report['locations']['removed'][0]['id']);

        /* Statuses: untouched */
        $this->assertSame(
            ['added' => 0, 'removed' => 0, 'changed' => 0, 'unchanged' => 2],
            $report['statuses']['counts']
        );

        /* Labels */
        $this->assertSame(
            ['added' => 1, 'removed' => 1, 'changed' => 1, 'unchanged' => 0],
            $report['labels']['counts']
        );
        $labels = collect($report['labels']['changed'])->keyBy('id');
        $this->assertSame('Fragile', $labels[$la1->id]['diff']['name']['from']);
        $this->assertSame('Fragile 2', $labels[$la1->id]['diff']['name']['to']);
        $this->assertSame('New label', $report['labels']['added'][0]['name']);
        $this->assertSame($la0->id, $report['labels']['removed'][0]['id']);
    }

    public function test_inverse_direction_reports_the_inverse_sets(): void
    {
        [$user, $team] = $this->newUserWithTeam();

        $gone = Item::factory()->onTeam($team)->create(['name' => 'Gone']);
        $rename = Item::factory()->onTeam($team)->create(['name' => 'Original name']);

        $base = $this->snapshot($user);

        $gone->forceDelete();
        $rename->update(['name' => 'Renamed']);

        $target = $this->snapshot($user);

        /* Reverse the direction: added ↔ removed, from ↔ to. The renamed
           row exists in both snapshots — it stays `changed`, and since the
           forward direction added nothing, nothing is removed backwards. */
        $inverse = $this->diff($user, $target, $base);

        $this->assertSame(
            ['added' => 1, 'removed' => 0, 'changed' => 1, 'unchanged' => 0],
            $inverse['items']['counts']
        );
        $this->assertSame($gone->id, $inverse['items']['added'][0]['id']);
        $this->assertSame([], $inverse['items']['removed']);

        $changed = collect($inverse['items']['changed'])->keyBy('id');
        $this->assertSame('Renamed', $changed[$rename->id]['diff']['name']['from']);
        $this->assertSame('Original name', $changed[$rename->id]['diff']['name']['to']);
    }

    public function test_auth_matrix_and_cross_team_pair(): void
    {
        [$owner, $team] = $this->newUserWithTeam();
        [$ro] = $this->newUserWithTeam();
        $this->addTeamMember($ro, $team, 'Read Only');
        [$foreign] = $this->newUserWithTeam();

        $a = $this->snapshot($owner);
        $b = $this->snapshot($owner);

        /* Read-only member (read permission, read token) is allowed */
        $this->actingAsApi($ro, ['item:read'])
            ->getJson("/api/backup/{$a}/compare/{$b}")
            ->assertOk();

        /* Valid team permission but a token without item:read */
        $this->actingAsApi($owner, ['item:write'])
            ->getJson("/api/backup/{$a}/compare/{$b}")
            ->assertStatus(403);

        /* A total stranger: 403 */
        $this->actingAsApi($foreign, ['item:read'])
            ->getJson("/api/backup/{$a}/compare/{$b}")
            ->assertStatus(403);

        /* Cross-team pair: the second backup belongs to another team */
        [$ownerB, $teamB] = $this->newUserWithTeam();
        $foreignBackup = $this->snapshot($ownerB);
        $this->actingAsApi($owner, ['item:read'])
            ->getJson("/api/backup/{$a}/compare/{$foreignBackup}")
            ->assertStatus(403);

        /* Unknown ids → 404 (route binding, before authorization) */
        $unknown = (string) Str::uuid();
        $this->actingAsApi($owner, ['item:read'])
            ->getJson("/api/backup/{$unknown}/compare/{$b}")
            ->assertStatus(404);
        $this->actingAsApi($owner, ['item:read'])
            ->getJson("/api/backup/{$a}/compare/{$unknown}")
            ->assertStatus(404);
    }

    public function test_empty_vs_empty(): void
    {
        [$user] = $this->newUserWithTeam();

        $a = $this->snapshot($user);
        $b = $this->snapshot($user);

        $report = $this->diff($user, $a, $b);

        foreach (['items', 'locations', 'statuses', 'labels'] as $type) {
            $this->assertSame([], $report[$type]['added']);
            $this->assertSame([], $report[$type]['removed']);
            $this->assertSame([], $report[$type]['changed']);
            $this->assertSame(
                ['added' => 0, 'removed' => 0, 'changed' => 0, 'unchanged' => 0],
                $report[$type]['counts'],
                "Type {$type} should be all-zero"
            );
        }
    }
}
