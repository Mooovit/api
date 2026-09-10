<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Status;
use App\Support\Backup\BackupSnapshotBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithApi;
use Tests\TestCase;

/**
 * API-030 — GET api/backups/cold-storage: the API-029 MVBAK1 bundle
 * (manifest + chunk frames) as JSON, behind the savepoint-backup auth
 * matrix (`item:write` on the effective team).
 */
class ColdStorageBackupApiTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithApi;

    public function test_guests_are_rejected(): void
    {
        $this->getJson('/api/backups/cold-storage')->assertStatus(401);
    }

    public function test_a_token_without_item_write_ability_is_forbidden(): void
    {
        [$user] = $this->newUserWithTeam();

        $this->actingAsApi($user, ['item:read'])
            ->getJson('/api/backups/cold-storage')
            ->assertStatus(403);
    }

    public function test_read_only_members_are_forbidden(): void
    {
        [, $team] = $this->newUserWithTeam();
        $readOnly = $this->addTeamMember(\App\Models\User::factory()->create(), $team, 'Read Only');

        $this->actingAsApi($readOnly, ['*'])
            ->getJson('/api/backups/cold-storage')
            ->assertStatus(403);
    }

    public function test_owner_gets_the_mvbak1_bundle_as_json(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $status = Status::factory()->onTeam($team)->create(['name' => 'In stock']);
        Item::factory()->onTeam($team)->withStatus($status)->create(['name' => 'Box A']);

        /* Foreign-team rows must never surface in the decoded snapshot. */
        [,$foreignTeam] = $this->newUserWithTeam();
        $foreignStatus = Status::factory()->onTeam($foreignTeam)->create(['name' => 'Foreign']);

        $json = $this->actingAsApi($user, ['item:write'])
            ->getJson('/api/backups/cold-storage')
            ->assertOk()
            ->assertJsonStructure([
                'schemaVersion', 'teamId', 'exportedAt', 'manifest',
                'frames', 'chunkCount', 'totalPayloadBytes', 'fingerprint',
            ])
            ->json();

        $this->assertSame(1, $json['schemaVersion']);
        $this->assertSame($team->id, $json['teamId']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $json['exportedAt']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $json['fingerprint']);

        /* Manifest + frames follow the MVBAK1 grammar, counts line up. */
        $manifestParts = explode('|', $json['manifest']);
        $this->assertSame('MVBAK1', $manifestParts[0]);
        $this->assertSame('M', $manifestParts[2]);
        $this->assertSame($team->id, $manifestParts[3]);
        $this->assertSame($json['fingerprint'], $manifestParts[7]);
        $this->assertSame((string) $json['chunkCount'], $manifestParts[5]);
        $this->assertGreaterThanOrEqual(1, $json['chunkCount']);
        $this->assertCount($json['chunkCount'], $json['frames']);
        foreach ($json['frames'] as $index => $frame) {
            $parts = explode('|', $frame);
            $this->assertSame('MVBAK1', $parts[0]);
            $this->assertSame('C', $parts[2]);
            $this->assertSame((string) $index, $parts[3]);
            $this->assertNotFalse(base64_decode($parts[6], true));
        }

        /* The decoded payload is EXACTLY what the builder emits for this
           team at that exportedAt — foreign team absent. */
        $payload = '';
        foreach ($json['frames'] as $frame) {
            $payload .= base64_decode(explode('|', $frame)[6], true);
        }
        $this->assertSame($json['fingerprint'], hash('sha256', $payload));
        $decoded = json_decode(gzdecode($payload), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(
            BackupSnapshotBuilder::build($team->fresh(), Carbon::parse($json['exportedAt'])),
            $decoded
        );
        $this->assertNotContains($foreignStatus->id, array_column($decoded['statuses'], 'id'));
    }
}
