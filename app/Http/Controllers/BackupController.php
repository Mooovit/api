<?php

namespace App\Http\Controllers;

use App\Exceptions\BackupCopyException;
use App\Models\Backup;
use App\Models\Team;
use App\Services\BackupService;
use App\Services\S3BackupClientFactory;
use App\Support\TeamRevision;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;
use ZipArchive;

/**
 * Savepoint backups (API-018): a team-scoped snapshot dumped as one CSV per
 * entity (items — soft-deleted tombstones included, locations, statuses,
 * labels), zipped and stored on the server under
 * `backups/{team_id}/{backup_id}.zip`. The row records the creator
 * ("this backup belongs to that user"); retention keeps the last 7 backups
 * per team — older rows are deleted together with their stored files.
 * Creation goes through `BackupService` — shared with the auto-backup
 * scheduler (API-020) and the S3 copy-off (API-021).
 *
 * Every client-facing shape flows through `Backup::metadata()` — filesystem
 * paths never leave the server; the download URL requires an authenticated
 * token, it is not a public link.
 */
class BackupController extends Controller
{
    /**
     * @var BackupService
     */
    private $service;

    /**
     * @var S3BackupClientFactory
     */
    private $s3;

    /**
     * @param BackupService $service
     * @param S3BackupClientFactory $s3
     * @return void
     */
    public function __construct(BackupService $service, S3BackupClientFactory $s3)
    {
        $this->service = $service;
        $this->s3 = $s3;
    }

    /**
     * Team-level authorization (create/list): the request-context team
     * (API-015's effectiveTeam — the token's current team when set, the
     * user's otherwise) + Jetstream permission + token ability.
     *
     * @param Request $request
     * @param string $permission 'item:read' or 'item:write'
     * @return Team
     * @throws AuthorizationException
     */
    private function authorizeTeam(Request $request, string $permission): Team
    {
        $user = $request->user();
        $team = $user->effectiveTeam();

        if (!$team instanceof Team
            || !$user->hasTeamPermission($team, $permission)
            || !$user->tokenCan($permission)
        ) {
            throw new AuthorizationException();
        }

        return $team;
    }

    /**
     * Resource-level authorization (download/delete): the backup's team —
     * the API-001-corrected pattern, never `current_team`.
     *
     * @param Request $request
     * @param Backup $backup
     * @param string $permission
     * @return void
     * @throws AuthorizationException
     */
    private function authorizeBackup(Request $request, Backup $backup, string $permission): void
    {
        $user = $request->user();
        if (!$user->hasTeamPermission($backup->team, $permission) ||
            !$user->tokenCan($permission)
        ) {
            throw new AuthorizationException();
        }
    }

    /**
     * POST api/backups (API-018) — snapshot the effective team via
     * `BackupService::createForTeam` (dump → row → retention → revision
     * bump, shared with the auto-backup scheduler). When the team has S3
     * configured (API-021), a failed bucket copy maps to 502 — upstream
     * trouble, not a validation error — and nothing is recorded.
     *
     * @param Request $request
     * @return JsonResponse
     * @throws AuthorizationException
     */
    public function store(Request $request): JsonResponse
    {
        $team = $this->authorizeTeam($request, 'item:write');

        try {
            $backup = $this->service->createForTeam($team, $request->user());
        } catch (BackupCopyException $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }

        return response()->json($backup->metadata(), 201);
    }

    /**
     * GET api/backups/bucket (API-021) — list the team's backup objects in
     * its configured bucket (keys + size + last_modified, newest first).
     * Without S3 → 422 (a clear "not configured" signal, not an auth error);
     * upstream S3 problems surface as 502 with the driver message.
     *
     * @param Request $request
     * @return JsonResponse
     * @throws AuthorizationException
     */
    public function bucket(Request $request): JsonResponse
    {
        $team = $this->authorizeTeam($request, 'item:read');

        $config = $team->s3Config()->first();
        if (!$config) {
            return response()->json(
                ['s3' => ['No S3 bucket configured for this team.']],
                422
            );
        }

        try {
            $objects = $this->s3->forConfig($config)->listObjects($config->prefix);
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }

        return response()->json([
            'bucket' => $config->bucket,
            'prefix' => $config->prefix,
            'objects' => $objects,
        ]);
    }

    /**
     * GET api/backups (API-018) — the team's snapshot metadata, newest
     * first. Provenance only: any member with `item:read` sees the team's
     * backups (`user_id` in the payload is not an ACL).
     *
     * @param Request $request
     * @return JsonResponse
     * @throws AuthorizationException
     */
    public function index(Request $request): JsonResponse
    {
        $team = $this->authorizeTeam($request, 'item:read');

        $rows = Backup::where('team_id', $team->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Backup $backup) => $backup->metadata())
            ->values();

