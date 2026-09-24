<?php

namespace App\Support;

use App\Exceptions\PrintTargetOfflineException;
use App\Models\Item;
use App\Models\PrintJob;
use App\Models\PrintStation;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * API-039: the server-side DYMO print queue. All queue semantics live here so
 * the token API (PrintApiController) and the session flavor for the web UI
 * (PrintQueueController) stay thin and behave identically.
 *
 * Model:
 *  - A pusher enqueues a job (item uuid + template, optional target printer).
 *    Targeting a printer with no live station throws
 *    PrintTargetOfflineException (→ 409); untargeted jobs always enqueue and
 *    wait for the first claimer.
 *  - Stations declare themselves (heartbeat upsert on team + client_id) and
 *    poll claim(): they get the oldest claimable job addressed to them (or
 *    untargeted), exclusively. Claiming is a single conditional UPDATE judged
 *    by affected rows — safe when several tabs/stations race, identical
 *    behaviour on SQLite (tests) and MySQL (prod).
 *  - A claimed job that is never completed becomes claimable again after
 *    STALE_CLAIM_SECONDS. A station that fails to print returns the job to
 *    pending until MAX_ATTEMPTS, then the job goes terminal failed with the
 *    error text kept. Jobs are never deleted (item deletion cascades).
 *
 * Queue writes deliberately do NOT touch teams.revision (not observed by
 * BumpsTeamRevisionObserver, no explicit bumps) — printing is plumbing, not
 * synced domain data; clients must not re-pull because someone printed.
 */
class PrintQueue
{
    /** A station is "live" while its heartbeat is younger than this. */
    public const LIVE_WINDOW_SECONDS = 90;

    /** A claimed-but-never-completed job becomes claimable again after this. */
    public const STALE_CLAIM_SECONDS = 300;

    /** Print attempts before a job goes terminal failed. */
    public const MAX_ATTEMPTS = 3;

    /** Allowed label templates (mirrors window.Dymo.TEMPLATES). */
    public const TEMPLATES = ['30334', '30252', '30336'];

    /**
     * Queue a label job. The item must already be team-resolved by the caller.
     *
     * @param Team $team
     * @param User|null $user the requesting user (app token or session); audit only
     * @param Item $item
     * @param string $template one of PrintQueue::TEMPLATES
     * @param string|null $targetPrinter optional DYMO printer name; must be served
     *                                   by a live station when given
     * @return PrintJob the fresh pending job
     * @throws PrintTargetOfflineException when the target printer is not live
     */
    public static function enqueue(Team $team, ?User $user, Item $item, string $template, ?string $targetPrinter): PrintJob
    {
        if ($targetPrinter !== null && $targetPrinter !== '') {
            $served = PrintStation::where('team_id', $team->id)
                ->where('printer_name', $targetPrinter)
                ->get()
                ->contains(fn (PrintStation $station) => $station->isLive());

            if (!$served) {
                throw PrintTargetOfflineException::forPrinter($targetPrinter);
            }
        }

        return PrintJob::create([
            'team_id' => $team->id,
            'user_id' => $user?->id,
            'item_id' => $item->id,
            'template' => $template,
            'target_printer' => ($targetPrinter !== '' ? $targetPrinter : null),
            'status' => PrintJob::STATUS_PENDING,
        ]);
    }

    /**
     * Declare/refresh a station: upsert on [team_id, client_id] and stamp the
     * heartbeat. Called by POST /print/station and again on every claim poll.
     *
     * @param Team $team
     * @param string $clientId browser-local identity (localStorage uuid)
     * @param string $printerName the DYMO LabelWriter the workstation serves
     * @param string|null $label optional human label for the app's station list
     * @param string|null $template the station's default template, when known
     * @return PrintStation
     */
    public static function heartbeat(Team $team, string $clientId, string $printerName, ?string $label = null, ?string $template = null): PrintStation
    {
        $station = PrintStation::where('team_id', $team->id)
            ->where('client_id', $clientId)
            ->first();

        if ($station === null) {
            $station = new PrintStation([
                'team_id' => $team->id,
                'client_id' => $clientId,
            ]);
        }

        $station->printer_name = $printerName;
        $station->label = $label;
        if ($template !== null && in_array($template, self::TEMPLATES, true)) {
            $station->template = $template;
        }
        $station->last_seen_at = now();
        $station->save();

        return $station;
    }

