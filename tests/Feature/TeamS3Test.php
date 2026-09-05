<?php

namespace Tests\Feature;

use App\Models\Backup;
use App\Models\Item;
use App\Models\Team;
use App\Models\TeamS3Config;
use App\Services\S3BackupClient;
use App\Services\S3BackupClientFactory;
use Aws\Command;
use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Aws\Result;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\MockInterface;
use Tests\Concerns\InteractsWithApi;
use Tests\TestCase;

/**
 * API-021: per-team S3 offload — credential management (probed with a
 * HeadBucket before anything is persisted, secret encrypted at rest and
 * never serialized), backup copy-off on create, bucket listing and the
 * computed retention rules. The S3 seam (S3BackupClient) is mocked —
 * no live network in tests.
 */
class TeamS3Test extends TestCase
{
    use RefreshDatabase;
    use InteractsWithApi;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    /**
     * Store a config row for the team directly (bypasses the probe).
     *
     * @param Team $team
     * @param array $overrides
     * @return TeamS3Config
     */
    private function configFor(Team $team, array $overrides = []): TeamS3Config
    {
        return $team->s3Config()->create(array_merge([
            'bucket' => 'team-bucket',
            'region' => 'eu-west-3',
            'access_key' => 'AKIAMOOVIT',
            'secret_key' => 's3cr3t',
            'prefix' => "backups/{$team->id}",
        ], $overrides));
    }

    /**
     * Bind a fake factory that returns $client for every forConfig() call.
     *
     * @param S3BackupClient|MockInterface $client
     * @param int|null $times exact expected forConfig calls (null = any)
     * @return void
     */
    private function fakeFactory($client, ?int $times = null): void
    {
        $factory = Mockery::mock(S3BackupClientFactory::class);
        if ($times === null) {
            $factory->shouldReceive('forConfig')->andReturn($client);
        } else {
            $factory->shouldReceive('forConfig')->times($times)->andReturn($client);
        }

        $this->instance(S3BackupClientFactory::class, $factory);
    }

    /**
     * @return MockInterface|S3BackupClient
     */
    private function mockClient()
    {
        return Mockery::mock(S3BackupClient::class);
    }

    public function test_config_round_trip_upsert_read_delete(): void
    {
        [$user, $team] = $this->newUserWithTeam();

        $client = $this->mockClient();
        $client->shouldReceive('headBucket')->twice(); // probe on each upsert
        $this->fakeFactory($client, 2);

        $payload = [
            'bucket' => 'team-bucket',
            'region' => 'eu-west-3',
            'access_key' => 'AKIAMOOVIT',
            'secret_key' => 's3cr3t',
            'endpoint' => 'https://minio.example',
        ];

        $first = $this->actingAsApi($user, ['item:write'])
            ->postJson('/api/team/s3', $payload)
            ->assertOk()
            ->json();

        /* The safe shape: no secret, no access key — ever */
        $this->assertTrue($first['configured']);
        $this->assertSame('team-bucket', $first['bucket']);
        $this->assertSame('eu-west-3', $first['region']);
        $this->assertSame('https://minio.example', $first['endpoint']);
        $this->assertSame("backups/{$team->id}", $first['prefix']);
        $this->assertArrayNotHasKey('secret_key', $first);
        $this->assertArrayNotHasKey('access_key', $first);

        /* Encrypted at rest: the raw column is not the plaintext */
        $raw = DB::table('team_s3_configs')->where('team_id', $team->id)->value('secret_key');
        $this->assertIsString($raw);
        $this->assertNotSame('s3cr3t', $raw);
        $this->assertSame('s3cr3t', Crypt::decryptString($raw));

        /* Upsert: same team → still one row, values replaced */
        $second = $this->actingAsApi($user, ['item:write'])
            ->postJson('/api/team/s3', array_merge($payload, [
                'bucket' => 'team-bucket-2',
                'endpoint' => null,
            ]))
            ->assertOk()
            ->json();

        $this->assertSame('team-bucket-2', $second['bucket']);
        $this->assertNull($second['endpoint']);
        $this->assertSame(1, DB::table('team_s3_configs')->where('team_id', $team->id)->count());

        /* Read-back: the same safe shape */
        $this->actingAsApi($user, ['item:write'])
            ->getJson('/api/team/s3')
            ->assertOk()
            ->assertJson($second)
            ->assertJsonMissing(['secret_key' => 's3cr3t']);

        /* Drop it: gone from the DB, read-back is the 422 signal */
        $this->actingAsApi($user, ['item:write'])
            ->deleteJson('/api/team/s3')
            ->assertOk()
            ->assertJsonPath('success', 'success');
        $this->assertDatabaseMissing('team_s3_configs', ['team_id' => $team->id]);
        $this->actingAsApi($user, ['item:write'])
            ->getJson('/api/team/s3')
            ->assertStatus(422)
            ->assertJson(['s3' => ['No S3 bucket configured for this team.']]);
    }

