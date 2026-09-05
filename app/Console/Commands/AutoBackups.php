<?php

namespace App\Console\Commands;

use App\Models\BackupSchedule;
use App\Services\BackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * API-020: run due auto-backup schedules. Every enabled schedule whose
 * `next_run_at` is due creates one savepoint (BackupService — the exact
 * API-018 machinery, retention included) attributed to the schedule's
 * `user_id` (the configurator — provenance, not an ACL), then advances
 * `last_run_at` / `next_run_at`. One transaction per schedule: a failing
 * team is reported and left unadvanced (retried next tick) without blocking
 * the others.
 */
class AutoBackups extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'moovit:auto-backups';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create the due auto-backups for every team schedule (API-020)';

    /**
     * Execute the console command.
     *
     * @param BackupService $service
     * @return int
     */
    public function handle(BackupService $service): int
    {
        $due = BackupSchedule::where('enabled', true)
            ->where('next_run_at', '<=', now())
            ->orderBy('next_run_at')
            ->get();

        foreach ($due as $schedule) {
            try {
                DB::transaction(function () use ($service, $schedule): void {
                    $service->createForTeam($schedule->team, $schedule->user);
                    $schedule->advance();
                });

                $this->info("Backup created for team {$schedule->team_id}.");
            } catch (Throwable $e) {
                report($e);

                $this->error("Backup failed for team {$schedule->team_id}: {$e->getMessage()}");
            }
        }

        return 0;
    }
}
