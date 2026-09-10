<?php

namespace App\Http\Controllers;

use App\Models\Team;
use App\Support\Backup\BackupCodec;
use App\Support\Backup\BackupSnapshotBuilder;
use Illuminate\Http\Request;

/**
 * API-029 — the server-produced cold-storage backup sheet: renders the
 * team's MV-135 QR backup (manifest + chunk frames) as a printable page.
 * The frames are wire-format compatible with the app's scan-to-restore
 * (MV-137 `BackupCodec.assemble`) — see App\Support\Backup\BackupCodec.
 *
 * Gate: session user with the `item:write` team permission (owner /
 * Administrator) — an archive exposes the whole dataset, so Read-Only
 * members are 403.
 */
class BackupSheetController extends Controller
{
    /**
     * GET /kanban/backup — the printable sheet.
     *
     * @param Request $request
     * @return \Illuminate\Contracts\View\View
     */
    public function sheet(Request $request)
    {
        $user = $request->user();
        $team = Team::find($user->current_team_id);
        abort_unless($team, 404);

        if (!$user->hasTeamPermission($team, 'item:write')) {
            throw new \Illuminate\Auth\Access\AuthorizationException();
        }

        $snapshot = BackupSnapshotBuilder::build($team, now());
        $bundle = BackupCodec::export($snapshot);

        /* Per-chunk captions for the sheet: `chunk i/N` + the CRC the
           scanner will check (frame field 5). */
        $chunks = [];
        foreach ($bundle['frames'] as $index => $frame) {
            $chunks[] = [
                'index' => $index,
                'crc' => explode('|', $frame)[5],
                'frame' => $frame,
            ];
        }

        return view('backup-sheet', [
            'teamName' => $team->name,
            'exportedAt' => $snapshot['exportedAt'],
            'manifest' => $bundle['manifest'],
            'chunks' => $chunks,
            'chunkCount' => $bundle['chunkCount'],
            'totalPayloadBytes' => $bundle['totalPayloadBytes'],
            'fingerprint' => $bundle['fingerprint'],
        ]);
    }
}