    /**
     * The team's live stations, freshest heartbeat first — what the app's
     * printer picker lists.
     *
     * @param Team $team
     * @return Collection<int, PrintStation>
     */
    public static function liveStations(Team $team): Collection
    {
        return PrintStation::where('team_id', $team->id)
            ->orderByDesc('last_seen_at')
            ->get()
            ->filter(fn (PrintStation $station) => $station->isLive())
            ->values();
    }

    /**
     * Claim the next job for a station. Claimable = pending, or claimed but
     * stale (never completed within STALE_CLAIM_SECONDS — the claiming tab
     * probably died). Jobs addressed to this station's printer come first,
     * then untargeted ones, each group FIFO by created_at. The claim itself
     * is a conditional UPDATE whose affected-row count decides ownership, so
     * a losing racer simply moves on to the next candidate.
     *
     * @param Team $team
     * @param PrintStation $station the (already heart-beated) claiming station
     * @return PrintJob|null the exclusively-claimed job, or null when the queue is empty
     */
    public static function claim(Team $team, PrintStation $station): ?PrintJob
    {
        $staleBefore = now()->subSeconds(self::STALE_CLAIM_SECONDS);

        $candidates = PrintJob::where('team_id', $team->id)
            ->where(function ($query) use ($station) {
                $query->whereNull('target_printer')
                    ->orWhere('target_printer', $station->printer_name);
            })
            ->where(function ($query) use ($staleBefore) {
                $query->where('status', PrintJob::STATUS_PENDING)
                    ->orWhere(function ($query) use ($staleBefore) {
                        $query->where('status', PrintJob::STATUS_CLAIMED)
                            ->where('claimed_at', '<', $staleBefore);
                    });
            })
            ->orderByRaw('CASE WHEN target_printer IS NOT NULL THEN 0 ELSE 1 END')
            ->orderBy('created_at')
            ->limit(10)
            ->get();

        foreach ($candidates as $candidate) {
            $claimed = PrintJob::where('id', $candidate->id)
                ->where(function ($query) use ($staleBefore) {
                    $query->where('status', PrintJob::STATUS_PENDING)
                        ->orWhere(function ($query) use ($staleBefore) {
                            $query->where('status', PrintJob::STATUS_CLAIMED)
                                ->where('claimed_at', '<', $staleBefore);
                        });
                })
                ->update([
                    'status' => PrintJob::STATUS_CLAIMED,
                    'claimed_by' => $station->id,
                    'claimed_at' => now(),
                    'attempts' => $candidate->attempts + 1,
                ]);

            if ($claimed === 1) {
                return $candidate->fresh();
            }
        }

        return null;
    }

    /**
     * Report a claim outcome. Only the owning station's report counts.
     * Success → printed. Failure → back to pending while attempts remain
     * (another/passing station may retry), else terminal failed with the
     * error kept for the record.
     *
     * @param PrintJob $job
     * @param PrintStation $station the reporting station
     * @param bool $ok
     * @param string|null $error the DYMO/framework error text on failure
     * @return bool whether the report was applied (owner mismatch is a no-op)
     */
    public static function complete(PrintJob $job, PrintStation $station, bool $ok, ?string $error = null): bool
    {
        if ($job->claimed_by !== $station->id) {
            return false;
        }

        if ($ok) {
            $job->status = PrintJob::STATUS_PRINTED;
            $job->completed_at = now();
            $job->error = null;
            $job->save();

            return true;
        }

        if ($job->attempts < self::MAX_ATTEMPTS) {
            $job->status = PrintJob::STATUS_PENDING;
        } else {
            $job->status = PrintJob::STATUS_FAILED;
            $job->completed_at = now();
        }
        $job->error = $error;
        $job->save();

        return true;
    }
}
