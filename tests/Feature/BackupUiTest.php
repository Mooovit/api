<?php

namespace Tests\Feature;

use App\Models\Backup;
use App\Models\Item;
use App\Models\Status;
use App\Models\Team;
use App\Models\TeamS3Config;
use App\Models\User;
use App\Services\BackupService;
use App\Services\S3BackupClient;
use App\Services\S3BackupClientFactory;
use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\Concerns\InteractsWithApi;
use Tests\TestCase;

/**
 * The management UI, team-level half (session-auth web routes): the
 * backups page (list safe metadata, create now, download, delete, compare
 * two savepoints) and the S3 offload page (probed credential upsert with
 * the secret encrypted at rest and never rendered, retention rules, bucket
 * listing). Authorization mirrors the API: item:read to view/download,
 * item:write to mutate.
 */
class BackupUiTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithApi;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    /**
     * Session authentication for the web routes, safe across user switches
     * (see BackupScheduleTest for the guard-flushing rationale).
     */
    private function actingAsWeb($user): self
    {
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');

        return $this->actingAs($user);
    }

    /**
     * A backup row + zip on the fake local disk, without going through the
     * service (so the create path stays under test on its own).
     */
    private function makeBackup(Team $team, User $user, string $name = 'b'): Backup
    {
        $id = (string) Str::uuid();
        $path = "backups/{$team->id}/{$id}.zip";

        Storage::disk('local')->put($path, "fake zip {$name}");

        return Backup::create([
            'id' => $id,
            'team_id' => $team->id,
            'user_id' => $user->id,
            'disk' => 'local',
            'path' => $path,
            'size' => 1234,
            'item_count' => 1,
            'location_count' => 2,
            'status_count' => 3,
            'label_count' => 4,
            'remote' => false,
        ]);
    }

    public function test_backups_page_lists_safe_metadata_only(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $backup = $this->makeBackup($team, $user, 'listed');

        $response = $this->actingAsWeb($user)
            ->get("/teams/{$team->id}/backups")
            ->assertOk()
            ->assertInertia(function ($page) use ($team, $backup) {
                $page->component('Backups/Index')
                    ->where('team.id', $team->id)
                    ->where('retention', 7)
                    ->where('s3_configured', false)
                    ->where('backups.0.id', $backup->id)
                    ->where('backups.0.size', 1234)
                    ->where('backups.0.item_count', 1)
                    ->where('backups.0.remote', false)
                    ->has('backups.0.download_url');
            });

        /* The storage path never leaves the server */
        $this->assertStringNotContainsString($backup->path, $response->getContent());
    }

    public function test_create_download_delete_round_trip(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        Item::factory()->onTeam($team)->create(['name' => 'Pallet']);

        $revisionBefore = $team->fresh()->revision;

        /* Create through the shared service (dump + row + retention) */
        $this->actingAsWeb($user)
            ->post("/teams/{$team->id}/backups")
            ->assertRedirect("/teams/{$team->id}/backups");

        $this->assertSame(1, Backup::where('team_id', $team->id)->count());
        $backup = Backup::where('team_id', $team->id)->first();
        $this->assertSame(1, $backup->item_count);
        Storage::disk('local')->assertExists($backup->path);
        $this->assertSame($revisionBefore + 1, $team->fresh()->revision);

        /* Download streams the zip with a zip content type */
        $downloaded = $this->actingAsWeb($user)
            ->get("/teams/{$team->id}/backups/{$backup->id}/download")
            ->assertOk();
        $this->assertSame('application/zip', $downloaded->headers->get('content-type'));

        /* Delete removes the row, the file, and bumps the revision again */
        $revisionBeforeDelete = $team->fresh()->revision;
        $this->actingAsWeb($user)
            ->delete("/teams/{$team->id}/backups/{$backup->id}")
            ->assertRedirect("/teams/{$team->id}/backups");

        $this->assertNull(Backup::find($backup->id));
        Storage::disk('local')->assertMissing($backup->path);
        $this->assertSame($revisionBeforeDelete + 1, $team->fresh()->revision);
    }

    public function test_compare_renders_the_page_with_the_diff(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $service = app(BackupService::class);

        Item::factory()->onTeam($team)->create(['name' => 'Kept']);
        $base = $service->createForTeam($team, $user);

        Item::factory()->onTeam($team)->create(['name' => 'Added later']);
        $kept = Item::where('name', 'Kept')->first();
        $kept->delete(); /* soft delete → tombstone excluded from dumps → removed */
        $target = $service->createForTeam($team, $user);

        $this->actingAsWeb($user)
            ->post("/teams/{$team->id}/backups/compare", [
                'base_id' => $base->id,
                'target_id' => $target->id,
            ])
            ->assertOk()
            ->assertInertia(function ($page) use ($base, $target, $kept) {
                $page->component('Backups/Index')
                    ->has('compare')
                    ->where('compare.items.counts.added', 1)
                    ->where('compare.items.counts.changed', 0)
                    ->where('compare.items.counts.removed', 1)
                    ->where('compare.items.added.0.name', 'Added later')
                    ->where('compare.items.removed.0.id', $kept->id)
                    ->where('compare.locations.counts.unchanged', 0)
                    ->missing('compare.items.added.0.path');
            });

        /* Both backups must belong to the URL team */
        [$foreign, $foreignTeam] = $this->newUserWithTeam();
        $foreignBackup = $this->makeBackup($foreignTeam, $foreign);
        $this->actingAsWeb($user)
            ->post("/teams/{$team->id}/backups/compare", [
                'base_id' => $base->id,
                'target_id' => $foreignBackup->id,
            ])
            ->assertNotFound();
    }

    public function test_permissions_read_writes_delete_and_foreign_team(): void
    {
        [$owner, $team] = $this->newUserWithTeam();
        [$ro] = $this->newUserWithTeam();
        $this->addTeamMember($ro, $team, 'Read Only');
        [$stranger] = $this->newUserWithTeam();

        $backup = $this->makeBackup($team, $owner);

        /* Read-only member: page + download + compare ok, writes 403 */
        $this->actingAsWeb($ro)
            ->get("/teams/{$team->id}/backups")
            ->assertOk();
        $this->actingAsWeb($ro)
            ->get("/teams/{$team->id}/backups/{$backup->id}/download")
            ->assertOk();
        $this->actingAsWeb($ro)
            ->post("/teams/{$team->id}/backups")
            ->assertStatus(403);
        $this->actingAsWeb($ro)
            ->delete("/teams/{$team->id}/backups/{$backup->id}")
            ->assertStatus(403);

        /* A non-member (valid session, foreign team) is 403 everywhere */
        $this->actingAsWeb($stranger)
            ->get("/teams/{$team->id}/backups")
            ->assertStatus(403);
        $this->actingAsWeb($stranger)
            ->get("/teams/{$team->id}/backups/{$backup->id}/download")
            ->assertStatus(403);

        $this->assertSame(1, Backup::where('team_id', $team->id)->count());

        /* The owner can still delete */
        $this->actingAsWeb($owner)
            ->delete("/teams/{$team->id}/backups/{$backup->id}")
            ->assertRedirect();
        $this->assertSame(0, Backup::where('team_id', $team->id)->count());
    }

    public function test_guest_is_redirected_to_login(): void
    {
        [$user, $team] = $this->newUserWithTeam();

        $this->get("/teams/{$team->id}/backups")->assertRedirect(route('login'));
        $this->post("/teams/{$team->id}/backups")->assertRedirect(route('login'));
        $this->get("/teams/{$team->id}/backups/s3")->assertRedirect(route('login'));
        $this->get("/teams/{$team->id}/backups/cold-storage/pdf")->assertRedirect(route('login'));
    }

    /* -------------------------- Cold-storage -------------------------- */

    public function test_cold_storage_pdf_streams_a_valid_pdf(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $status = Status::factory()->onTeam($team)->create(['name' => 'In stock']);
        Item::factory()->onTeam($team)->withStatus($status)->create(['name' => 'Box A']);

        $response = $this->actingAsWeb($user)
            ->get("/teams/{$team->id}/backups/cold-storage/pdf")
            ->assertOk();

        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString(
            'filename="cold-storage-' . $team->id,
            (string) $response->headers->get('Content-Disposition')
        );

        /* A tiny dataset = 1 chunk → summary page + 1 chunk page */
        $pdf = $response->getContent();
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertSame(2, $this->pdfPageCount($pdf));
    }

    public function test_cold_storage_pdf_permissions(): void
    {
        [$owner, $team] = $this->newUserWithTeam();
        [$ro] = $this->newUserWithTeam();
        $this->addTeamMember($ro, $team, 'Read Only');
        [$stranger] = $this->newUserWithTeam();

        /* Read-only member: 403 — the archive exposes the whole dataset */
        $this->actingAsWeb($ro)
            ->get("/teams/{$team->id}/backups/cold-storage/pdf")
            ->assertStatus(403);

        /* A non-member (valid session, foreign team) is 403 */
        $this->actingAsWeb($stranger)
            ->get("/teams/{$team->id}/backups/cold-storage/pdf")
            ->assertStatus(403);

        /* The owner gets the sheet */
        $this->actingAsWeb($owner)
            ->get("/teams/{$team->id}/backups/cold-storage/pdf")
            ->assertOk();
    }

    /**
     * The page count from the PDF's /Pages object.
     */
    private function pdfPageCount(string $pdf): int
    {
        $this->assertSame(1, preg_match('/\/Count (\d+)/', $pdf, $m));

        return (int) $m[1];
    }

    /* ------------------------------- S3 ------------------------------- */

    public function test_s3_page_shows_rules_without_config(): void
    {
        [$user, $team] = $this->newUserWithTeam();

        $this->actingAsWeb($user)
            ->get("/teams/{$team->id}/backups/s3")
            ->assertOk()
            ->assertInertia(function ($page) use ($team) {
                $page->component('Backups/S3')
                    ->where('team.id', $team->id)
                    ->where('retention', 7)
                    ->where('config', null)
                    ->where('objects', null)
                    ->where('lifecycle', null)
                    ->where('bucket_error', null);
            });
    }

    public function test_s3_store_probes_before_persisting_and_encrypts(): void
    {
        [$user, $team] = $this->newUserWithTeam();

        $client = Mockery::mock(S3BackupClient::class);
        $client->shouldReceive('headBucket')->once()->andReturnNull();
        $factory = Mockery::mock(S3BackupClientFactory::class);
        /* twice: once for the POST probe, once for the page render below */
        $factory->shouldReceive('forConfig')->twice()->andReturn($client);
        $this->instance(S3BackupClientFactory::class, $factory);

        $this->actingAsWeb($user)
            ->post("/teams/{$team->id}/backups/s3", [
                'bucket' => 'team-bucket',
                'region' => 'eu-west-3',
                'access_key' => 'AKIATEST',
                'secret_key' => 'topsecret',
            ])
            ->assertRedirect("/teams/{$team->id}/backups/s3");

        /* Validations still apply */
        $this->actingAsWeb($user)
            ->post("/teams/{$team->id}/backups/s3", ['bucket' => 'x'])
            ->assertSessionHasErrors(['region', 'access_key', 'secret_key']);

        $config = TeamS3Config::where('team_id', $team->id)->first();
        $this->assertNotNull($config);
        $this->assertSame('team-bucket', $config->bucket);
        $this->assertSame("backups/{$team->id}", $config->prefix);

        /* Encrypted at rest, decryptable through the cast */
        $raw = DB::table('team_s3_configs')->where('team_id', $team->id)->value('secret_key');
        $this->assertNotSame('topsecret', $raw);
        $this->assertSame('topsecret', $config->fresh()->secret_key);

        /* Never rendered back */
        $this->assertStringNotContainsString(
            'topsecret',
            $this->actingAsWeb($user)->get("/teams/{$team->id}/backups/s3")->getContent()
        );
    }

    public function test_s3_probe_failure_persists_nothing(): void
    {
        [$user, $team] = $this->newUserWithTeam();

        $client = Mockery::mock(S3BackupClient::class);
        $client->shouldReceive('headBucket')->once()->andThrow(
            new AwsException('bucket unreachable', Mockery::mock(CommandInterface::class))
        );
        $factory = Mockery::mock(S3BackupClientFactory::class);
        $factory->shouldReceive('forConfig')->once()->andReturn($client);
        $this->instance(S3BackupClientFactory::class, $factory);

        $this->actingAsWeb($user)
            ->post("/teams/{$team->id}/backups/s3", [
                'bucket' => 'team-bucket',
                'region' => 'eu-west-3',
                'access_key' => 'AKIATEST',
                'secret_key' => 'topsecret',
            ])
            ->assertSessionHasErrors('bucket');

        $this->assertSame(0, TeamS3Config::where('team_id', $team->id)->count());
    }

    public function test_s3_show_lists_bucket_objects_and_lifecycle(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        TeamS3Config::create([
            'team_id' => $team->id,
            'bucket' => 'team-bucket',
            'region' => 'eu-west-3',
            'access_key' => 'AKIATEST',
            'secret_key' => 'topsecret',
        ]);

        $client = Mockery::mock(S3BackupClient::class);
        $client->shouldReceive('listObjects')->once()->andReturn([
            [
                'key' => "backups/{$team->id}/abc.zip",
                'size' => 2048,
                'last_modified' => \Illuminate\Support\Carbon::parse('2026-09-01 12:00:00'),
            ],
        ]);
        $client->shouldReceive('getLifecycle')->once()->andReturn([
            ['ID' => 'expire-30d', 'Status' => 'Enabled', 'Expiration' => ['Days' => 30]],
        ]);
        $factory = Mockery::mock(S3BackupClientFactory::class);
        $factory->shouldReceive('forConfig')->once()->andReturn($client);
        $this->instance(S3BackupClientFactory::class, $factory);

        $response = $this->actingAsWeb($user)
            ->get("/teams/{$team->id}/backups/s3")
            ->assertOk();

        $response->assertInertia(function ($page) use ($team) {
            $page->component('Backups/S3')
                ->where('config.bucket', 'team-bucket')
                ->where('config.region', 'eu-west-3')
                ->where('objects.0.key', "backups/{$team->id}/abc.zip")
                ->where('objects.0.size', 2048)
                ->where('lifecycle.0.ID', 'expire-30d')
                ->where('bucket_error', null);
        });

        /* The page shape carries no secret (the model hides it anyway) */
        $this->assertStringNotContainsString('topsecret', $response->getContent());
    }

    public function test_s3_bucket_upstream_error_is_displayed_not_fatal(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        TeamS3Config::create([
            'team_id' => $team->id,
            'bucket' => 'team-bucket',
            'region' => 'eu-west-3',
            'access_key' => 'AKIATEST',
            'secret_key' => 'topsecret',
        ]);

        $client = Mockery::mock(S3BackupClient::class);
        $client->shouldReceive('listObjects')->once()->andThrow(
            new AwsException('network down', Mockery::mock(CommandInterface::class))
        );
        $factory = Mockery::mock(S3BackupClientFactory::class);
        $factory->shouldReceive('forConfig')->once()->andReturn($client);
        $this->instance(S3BackupClientFactory::class, $factory);

        $this->actingAsWeb($user)
            ->get("/teams/{$team->id}/backups/s3")
            ->assertOk()
            ->assertInertia(function ($page) {
                $page->component('Backups/S3')
                    ->where('bucket_error', 'network down')
                    ->where('objects', null);
            });
    }

    public function test_s3_destroy_removes_the_config(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        TeamS3Config::create([
            'team_id' => $team->id,
            'bucket' => 'team-bucket',
            'region' => 'eu-west-3',
            'access_key' => 'AKIATEST',
            'secret_key' => 'topsecret',
        ]);

        $this->actingAsWeb($user)
            ->delete("/teams/{$team->id}/backups/s3")
            ->assertRedirect("/teams/{$team->id}/backups/s3");

        $this->assertSame(0, TeamS3Config::where('team_id', $team->id)->count());
    }

    public function test_read_only_member_cannot_save_s3(): void
    {
        [$owner, $team] = $this->newUserWithTeam();
        [$ro] = $this->newUserWithTeam();
        $this->addTeamMember($ro, $team, 'Read Only');

        /* Read-only can view the page */
        $this->actingAsWeb($ro)
            ->get("/teams/{$team->id}/backups/s3")
            ->assertOk();

        /* But not save or remove the config */
        $this->actingAsWeb($ro)
            ->post("/teams/{$team->id}/backups/s3", [
                'bucket' => 'team-bucket',
                'region' => 'eu-west-3',
                'access_key' => 'AKIATEST',
                'secret_key' => 'topsecret',
            ])
            ->assertStatus(403);
        $this->actingAsWeb($ro)
            ->delete("/teams/{$team->id}/backups/s3")
            ->assertStatus(403);

        $this->assertSame(0, TeamS3Config::where('team_id', $team->id)->count());
    }
}
