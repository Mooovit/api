<?php

namespace App\Http\Controllers;

use App\Models\Audit;
use App\Models\Backup;
use App\Models\History;
use App\Models\Item;
use App\Models\Label;
use App\Models\Location;
use App\Models\Status;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

/**
 * The dashboard (server UI) — an overview of the session user's current
 * team: revision + last activity (API-003/009 as read-only summaries),
 * item counts with a status breakdown, the latest items with their
 * status/location, and the state of the savepoint stack (last backup,
 * auto-backup schedule, S3 offload — API-018/020/021).
 */
class DashboardController extends Controller
{
    /**
     * GET /dashboard.
     *
     * @param Request $request
     * @return \Inertia\Response
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $team = $user->currentTeam;

        if (!$team) {
            return Inertia::render('Dashboard', ['team' => null]);
        }

        /* Team-wide last activity: the newest change row or stocktake */
        $lastHistoryAt = History::whereHas('item', function ($q) use ($team) {
            $q->where('team_id', $team->id);
        })->max('changed_at');
        $lastAuditAt = Audit::where('team_id', $team->id)->max('created_at');
        $lastActivityAt = collect([$lastHistoryAt, $lastAuditAt])->filter()->sortDesc()->first();

        $lastBackup = Backup::where('team_id', $team->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
        $schedule = $team->backupSchedule()->first();
        $s3Config = $team->s3Config()->first();

        return Inertia::render('Dashboard', [
            'team' => $team->only(['id', 'name']),
            'revision' => (int) $team->revision,
            'counts' => [
                'items' => Item::where('team_id', $team->id)->count(),
                'locations' => Location::where('team_id', $team->id)->count(),
                'statuses' => Status::where('team_id', $team->id)->count(),
                'labels' => Label::where('team_id', $team->id)->count(),
            ],
            'status_breakdown' => Status::where('team_id', $team->id)
                ->withCount('items')
                ->orderBy('position')
                ->get()
                ->map(fn (Status $status) => [
                    'id' => $status->id,
                    'name' => $status->name,
                    'items_count' => (int) $status->items_count,
                ])
                ->values(),
            'items' => Item::where('team_id', $team->id)
                ->with(['status:id,name', 'location:id,name'])
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(10)
                ->get()
                ->map(fn (Item $item) => [
                    'id' => $item->id,
                    'name' => $item->name,
                    'status' => $item->status->name ?? null,
                    'location' => $item->location->name ?? null,
                    'updated_at' => $item->updated_at
                        ? $item->updated_at->toISOString()
                        : null,
                ])
                ->values(),
            'last_activity_at' => $lastActivityAt
                ? Carbon::parse($lastActivityAt)->toISOString()
                : null,
            'last_device_seen_at' => $user->tokens()->max('last_used_at'),
            'last_backup' => $lastBackup ? [
                'id' => $lastBackup->id,
                'created_at' => $lastBackup->created_at,
                'size' => (int) $lastBackup->size,
                'remote' => (bool) $lastBackup->remote,
            ] : null,
            'schedule' => $schedule ? $schedule->only(['enabled', 'frequency', 'last_run_at', 'next_run_at']) : null,
            's3_configured' => (bool) $s3Config,
            's3_bucket' => optional($s3Config)->bucket,
        ]);
    }
}