    public function test_probe_failure_returns_422_and_persists_nothing(): void
    {
        [$user, $team] = $this->newUserWithTeam();

        $client = $this->mockClient();
        $client->shouldReceive('headBucket')->once()
            ->andThrow(new AwsException('bucket unreachable', new Command('HeadBucket')));
        $this->fakeFactory($client, 1);

        /* Dead credentials are a user-input problem: 422 on `bucket` */
        $this->actingAsApi($user, ['item:write'])
            ->postJson('/api/team/s3', [
                'bucket' => 'team-bucket',
                'region' => 'eu-west-3',
                'access_key' => 'AKIAMOOVIT',
                'secret_key' => 's3cr3t',
            ])
            ->assertStatus(422)
            ->assertJson(['bucket' => ['bucket unreachable']]);

        $this->assertDatabaseMissing('team_s3_configs', ['team_id' => $team->id]);
    }

    public function test_store_validates_the_required_fields(): void
    {
        [$user] = $this->newUserWithTeam();

        $this->actingAsApi($user, ['item:write'])
            ->postJson('/api/team/s3', ['region' => 'eu-west-3', 'access_key' => 'k', 'secret_key' => 's'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['bucket']);

        $this->actingAsApi($user, ['item:write'])
            ->postJson('/api/team/s3', ['bucket' => 'b', 'region' => 'r', 'access_key' => 'k'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['secret_key']);

        $this->assertDatabaseCount('team_s3_configs', 0);
    }

    public function test_backup_create_copies_to_bucket_marks_remote_and_lists(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $this->configFor($team, ['prefix' => 'offload/']);
        Item::factory()->onTeam($team)->create(['name' => 'Alpha']);

        /* Partial mock: the REAL listObjects runs (pagination + newest-first
           sort are pinned) on a canned paginator; put is stubbed. */
        $object = fn (string $key, int $size, string $modified): array => [
            'Key' => $key,
            'Size' => $size,
            'LastModified' => new DateTimeImmutable($modified),
        ];
        $sdk = Mockery::mock(S3Client::class);
        $sdk->shouldReceive('getPaginator')->once()->with('ListObjectsV2', [
            'Bucket' => 'team-bucket',
            'Prefix' => 'offload/',
        ])->andReturn([
            /* The older object is served first — the seam must sort */
            new Result(['Contents' => [$object('offload/older.zip', 10, '2026-09-01 08:00:00')]]),
            new Result(['Contents' => [$object('offload/newer.zip', 20, '2026-09-05 09:30:00')]]),
        ]);
        $client = Mockery::mock(S3BackupClient::class, [$sdk, 'team-bucket'])
            ->makePartial();

        $copied = [];
        $client->shouldReceive('put')->once()->andReturnUsing(
            function (string $key, string $bytes) use (&$copied): void {
                $copied[$key] = $bytes;
            }
        );

        $this->fakeFactory($client, 2); // copy-off + bucket listing

        $created = $this->actingAsApi($user, ['item:write'])
            ->postJson('/api/backups')
            ->assertStatus(201)
            ->json();

        /* Local copy kept, row marked remote */
        $path = "backups/{$team->id}/{$created['id']}.zip";
        Storage::disk('local')->assertExists($path);
        $this->assertDatabaseHas('backups', ['id' => $created['id'], 'remote' => true]);

        /* The very same bytes landed in the bucket under the same key */
        $this->assertSame([$path => Storage::disk('local')->get($path)], $copied);

        /* Listing: keys + size + last_modified, newest first */
        $rows = $this->actingAsApi($user, ['item:read'])
            ->getJson('/api/backups/bucket')
            ->assertOk()
            ->json();

        $this->assertSame('team-bucket', $rows['bucket']);
        $this->assertSame('offload/', $rows['prefix']);
        $this->assertSame('offload/newer.zip', $rows['objects'][0]['key']);
        $this->assertSame('offload/older.zip', $rows['objects'][1]['key']);
        $this->assertSame(20, $rows['objects'][0]['size']);
        $this->assertStringContainsString('2026-09-05', $rows['objects'][0]['last_modified']);
    }

    public function test_copy_failure_maps_to_502_without_row_or_local_file(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $this->configFor($team);
        Item::factory()->onTeam($team)->create(['name' => 'Alpha']);

        $client = $this->mockClient();
        $client->shouldReceive('put')->once()
            ->andThrow(new AwsException('S3 is down', new Command('PutObject')));
        $this->fakeFactory($client, 1);

        /* Upstream trouble, not a validation error → 502, nothing recorded */
        $this->actingAsApi($user, ['item:write'])
            ->postJson('/api/backups')
            ->assertStatus(502)
            ->assertJsonPath('error', 'S3 copy failed: S3 is down');

        $this->assertDatabaseCount('backups', 0);
        $this->assertSame([], Storage::disk('local')->allFiles("backups/{$team->id}"));
    }

    public function test_without_s3_backup_create_is_unchanged_and_bucket_list_is_422(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        Item::factory()->onTeam($team)->create(['name' => 'Alpha']);

        $created = $this->actingAsApi($user, ['item:write'])
            ->postJson('/api/backups')
            ->assertStatus(201)
            ->json();

        $this->assertDatabaseHas('backups', ['id' => $created['id'], 'remote' => false]);
        Storage::disk('local')->assertExists("backups/{$team->id}/{$created['id']}.zip");

        $this->actingAsApi($user, ['item:read'])
            ->getJson('/api/backups/bucket')
            ->assertStatus(422)
            ->assertJson(['s3' => ['No S3 bucket configured for this team.']]);

        $rules = $this->actingAsApi($user, ['item:read'])
            ->getJson('/api/team/s3/rules')
            ->assertOk()
            ->json();

        $this->assertSame(7, $rules['local_retention']);
        $this->assertFalse($rules['s3_configured']);
        $this->assertNull($rules['s3_retention']);
        $this->assertNull($rules['lifecycle']);
        $this->assertStringContainsString('7', $rules['note']);
    }

    public function test_rules_with_s3_show_the_bucket_lifecycle(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $this->configFor($team);

        $client = $this->mockClient();
        $client->shouldReceive('getLifecycle')->once()->andReturn([
            ['ID' => 'expire-after-30d', 'Status' => 'Enabled'],
        ]);
        $this->fakeFactory($client, 1);

        $rules = $this->actingAsApi($user, ['item:read'])
            ->getJson('/api/team/s3/rules')
            ->assertOk()
            ->json();

        $this->assertTrue($rules['s3_configured']);
        $this->assertSame('per bucket lifecycle', $rules['s3_retention']);
        $this->assertSame(7, $rules['local_retention']);
        $this->assertSame(
            [['ID' => 'expire-after-30d', 'Status' => 'Enabled']],
            $rules['lifecycle']
        );
    }

    public function test_rules_treat_an_unavailable_lifecycle_as_null_not_an_error(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $this->configFor($team);

        /* Partial mock: the REAL S3BackupClient::getLifecycle runs against an
           SDK client that throws (e.g. AccessDenied on restricted IAM) — the
           seam must swallow it into `null`, not let it bubble up. */
        $sdk = Mockery::mock(S3Client::class);
        $sdk->shouldReceive('getBucketLifecycleConfiguration')->once()
            ->andThrow(new AwsException('AccessDenied', new Command('GetBucketLifecycleConfiguration')));
        $client = Mockery::mock(S3BackupClient::class, [$sdk, 'team-bucket'])->makePartial();

        $this->fakeFactory($client, 1);

        $rules = $this->actingAsApi($user, ['item:read'])
            ->getJson('/api/team/s3/rules')
            ->assertOk()
            ->json();

        $this->assertTrue($rules['s3_configured']);
        $this->assertNull($rules['lifecycle']);
    }

    public function test_rotation_with_s3_prunes_local_but_keeps_bucket_copies(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $this->configFor($team);
        Item::factory()->onTeam($team)->create(['name' => 'Alpha']);

        $client = $this->mockClient();
        $bucketKeys = [];
        $client->shouldReceive('put')->times(8)->andReturnUsing(
            function (string $key) use (&$bucketKeys): void {
                $bucketKeys[] = $key;
            }
        );
        $this->fakeFactory($client, 8);

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

        /* Local: last 7 rows and files; the oldest is pruned */
        $this->assertSame(7, Backup::where('team_id', $team->id)->count());
        Storage::disk('local')->assertMissing("backups/{$team->id}/{$ids[0]}.zip");
        Storage::disk('local')->assertExists("backups/{$team->id}/{$ids[1]}.zip");

        /* Bucket: all 8 copies are still there — rotation is local-only,
           the bucket lifecycle is the user's domain */
        $this->assertCount(8, $bucketKeys);
        $this->assertContains("backups/{$team->id}/{$ids[0]}.zip", $bucketKeys);
    }

    public function test_permission_matrix(): void
    {
        [$owner, $team] = $this->newUserWithTeam();
        $this->configFor($team);

        [$ro] = $this->newUserWithTeam();
        $this->addTeamMember($ro, $team, 'Read Only');

        /* Read-only member: config management is item:write — all denied */
        $roWrites = ['bucket' => 'b', 'region' => 'r', 'access_key' => 'k', 'secret_key' => 's'];
        $this->actingAsApi($ro, ['item:read'])->postJson('/api/team/s3', $roWrites)->assertStatus(403);
        $this->actingAsApi($ro, ['item:read'])->getJson('/api/team/s3')->assertStatus(403);
        $this->actingAsApi($ro, ['item:read'])->deleteJson('/api/team/s3')->assertStatus(403);

        /* ...but the read endpoints are item:read — allowed */
        $client = $this->mockClient();
        $client->shouldReceive('listObjects')->once()->andReturn([
            ['key' => "backups/{$team->id}/x.zip", 'size' => 1, 'last_modified' => Carbon::parse('2026-09-05 09:00:00')],
        ]);
        $client->shouldReceive('getLifecycle')->once()->andReturnNull();
        $this->fakeFactory($client, 2);

        $this->actingAsApi($ro, ['item:read'])
            ->getJson('/api/backups/bucket')
            ->assertOk()
            ->assertJsonPath('bucket', 'team-bucket');
        $this->actingAsApi($ro, ['item:read'])
            ->getJson('/api/team/s3/rules')
            ->assertOk()
            ->assertJsonPath('s3_configured', true);

        /* Full-permission member but a token without item:write */
        $this->actingAsApi($owner, ['item:read'])->postJson('/api/team/s3', $roWrites)->assertStatus(403);

        /* A foreign user only ever reaches their own (unconfigured) team
           scope — team-scoped by design, there is no {team} parameter */
        [$foreign] = $this->newUserWithTeam();
        $this->actingAsApi($foreign, ['item:write'])->getJson('/api/team/s3')->assertStatus(422);
        $this->actingAsApi($foreign, ['item:read'])
            ->getJson('/api/backups/bucket')
            ->assertStatus(422);
        $this->actingAsApi($foreign, ['item:read'])
            ->getJson('/api/team/s3/rules')
            ->assertOk()
            ->assertJsonPath('s3_configured', false);

        /* Unauthenticated — flush the sticky Authorization default header
           (withToken persists across requests in one test) and the memoized
           guard user before hitting the endpoints with no token at all */
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/team/s3')->assertStatus(401);
        $this->postJson('/api/team/s3')->assertStatus(401);
        $this->deleteJson('/api/team/s3')->assertStatus(401);
        $this->getJson('/api/backups/bucket')->assertStatus(401);
        $this->getJson('/api/team/s3/rules')->assertStatus(401);
    }
}
