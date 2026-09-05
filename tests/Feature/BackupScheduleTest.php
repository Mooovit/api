<?php

namespace Tests\Feature;

use App\Models\Backup;
use App\Models\BackupSchedule;
use App\Models\Item;
use App\Models\User;
use App\Services\BackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\Concerns\InteractsWithApi;
use Tests\TestCase;

/**
 * API-020: per-team auto-backup schedules — server-UI configuration (Inertia
 * web routes, one schedule per team, POST upsert / DELETE remove) and the
 * `moovit:auto-backups` scheduler command that creates due savepoints through
 * the API-018 service (retention included) and advances last/next run.
 *
 * `next_run_at` is computed from now at configuration/run time — never from a
 * fixed anchor — and month edges follow Carbon's addMonth() overflow (Jan 31
 * monthly → Mar 3, not Feb 28).
 */
class BackupScheduleTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithApi;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * A persisted schedule, due at $nextRun (enabled by default).
     */
    private function makeSchedule($team, $user, string $frequency, Carbon $nextRun, bool $enabled = true): BackupSchedule
    {
        return BackupSchedule::create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'frequency' => $frequency,
            'enabled' => $enabled,
            'next_run_at' => $nextRun,
        ]);
    }

    /**
     * Session authentication for the web routes, safe across user switches.
     *
     * Plain actingAs breaks after the first request: the auth middleware
     * leaves `sanctum` as the default driver, so the NEXT actingAs set-user
     * lands directly (raw, no TransientToken) on the memoizing sanctum
     * RequestGuard — tokenCan() goes false and every tokenCan check 403s.
     * Flushing the guards and re-pointing the default driver at `web`
     * before each login keeps the session guard (and the TransientToken
     * wrap in the sanctum closure) in play — same lesson as the API trait's
     * forgetGuards note.
     */
    private function actingAsWeb($user): self
    {
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');

        return $this->actingAs($user);
    }

    public function test_ui_round_trip_create_update_delete(): void
    {
        Carbon::setTestNow('2026-03-15 09:00:00');
        [$user, $team] = $this->newUserWithTeam();

        /* The empty page renders */
        $this->actingAsWeb($user)
            ->get("/teams/{$team->id}/backups/schedule")
            ->assertOk();

        /* Create: daily, enabled → next run tomorrow at save time */
        $this->actingAsWeb($user)
            ->post("/teams/{$team->id}/backups/schedule", [
                'frequency' => 'daily',
                'enabled' => true,
            ])
            ->assertRedirect("/teams/{$team->id}/backups/schedule");

        $schedule = $team->backupSchedule()->first();
        $this->assertNotNull($schedule);
        $this->assertSame('daily', $schedule->frequency);
        $this->assertTrue($schedule->enabled);
        $this->assertSame($user->id, $schedule->user_id);
        $this->assertSame('2026-03-16 09:00:00', $schedule->next_run_at->format('Y-m-d H:i:s'));

        /* Update (upsert, same row): weekly + disabled → next run cleared,
           configurator re-stamped, and one row per team still */
        Carbon::setTestNow('2026-03-20 09:00:00');
        $this->actingAsWeb($user)
            ->post("/teams/{$team->id}/backups/schedule", [
                'frequency' => 'weekly',
                'enabled' => false,
            ])
            ->assertRedirect("/teams/{$team->id}/backups/schedule");

        $this->assertSame(1, BackupSchedule::where('team_id', $team->id)->count());
        $schedule->refresh();
        $this->assertSame('weekly', $schedule->frequency);
        $this->assertFalse($schedule->enabled);
        $this->assertNull($schedule->next_run_at);
        $this->assertSame($user->id, $schedule->user_id);

        /* Re-enabling computes the next run from the new save time */
        $this->actingAsWeb($user)
            ->post("/teams/{$team->id}/backups/schedule", [
                'frequency' => 'monthly',
                'enabled' => true,
            ])
            ->assertRedirect("/teams/{$team->id}/backups/schedule");

        $schedule->refresh();
        $this->assertTrue($schedule->enabled);
        $this->assertSame('2026-04-20 09:00:00', $schedule->next_run_at->format('Y-m-d H:i:s'));

        /* Validation: unknown frequency / missing enabled are rejected */
        $this->actingAsWeb($user)
            ->post("/teams/{$team->id}/backups/schedule", ['frequency' => 'hourly', 'enabled' => true])
            ->assertSessionHasErrors('frequency');
        $this->actingAsWeb($user)
            ->post("/teams/{$team->id}/backups/schedule", ['frequency' => 'daily'])
            ->assertSessionHasErrors('enabled');
        $schedule->refresh();
        $this->assertSame('monthly', $schedule->frequency);

        /* Delete removes the row */
        $this->actingAsWeb($user)
            ->delete("/teams/{$team->id}/backups/schedule")
            ->assertRedirect("/teams/{$team->id}/backups/schedule");
        $this->assertSame(0, BackupSchedule::where('team_id', $team->id)->count());
    }

    public function test_ui_authorization(): void
    {
        [$owner, $team] = $this->newUserWithTeam();
        [$ro] = $this->newUserWithTeam();
        $this->addTeamMember($ro, $team, 'Read Only');
        [$stranger] = $this->newUserWithTeam();

        /* Read-only member: page is readable, writes are 403 */
        $this->actingAsWeb($ro)
            ->get("/teams/{$team->id}/backups/schedule")
            ->assertOk();
        $this->actingAsWeb($ro)
            ->post("/teams/{$team->id}/backups/schedule", ['frequency' => 'daily', 'enabled' => true])
            ->assertStatus(403);
        $this->actingAsWeb($ro)
            ->delete("/teams/{$team->id}/backups/schedule")
            ->assertStatus(403);

        /* A non-member (valid session, foreign team) is 403 */
        $this->actingAsWeb($stranger)
            ->get("/teams/{$team->id}/backups/schedule")
            ->assertStatus(403);
        $this->actingAsWeb($stranger)
            ->post("/teams/{$team->id}/backups/schedule", ['frequency' => 'daily', 'enabled' => true])
            ->assertStatus(403);

        $this->assertSame(0, BackupSchedule::where('team_id', $team->id)->count());

        /* The owner can still save */
        $this->actingAsWeb($owner)
            ->post("/teams/{$team->id}/backups/schedule", ['frequency' => 'daily', 'enabled' => true])
            ->assertRedirect();
        $this->assertSame(1, BackupSchedule::where('team_id', $team->id)->count());
    }

    public function test_guest_is_redirected_to_login(): void
    {
        [$user, $team] = $this->newUserWithTeam();

        $this->get("/teams/{$team->id}/backups/schedule")->assertRedirect(route('login'));
        $this->post("/teams/{$team->id}/backups/schedule", ['frequency' => 'daily'])
            ->assertRedirect(route('login'));
    }

    public function test_command_creates_due_backup_and_advances_idempotently(): void
    {
        Carbon::setTestNow('2026-05-01 08:00:00');
        [$user, $team] = $this->newUserWithTeam();
        Item::factory()->onTeam($team)->create(['name' => 'Scheduled']);

        $schedule = $this->makeSchedule($team, $user, 'daily', now()->subMinute());

        /* First run: due → one backup attributed to the schedule's user */
        Artisan::call('moovit:auto-backups');

        $this->assertSame(1, Backup::where('team_id', $team->id)->count());
        $backup = Backup::where('team_id', $team->id)->first();
        $this->assertSame($user->id, $backup->user_id);
        $this->assertSame(1, $backup->item_count);
        Storage::disk('local')->assertExists($backup->path);

        $schedule->refresh();
        $this->assertSame('2026-05-01 08:00:00', $schedule->last_run_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-05-02 08:00:00', $schedule->next_run_at->format('Y-m-d H:i:s'));

        /* Re-run before next_run_at: no-op (idempotent) */
        Artisan::call('moovit:auto-backups');
        $this->assertSame(1, Backup::where('team_id', $team->id)->count());

        /* The next day the schedule is due again */
        Carbon::setTestNow('2026-05-02 08:00:00');
        Artisan::call('moovit:auto-backups');
        $this->assertSame(2, Backup::where('team_id', $team->id)->count());
        $schedule->refresh();
        $this->assertSame('2026-05-03 08:00:00', $schedule->next_run_at->format('Y-m-d H:i:s'));
    }

    public function test_all_four_frequencies_advance_from_the_run_time(): void
    {
        /* Jan 31 → the monthly edge pin: Carbon's addMonth() OVERFLOWS
           (Feb 31 does not exist → Mar 3). Accepted behavior, documented. */
        Carbon::setTestNow('2026-01-31 10:00:00');

        $cases = [
            'daily' => '2026-02-01 10:00:00',
            'weekly' => '2026-02-07 10:00:00',
            'monthly' => '2026-03-03 10:00:00',
            'yearly' => '2027-01-31 10:00:00',
        ];

        $schedules = [];
        foreach (array_keys($cases) as $frequency) {
            [$user, $team] = $this->newUserWithTeam();
            $schedules[$frequency] = $this->makeSchedule($team, $user, $frequency, now()->subMinute());
        }

        Artisan::call('moovit:auto-backups');

        foreach ($cases as $frequency => $expectedNext) {
            $schedules[$frequency]->refresh();
            $this->assertSame(
                '2026-01-31 10:00:00',
                $schedules[$frequency]->last_run_at->format('Y-m-d H:i:s'),
                "frequency {$frequency}: last_run_at"
            );
            $this->assertSame(
                $expectedNext,
                $schedules[$frequency]->next_run_at->format('Y-m-d H:i:s'),
                "frequency {$frequency}: next_run_at"
            );
        }
    }

    public function test_unknown_frequency_is_rejected_by_the_model(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        BackupSchedule::nextRunFor('hourly');
    }

    public function test_not_due_and_disabled_schedules_are_untouched(): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00');

        [$userA, $teamA] = $this->newUserWithTeam();
        $notDue = $this->makeSchedule($teamA, $userA, 'daily', now()->addHour());

        [$userB, $teamB] = $this->newUserWithTeam();
        $disabled = $this->makeSchedule($teamB, $userB, 'daily', now()->subHour(), false);

        /* A due control team, to prove the command ran */
        [$userC, $teamC] = $this->newUserWithTeam();
        $due = $this->makeSchedule($teamC, $userC, 'daily', now()->subMinute());

        Artisan::call('moovit:auto-backups');

        foreach ([$notDue, $disabled] as $untouched) {
            $untouched->refresh();
            $this->assertNull($untouched->last_run_at);
            $this->assertSame(0, Backup::where('team_id', $untouched->team_id)->count());
        }
        $this->assertSame(
            '2026-06-01 13:00:00',
            $notDue->next_run_at->format('Y-m-d H:i:s')
        );

        $due->refresh();
        $this->assertNotNull($due->last_run_at);
        $this->assertSame(1, Backup::where('team_id', $teamC->id)->count());
    }

    public function test_a_failing_team_does_not_block_the_others(): void
    {
        Carbon::setTestNow('2026-07-01 10:00:00');

        /* Team A is due first (ordered by next_run_at) and will fail */
        [$userA, $teamA] = $this->newUserWithTeam();
        $a = $this->makeSchedule($teamA, $userA, 'daily', now()->subMinutes(5));

        [$userB, $teamB] = $this->newUserWithTeam();
        $b = $this->makeSchedule($teamB, $userB, 'daily', now()->subMinute());

        $mock = Mockery::mock(BackupService::class)->makePartial();
        $mock->shouldReceive('createForTeam')
            ->once()
            ->ordered()
            ->andThrow(new RuntimeException('disk blown'));
        $mock->shouldReceive('createForTeam')
            ->once()
            ->ordered()
            ->passthru();
        $this->instance(BackupService::class, $mock);

        Artisan::call('moovit:auto-backups');

        /* The failed team: no backup, schedule unadvanced (retried next tick) */
        $a->refresh();
        $this->assertNull($a->last_run_at);
        $this->assertSame(0, Backup::where('team_id', $teamA->id)->count());

        /* The other team: processed normally */
        $b->refresh();
        $this->assertNotNull($b->last_run_at);
        $this->assertSame(1, Backup::where('team_id', $teamB->id)->count());
    }

    public function test_scheduled_backups_count_toward_retention(): void
    {
        Carbon::setTestNow('2026-08-01 06:00:00');
        [$user, $team] = $this->newUserWithTeam();

        $this->makeSchedule($team, $user, 'daily', now());

        $firstId = null;
        for ($i = 0; $i < 8; $i++) {
            Artisan::call('moovit:auto-backups');

            $ids = Backup::where('team_id', $team->id)->pluck('id');
            if ($firstId === null) {
                $firstId = $ids->first();
            }

            Carbon::setTestNow(Carbon::parse('2026-08-01 06:00:00')->addDays($i + 1));
        }

        /* The last-7 rule applies to scheduled backups identically */
        $this->assertSame(7, Backup::where('team_id', $team->id)->count());
        $this->assertNull(Backup::find($firstId));
    }
}