        return response()->json($rows);
    }

    /**
     * GET api/backup/{backup} (API-018) — stream the zip with a readable
     * filename. `item:read` on the owning team.
     *
     * @param Request $request
     * @param Backup $backup
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     * @throws AuthorizationException
     */
    public function show(Request $request, Backup $backup)
    {
        $this->authorizeBackup($request, $backup, 'item:read');

        return Storage::disk($backup->disk)->response(
            $backup->path,
            sprintf('backup-%s-%s.zip', $backup->team_id, $backup->created_at->format('Ymd_His')),
            ['Content-Type' => 'application/zip']
        );
    }

    /**
     * DELETE api/backup/{backup} (API-018) — remove the file and the row;
     * anyone with `item:write` on the owning team (creator included).
     * Bumps the team revision once.
     *
     * @param Request $request
     * @param Backup $backup
     * @return JsonResponse
     * @throws AuthorizationException
     */
    public function destroy(Request $request, Backup $backup): JsonResponse
    {
        $this->authorizeBackup($request, $backup, 'item:write');

        DB::transaction(function () use ($backup): void {
            $backup->delete();
            Storage::disk($backup->disk)->delete($backup->path);
        });

        /* The row is gone — bump via the in-memory team_id (same target) */
        TeamRevision::bump($backup);

        return response()->json(['success' => 'success']);
    }

    /**
     * GET api/backup/{backup}/compare/{other} (API-019) — diff two
     * savepoints of the same team: `{backup}` is the base, `{other}` the
     * target ("what happened going from base to target"). Per entity type:
     * `added`/`removed` full rows (identity = row id), `changed` only rows
     * with at least one field difference as `{field: {from, to}}`, plus
     * `counts` `{added, removed, unchanged, changed}`. Compared against the
     * snapshots, not the live tables. Fields compare as trimmed strings;
     * null and empty string are equal (CSV round-trip artifact). Note: the
     * dumps include soft-deleted tombstones, so a soft-delete shows as
     * `changed` on `deleted_at` — only rows that truly left the table are
     * `removed`.
     *
     * @param Request $request
     * @param Backup $backup base
     * @param Backup $other target
     * @return JsonResponse
     * @throws AuthorizationException
     */
    public function compare(Request $request, Backup $backup, Backup $other): JsonResponse
    {
        $user = $request->user();
        if ($backup->team_id !== $other->team_id
            || !$user->hasTeamPermission($backup->team, 'item:read')
            || !$user->tokenCan('item:read')
        ) {
            throw new AuthorizationException();
        }

        return response()->json([
            'generated_at' => now(),
            'items' => $this->compareType($backup, $other, 'items'),
            'locations' => $this->compareType($backup, $other, 'locations'),
            'statuses' => $this->compareType($backup, $other, 'statuses'),
            'labels' => $this->compareType($backup, $other, 'labels'),
        ]);
    }

    /**
     * Read one entity CSV out of a backup zip as an `id => row` map (assoc
     * arrays, string values as the CSV carries them). An empty or missing
     * file (empty team) yields an empty map.
     *
     * @param Backup $backup
     * @param string $type
     * @return array<string, array<string, string>>
     */
    private function readRows(Backup $backup, string $type): array
    {
        $bytes = Storage::disk($backup->disk)->get($backup->path);

        $tmp = tempnam(sys_get_temp_dir(), 'compare');
        file_put_contents($tmp, $bytes);
        $zip = new ZipArchive();
        $zip->open($tmp);
        $csv = $zip->getFromName("{$type}.csv");
        $zip->close();
        unlink($tmp);

        if ($csv === false || trim($csv) === '') {
            return [];
        }

        $lines = explode("\n", trim($csv));
        $header = str_getcsv(array_shift($lines), ',', '"', '\\');

        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $row = array_combine($header, str_getcsv($line, ',', '"', '\\'));
            $rows[$row['id']] = $row;
        }

        return $rows;
    }

    /**
     * Diff one entity type between base and target (see compare()).
     *
     * @param Backup $base
     * @param Backup $target
     * @param string $type
     * @return array
     */
    private function compareType(Backup $base, Backup $target, string $type): array
    {
        $baseRows = $this->readRows($base, $type);
        $targetRows = $this->readRows($target, $type);

        $added = [];
        foreach ($targetRows as $id => $row) {
            if (!isset($baseRows[$id])) {
                $added[] = $row;
            }
        }

        $removed = [];
        foreach ($baseRows as $id => $row) {
            if (!isset($targetRows[$id])) {
                $removed[] = $row;
            }
        }

        $changed = [];
        $unchanged = 0;
        foreach ($targetRows as $id => $targetRow) {
            if (!isset($baseRows[$id])) {
                continue;
            }
            $diff = [];
            foreach ($targetRow as $field => $to) {
                $from = $baseRows[$id][$field] ?? '';
                if ($this->normalize($from) !== $this->normalize($to)) {
                    $diff[$field] = ['from' => $from, 'to' => $to];
                }
            }
            if ($diff !== []) {
                $changed[] = ['id' => $id, 'diff' => $diff];
            } else {
                $unchanged++;
            }
        }

        return [
            'added' => $added,
            'removed' => $removed,
            'changed' => $changed,
            'counts' => [
                'added' => count($added),
                'removed' => count($removed),
                'changed' => count($changed),
                'unchanged' => $unchanged,
            ],
        ];
    }

    /**
     * Comparison normalization: trimmed strings, null ≡ empty string.
     *
     * @param string|null $value
     * @return string
     */
    private function normalize(?string $value): string
    {
        return trim((string) $value);
    }
}
