<?php

namespace Tests\Feature;

use App\Models\Backup;
use App\Models\Item;
use App\Models\Label;
use App\Models\Location;
use App\Models\Status;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithApi;
use Tests\TestCase;
use ZipArchive;

/**
 * API-018: savepoint backups — CSV snapshot per team (items with
 * soft-deleted tombstones, locations, statuses, labels), zipped on the
 * server, owned by the creating user, downloadable, last 7 per team kept.
 */
class BackupTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithApi;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    /**
     * Populate the team: 3 items (one soft-deleted), 2 locations, 2
     * statuses, 1 label.
     */
    private function populate(User $user, \App\Models\Team $team): Item
    {
        Location::factory()->onTeam($team)->create(['name' => 'Aisle 1']);
        Location::factory()->onTeam($team)->create(['name' => 'Aisle 2']);
        Status::factory()->onTeam($team)->create(['name' => 'In stock']);
        Status::factory()->onTeam($team)->create(['name' => 'Low']);
        Label::create(['name' => 'Fragile', 'color' => '#FF0000', 'team_id' => $team->id]);

        Item::factory()->onTeam($team)->create(['name' => 'Alpha']);
        Item::factory()->onTeam($team)->create(['name' => 'Bravo']);

        return Item::factory()->onTeam($team)->create(['name' => 'Trashed']);
    }

    /**
     * Extract the four CSV files from raw zip bytes.
     */
    private function unzip(string $bytes): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'unzip');
        file_put_contents($tmp, $bytes);
        $zip = new ZipArchive();
        $zip->open($tmp);

        $csvs = [];
        foreach (['items', 'locations', 'statuses', 'labels'] as $name) {
            $csvs[$name] = $zip->getFromName("{$name}.csv");
        }
        $zip->close();
        unlink($tmp);

        return $csvs;
    }

    /**
     * Parse CSV text (as produced by fputcsv) into an array of row arrays.
     */
    private function parseCsv(string $csv): array
    {
        return array_map(
            fn (string $line): array => str_getcsv($line, ',', '"', '\\'),
            explode("\n", trim($csv))
        );
    }

    public function test_round_trip_create_list_download_delete(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $trashed = $this->populate($user, $team);
        $trashed->delete();
        $revisionBefore = $team->fresh()->revision;

        /* Create */
        $created = $this->actingAsApi($user, ['item:write'])
            ->postJson('/api/backups')
            ->assertStatus(201)
            ->json();

        $this->assertSame($user->id, $created['user_id']);
        $this->assertSame(3, $created['item_count']);
        $this->assertSame(2, $created['location_count']);
        $this->assertSame(2, $created['status_count']);
        $this->assertSame(1, $created['label_count']);
        $this->assertGreaterThan(0, $created['size']);
        $this->assertStringContainsString("/api/backup/{$created['id']}", $created['url']);
        $this->assertArrayNotHasKey('path', $created);
        $this->assertArrayNotHasKey('disk', $created);
        $this->assertSame($revisionBefore + 1, $team->fresh()->revision);

        /* File stored under the documented layout */
        Storage::disk('local')->assertExists("backups/{$team->id}/{$created['id']}.zip");

        /* List — metadata only, newest first */
        $rows = $this->actingAsApi($user, ['item:read'])
            ->getJson('/api/backups')
            ->assertOk()
            ->json();
        $this->assertCount(1, $rows);
        $this->assertSame($created['id'], $rows[0]['id']);
        $this->assertArrayNotHasKey('path', $rows[0]);
        $this->assertArrayNotHasKey('disk', $rows[0]);

        /* Download — a real zip with the right filename */
        $this->app['auth']->forgetGuards();
        $download = $this->withToken(explode('|', $user->createToken('dl', ['item:read'])->plainTextToken)[1])
            ->get("/api/backup/{$created['id']}")
            ->assertOk();
        $this->assertStringContainsString('application/zip', $download->headers->get('Content-Type'));
        $this->assertStringContainsString("backup-{$team->id}-", $download->headers->get('Content-Disposition'));
        $bytes = $download->streamedContent();
        $this->assertStringStartsWith('PK', $bytes);

        /* The CSVs parse back to exactly the dumped rows — including the
           soft-deleted item with its deleted_at */
        $csvs = $this->unzip($bytes);

        $items = $this->parseCsv($csvs['items']);
        $this->assertCount(4, $items); // header + 3
        $nameCol = array_search('name', $items[0], true);
        $deletedCol = array_search('deleted_at', $items[0], true);
        $names = array_column($items, $nameCol);
        $this->assertContains('Alpha', $names);
        $this->assertContains('Bravo', $names);
        $this->assertContains('Trashed', $names);
        $trashRow = $items[array_search('Trashed', $names, true)];
        $this->assertNotSame('', $trashRow[$deletedCol]);
        foreach ($items as $index => $row) {
            if ($index > 0 && $row[$nameCol] !== 'Trashed') {
                $this->assertSame('', $row[$deletedCol]);
            }
        }

        $locations = $this->parseCsv($csvs['locations']);
        $this->assertCount(3, $locations); // header + 2
        $locNameCol = array_search('name', $locations[0], true);
        $this->assertContains('Aisle 1', array_column($locations, $locNameCol));
        $this->assertContains('Aisle 2', array_column($locations, $locNameCol));

        $statuses = $this->parseCsv($csvs['statuses']);
        $this->assertCount(3, $statuses); // header + 2
        $stNameCol = array_search('name', $statuses[0], true);
        $this->assertContains('In stock', array_column($statuses, $stNameCol));
        $this->assertContains('Low', array_column($statuses, $stNameCol));

        $labels = $this->parseCsv($csvs['labels']);
        $this->assertCount(2, $labels); // header + 1
        $lbNameCol = array_search('name', $labels[0], true);
        $this->assertContains('Fragile', array_column($labels, $lbNameCol));

        /* Delete — row + file gone, revision bumped again */
        $this->actingAsApi($user, ['item:write'])
            ->deleteJson("/api/backup/{$created['id']}")
            ->assertOk()
            ->assertJsonPath('success', 'success');
        Storage::disk('local')->assertMissing("backups/{$team->id}/{$created['id']}.zip");
        $this->assertDatabaseMissing('backups', ['id' => $created['id']]);
        $this->assertSame($revisionBefore + 2, $team->fresh()->revision);
    }

    public function test_retention_keeps_the_last_seven_and_delete_frees_a_slot(): void
    {
        [$user, $team] = $this->newUserWithTeam();

        /* Eight creates a minute apart — deterministic created_at ordering */
        $base = Carbon::now()->subMinutes(30);
        $ids = [];
        for ($i = 0; $i < 8; $i++) {
            $this->travelTo($base->copy()->addMinutes($i));
            $ids[] = $this->actingAsApi($user, ['item:write'])
                ->postJson('/api/backups')
                ->assertStatus(201)
                ->json('id');
        }
        Carbon::setTestNow();

        $rows = $this->actingAsApi($user, ['item:read'])
            ->getJson('/api/backups')
            ->assertOk()
            ->json();

        /* The oldest (first created) is gone, row + file; 7 remain, newest first */
        $this->assertCount(7, $rows);
        $this->assertSame($ids[7], $rows[0]['id']);
        $this->assertNotContains($ids[0], array_column($rows, 'id'));
        Storage::disk('local')->assertMissing("backups/{$team->id}/{$ids[0]}.zip");
        Storage::disk('local')->assertExists("backups/{$team->id}/{$ids[1]}.zip");
        $this->assertDatabaseMissing('backups', ['id' => $ids[0]]);

        /* A manual DELETE frees a slot — retention counts rows */
        $this->actingAsApi($user, ['item:write'])
            ->deleteJson("/api/backup/{$ids[1]}")
            ->assertOk();
        $this->assertCount(6, $this->actingAsApi($user, ['item:read'])
            ->getJson('/api/backups')->assertOk()->json());

        $new = $this->actingAsApi($user, ['item:write'])
            ->postJson('/api/backups')
            ->assertStatus(201)
            ->json('id');

        $rows = $this->actingAsApi($user, ['item:read'])
            ->getJson('/api/backups')
            ->assertOk()
            ->json();
        $this->assertCount(7, $rows);
        /* The slot was free: nothing else was pruned */
        Storage::disk('local')->assertExists("backups/{$team->id}/{$ids[2]}.zip");
        $this->assertDatabaseHas('backups', ['id' => $ids[2]]);
        $this->assertSame($new, $rows[0]['id']);
    }

    public function test_permission_matrix(): void
    {
        [$owner, $team] = $this->newUserWithTeam();
        $this->populate($owner, $team);

        $created = $this->actingAsApi($owner, ['item:write'])
            ->postJson('/api/backups')
            ->assertStatus(201)
            ->json();

        /* Read-only team member: list/download allowed, create/delete not */
        [$ro] = $this->newUserWithTeam();
        $this->addTeamMember($ro, $team, 'Read Only');

        $this->actingAsApi($ro, ['item:read'])->postJson('/api/backups')->assertStatus(403);
        $this->actingAsApi($ro, ['item:read'])
            ->deleteJson("/api/backup/{$created['id']}")
            ->assertStatus(403);
        $this->actingAsApi($ro, ['item:read'])->getJson('/api/backups')->assertOk();

        $this->app['auth']->forgetGuards();
        $download = $this->withToken(explode('|', $ro->createToken('ro', ['item:read'])->plainTextToken)[1])
            ->get("/api/backup/{$created['id']}")
            ->assertOk();
        $this->assertStringStartsWith('PK', $download->streamedContent());

        /* Full-permission member but a token without item:write */
        $this->actingAsApi($owner, ['item:read'])->postJson('/api/backups')->assertStatus(403);
        $this->actingAsApi($owner, ['item:read'])
            ->deleteJson("/api/backup/{$created['id']}")
            ->assertStatus(403);

        /* A foreign user (own team, not a member here): 403 on the verbs,
           and their own (empty) team scope on the list */
        [$foreign, $teamB] = $this->newUserWithTeam();
        $this->actingAsApi($foreign, ['item:read'])
            ->getJson("/api/backup/{$created['id']}")
            ->assertStatus(403);
        $this->actingAsApi($foreign, ['item:write'])
            ->deleteJson("/api/backup/{$created['id']}")
            ->assertStatus(403);
        $this->assertSame([], $this->actingAsApi($foreign, ['item:read'])
            ->getJson('/api/backups')->assertOk()->json());

        /* Unknown id → 404 (route binding, before authorization) */
        $unknown = (string) Str::uuid();
        $this->actingAsApi($owner, ['item:write'])
            ->getJson("/api/backup/{$unknown}")
            ->assertStatus(404);
        $this->actingAsApi($owner, ['item:write'])
            ->deleteJson("/api/backup/{$unknown}")
            ->assertStatus(404);
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/backups')->assertStatus(401);
        $this->postJson('/api/backups')->assertStatus(401);
    }
}
