<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Item;
use App\Models\ItemBarcode;
use App\Models\Label;
use App\Models\Location;
use App\Models\Status;
use App\Support\Backup\BackupCodec;
use App\Support\Backup\BackupSnapshotBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithApi;
use Tests\TestCase;

/**
 * API-029 — the printable cold-storage backup sheet (GET /kanban/backup):
 * the admin gate, and the snapshot the builder emits for a seeded team
 * (the Kotlin BackupSnapshot mapping, pinned server-side).
 */
class KanbanBackupSheetTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithApi;

    /**
     * Switch the session user safely (guards memoize across requests in one
     * test — same rationale as InteractsWithApi::actingAsApi).
     */
    private function actingAsFresh($user): self
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user);
    }

    public function test_guests_cannot_reach_the_backup_sheet(): void
    {
        $this->getJson('/kanban/backup')->assertStatus(401);
    }

    public function test_read_only_members_are_forbidden_from_the_backup_sheet(): void
    {
        [$owner, $team] = $this->newUserWithTeam();
        $readOnly = $this->addTeamMember(\App\Models\User::factory()->create(), $team, 'Read Only');

        $this->actingAsFresh($readOnly)->getJson('/kanban/backup')->assertStatus(403);
    }

    public function test_owner_gets_the_printable_sheet_with_manifest_and_chunks(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $status = Status::factory()->onTeam($team)->create(['name' => 'In stock']);
        Item::factory()->onTeam($team)->withStatus($status)->create(['name' => 'Box A']);

        $response = $this->actingAsFresh($user)->get('/kanban/backup')->assertOk();

        $response->assertViewIs('backup-sheet');
        $response->assertViewHas('teamName', $team->name);
        $response->assertViewHas('manifest', function (string $manifest) use ($team) {
            $parts = explode('|', $manifest);

            return $parts[0] === 'MVBAK1'
                && $parts[2] === 'M'
                && $parts[3] === $team->id
                && (bool) preg_match('/^[0-9a-f]{64}$/', $parts[7]);
        });
        $response->assertViewHas('chunks', function (array $chunks) {
            if (! $chunks) {
                return false;
            }
            foreach ($chunks as $i => $chunk) {
                $parts = explode('|', $chunk['frame']);
                if ($chunk['index'] !== $i
                    || $chunk['crc'] !== $parts[5]
                    || $parts[2] !== 'C') {
                    return false;
                }
            }

            return count($chunks) === (int) explode('|', $chunks[0]['frame'])[4];
        });
        $response->assertViewHas('fingerprint', function (string $fingerprint) {
            return (bool) preg_match('/^[0-9a-f]{64}$/', $fingerprint);
        });

        /* The rendered page carries the summary, the manifest text and the
           vendored QR renderer the client needs. */
        $response->assertSee($team->name);
        $response->assertSee('js/vendor/qrcode.min.js');
        $response->assertSee($response->viewData('manifest'));
    }

    public function test_snapshot_mapping_matches_the_kotlin_backup_snapshot(): void
    {
        [$user, $team] = $this->newUserWithTeam();

        $liveStatus = Status::factory()->onTeam($team)->create(['name' => 'In stock']);
        $goneStatus = Status::factory()->onTeam($team)->create(['name' => 'Retired']);
        $goneStatus->delete();

        $liveLocation = Location::factory()->onTeam($team)->create(['name' => 'Shelf A']);
        $goneLocation = Location::factory()->onTeam($team)->create(['name' => 'Old dock']);
        $goneLocation->delete();

        $label = Label::factory()->onTeam($team)->create(['name' => 'Fragile']);

        $box = Item::factory()->onTeam($team)
            ->withStatus($liveStatus)->inLocation($liveLocation)
            ->create(['name' => 'Box A']);
        $screw = Item::factory()->onTeam($team)
            ->withStatus($liveStatus)->inLocation($liveLocation)
            ->childOf($box)
            ->create(['name' => 'Screw']);
        /* An item with neither location nor status — Kotlin non-nullables. */
        $bare = Item::factory()->onTeam($team)->create(['name' => 'Bare']);
        /* A soft-deleted item — schema v1 archives the LIVE dataset only. */
        $trash = Item::factory()->onTeam($team)->create(['name' => 'Ghost']);
        $trash->delete();

        $box->labels()->attach([$label->id]);
        ItemBarcode::create(['item_id' => $box->id, 'team_id' => $team->id, 'code' => '4006381333931', 'type' => 'EAN13']);
        Attachment::create([
            'item_id' => $box->id, 'team_id' => $team->id, 'user_id' => $user->id,
            'disk' => 'public', 'path' => "attachments/{$team->id}/{$box->id}/x.jpg",
            'original_name' => 'x.jpg', 'mime_type' => 'image/jpeg', 'size' => 12,
            'caption' => 'Under the stairs',
        ]);

        /* Foreign-team rows must never leak into the snapshot. */
        [,$foreignTeam] = $this->newUserWithTeam();
        $foreignStatus = Status::factory()->onTeam($foreignTeam)->create(['name' => 'Foreign status']);
        Item::factory()->onTeam($foreignTeam)->withStatus($foreignStatus)->create(['name' => 'Foreign box']);

        $snapshot = BackupSnapshotBuilder::build($team->fresh(), Carbon::parse('2026-09-07 10:00:00'));

        $this->assertSame(BackupCodec::SCHEMA_VERSION, $snapshot['schemaVersion']);
        $this->assertSame($team->id, $snapshot['teamId']);
        $this->assertSame('2026-09-07T10:00:00Z', $snapshot['exportedAt']);

        /* Tombstoned catalogue entries ride along (API-027 semantics). */
        $statusIds = array_column($snapshot['statuses'], 'id');
        $this->assertContains($liveStatus->id, $statusIds);
        $this->assertContains($goneStatus->id, $statusIds);
        $retired = $snapshot['statuses'][array_search($goneStatus->id, $statusIds, true)];
        $this->assertSame($goneStatus->deleted_at->toISOString(), $retired['deletedAt']);
        $inStock = $snapshot['statuses'][array_search($liveStatus->id, $statusIds, true)];
        $this->assertNull($inStock['deletedAt']);
        $this->assertNotContains($foreignStatus->id, $statusIds);

        $locationIds = array_column($snapshot['locations'], 'id');
        $this->assertEqualsCanonicalizing([$liveLocation->id, $goneLocation->id], $locationIds);
        $oldDock = $snapshot['locations'][array_search($goneLocation->id, $locationIds, true)];
        $this->assertSame($goneLocation->deleted_at->toISOString(), $oldDock['deletedAt']);

        /* Labels carry the forward-compat null order. */
        $this->assertSame([[
            'id' => $label->id, 'name' => 'Fragile', 'color' => $label->color, 'order' => null,
        ]], $snapshot['labels']);

        /* Live items only, boxes derived from parentId, null FKs as "". */
        $this->assertEqualsCanonicalizing([$box->id, $screw->id, $bare->id], array_column($snapshot['items'], 'id'));
        $byId = array_column($snapshot['items'], null, 'id');
        $this->assertTrue($byId[$box->id]['isBox']);
        $this->assertSame($box->id, $byId[$screw->id]['parentId']);
        $this->assertFalse($byId[$screw->id]['isBox']);
        $this->assertSame($liveLocation->id, $byId[$box->id]['locationId']);
        $this->assertSame($liveStatus->id, $byId[$box->id]['statusId']);
        $this->assertSame('', $byId[$bare->id]['locationId']);
        $this->assertSame('', $byId[$bare->id]['statusId']);
        $this->assertSame($team->id, $byId[$box->id]['teamId']);

        /* Cross-refs and registry rows, scoped to the live items. */
        $this->assertSame([['itemId' => $box->id, 'labelId' => $label->id]], $snapshot['itemLabels']);
        $this->assertSame([[
            'id' => ItemBarcode::first()->id, 'itemId' => $box->id,
            'code' => '4006381333931', 'type' => 'EAN13',
        ]], $snapshot['barcodes']);
        $this->assertSame([[
            'id' => Attachment::first()->id, 'itemId' => $box->id, 'caption' => 'Under the stairs',
        ]], $snapshot['attachments']);
    }

    public function test_the_seeded_team_exports_a_bundle_that_decodes_back(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $status = Status::factory()->onTeam($team)->create(['name' => 'In stock']);
        $location = Location::factory()->onTeam($team)->create(['name' => 'Shelf A']);
        $box = Item::factory()->onTeam($team)->withStatus($status)->inLocation($location)->create();
        /* Direct inserts — the factory's unique-word faker would exhaust. */
        for ($i = 0; $i < 250; $i++) {
            Item::create([
                'name' => "Generated screw $i",
                'team_id' => $team->id,
                'parent_id' => $box->id,
                'location_id' => $location->id,
                'status_id' => $status->id,
            ]);
        }

        $exportedAt = Carbon::parse('2026-09-07 10:00:00');
        $snapshot = BackupSnapshotBuilder::build($team->fresh(), $exportedAt);
        $bundle = BackupCodec::export($snapshot);

        /* Concatenate the frames the way a scanner would, then decode. */
        $this->assertGreaterThan(1, $bundle['chunkCount']);
        $payload = '';
        foreach ($bundle['frames'] as $frame) {
            $payload .= base64_decode(explode('|', $frame)[6], true);
        }

        $this->assertSame($bundle['fingerprint'], hash('sha256', $payload));
        $decoded = json_decode(gzdecode($payload), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($snapshot, $decoded);
    }
}
