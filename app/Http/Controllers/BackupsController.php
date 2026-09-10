<?php

namespace App\Http\Controllers;

use App\Exceptions\BackupCopyException;
use App\Models\Backup;
use App\Models\Team;
use App\Services\BackupService;
use App\Support\BackupCompare;
use App\Support\Backup\BackupCodec;
use App\Support\Backup\BackupPdf;
use App\Support\Backup\BackupSnapshotBuilder;
use App\Support\TeamRevision;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Savepoint management (server UI) — the API-018/019 features behind a web
 * page, per team: list backups (safe metadata shapes only), create one now
 * (through the shared BackupService — retention + revision bump included),
 * download the zip, delete it, and compare two savepoints through the same
 * BackupCompare implementation as the API. Team authorization follows the
 * BackupScheduleController web pattern (session users carry a
 * TransientToken, so tokenCan passes).
 */
class BackupsController extends Controller
{
    /**
     * @var BackupService
     */
    private $service;

    /**
     * @param BackupService $service
     * @return void
     */
    public function __construct(BackupService $service)
    {
        $this->service = $service;
    }

    /**
     * Team-level authorization for the web routes (the team comes from the
     * URL, not from a token's current team).
     *
     * @param Request $request
     * @param Team $team
     * @param string $permission 'item:read' or 'item:write'
     * @return void
     * @throws AuthorizationException
     */
    private function authorizeTeam(Request $request, Team $team, string $permission): void
    {
        $user = $request->user();

        if (!$user->belongsToTeam($team)
            || !$user->hasTeamPermission($team, $permission)
            || !$user->tokenCan($permission)
        ) {
            throw new AuthorizationException();
        }
    }

    /**
     * The client-facing backup shape for the page — counts, size, remote
     * flag and a web download URL; never `disk`/`path`.
     *
     * @param Backup $backup
     * @param Team $team
     * @return array
     */
    private function shape(Backup $backup, Team $team): array
    {
        return [
            'id' => $backup->id,
            'size' => (int) $backup->size,
            'item_count' => (int) $backup->item_count,
            'location_count' => (int) $backup->location_count,
            'status_count' => (int) $backup->status_count,
            'label_count' => (int) $backup->label_count,
            'remote' => (bool) $backup->remote,
            'download_url' => route('backups.download', ['team' => $team->id, 'backup' => $backup->id]),
            'created_at' => $backup->created_at,
        ];
    }

    /**
     * The page props (also reused by compare() to render the page with the
     * diff attached).
     *
     * @param Team $team
     * @return array
     */
    private function props(Team $team): array
    {
        return [
            'team' => $team->only(['id', 'name']),
            'retention' => BackupService::RETENTION,
            's3_configured' => (bool) $team->s3Config()->first(),
            'backups' => Backup::where('team_id', $team->id)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->get()
                ->map(fn (Backup $backup) => $this->shape($backup, $team))
                ->values(),
        ];
    }

    /**
     * GET /teams/{team}/backups.
     *
     * @param Request $request
     * @param Team $team
     * @return Response
     * @throws AuthorizationException
     */
    public function index(Request $request, Team $team): Response
    {
        $this->authorizeTeam($request, $team, 'item:read');

        return Inertia::render('Backups/Index', $this->props($team));
    }

    /**
     * POST /teams/{team}/backups — create a savepoint now.
     *
     * @param Request $request
     * @param Team $team
     * @return RedirectResponse
     * @throws AuthorizationException
     */
    public function store(Request $request, Team $team): RedirectResponse
    {
        $this->authorizeTeam($request, $team, 'item:write');

        try {
            $this->service->createForTeam($team, $request->user());
        } catch (BackupCopyException $e) {
            return Redirect::back()->withErrors(['backup' => $e->getMessage()]);
        }

        return Redirect::route('backups.index', $team)->with('success', 'Backup created.');
    }

    /**
     * GET /teams/{team}/backups/cold-storage/pdf — the API-031 cold-storage
     * sheet as a SERVER-rendered PDF, session flavor of
     * `BackupController::coldStoragePdf` (the /api/* route needs a bearer
     * token, the browser UI only carries a session — see the web.php note
     * on the kanban page). Same content (BackupPdf: MVBAK1 manifest + QR
     * chunk frames) and the same gate as the sheet: `item:write` — an
     * archive exposes the whole dataset, so Read-Only members are 403.
     *
     * @param Request $request
     * @param Team $team
     * @return \Illuminate\Http\Response
     * @throws AuthorizationException
     */
    public function coldStoragePdf(Request $request, Team $team)
    {
        $this->authorizeTeam($request, $team, 'item:write');

        $snapshot = BackupSnapshotBuilder::build($team, now());
        $bundle = BackupCodec::export($snapshot);
        $bytes = BackupPdf::render($bundle, $team->name, $snapshot['exportedAt']);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf(
                'inline; filename="cold-storage-%s-%s.pdf"',
                $team->id,
                str_replace(':', '', $snapshot['exportedAt'])
            ),
        ]);
    }

    /**
     * GET /teams/{team}/backups/{backup}/download — stream the zip with a
     * readable filename (backup must belong to the URL team).
     *
     * @param Request $request
     * @param Team $team
     * @param Backup $backup
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     * @throws AuthorizationException
     */
    public function download(Request $request, Team $team, Backup $backup)
    {
        $this->authorizeTeam($request, $team, 'item:read');
        abort_unless($backup->team_id === $team->id, 404);

        return Storage::disk($backup->disk)->response(
            $backup->path,
            sprintf('backup-%s-%s.zip', $backup->team_id, $backup->created_at->format('Ymd_His')),
            ['Content-Type' => 'application/zip']
        );
    }

    /**
     * DELETE /teams/{team}/backups/{backup} — remove the row and the file,
     * bump the team revision once.
     *
     * @param Request $request
     * @param Team $team
     * @param Backup $backup
     * @return RedirectResponse
     * @throws AuthorizationException
     */
    public function destroy(Request $request, Team $team, Backup $backup): RedirectResponse
    {
        $this->authorizeTeam($request, $team, 'item:write');
        abort_unless($backup->team_id === $team->id, 404);

        DB::transaction(function () use ($backup): void {
            $backup->delete();
            Storage::disk($backup->disk)->delete($backup->path);
        });

        TeamRevision::bump($backup);

        return Redirect::route('backups.index', $team)->with('success', 'Backup deleted.');
    }

    /**
     * POST /teams/{team}/backups/compare — diff two savepoints of this team
     * (`base_id` → `target_id`) and render the page with the result. Same
     * team scoping as the API: both backups must belong to the URL team.
     *
     * @param Request $request
     * @param Team $team
     * @return Response
     * @throws AuthorizationException
     */
    public function compare(Request $request, Team $team): Response
    {
        $this->authorizeTeam($request, $team, 'item:read');

        $data = $request->validate([
            'base_id' => 'required|string',
            'target_id' => 'required|string',
        ]);

        $base = Backup::where('team_id', $team->id)->whereKey($data['base_id'])->firstOrFail();
        $target = Backup::where('team_id', $team->id)->whereKey($data['target_id'])->firstOrFail();

        return Inertia::render('Backups/Index', array_merge(
            $this->props($team),
            ['compare' => BackupCompare::diff($base, $target)]
        ));
    }
}
