<?php

namespace App\Services;

use App\Exceptions\BackupCopyException;
use App\Models\Backup;
use App\Models\Team;
use App\Models\User;
use App\Support\TeamRevision;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;
use ZipArchive;

/**
 * Shared savepoint machinery (API-018/020/021): dump a team's entity tables
 * as one CSV each into a zip on the `local` disk, record the Backup row,
 * apply the last-7 retention and bump the team revision. Used by the API
 * controller (manual savepoints) and the auto-backup scheduler command
 * (API-020) — one dump implementation, two entry points. When the team has
 * S3 configured (API-021) the zip is copied off before the row is recorded;
 * a failed copy leaves no row and no local file.
 */
class BackupService
{
    /** Backups kept per team after each create. */
    public const RETENTION = 7;

    /**
     * @var S3BackupClientFactory
     */
    private $s3;

    /**
     * @param S3BackupClientFactory $s3
     * @return void
     */
    public function __construct(S3BackupClientFactory $s3)
    {
        $this->s3 = $s3;
    }

    /**
     * Snapshot a team: one CSV per entity (items, locations, statuses,
     * labels — soft-deleted rows excluded) into a zip on the `local` disk,
     * then the metadata row, then retention (keep the last 7 of the team)
     * and a single revision bump (API-003 — backup rows are not
     * observer-registered, retention pruning is internal cleanup riding
     * along with the create).
     *
     * @param Team $team
     * @param User $user provenance (manual creator or schedule configurator)
     * @return Backup
     */
    public function createForTeam(Team $team, User $user): Backup
    {
        /* The id is minted up front — the storage path carries it */
        $id = (string) Str::uuid();
        $path = "backups/{$team->id}/{$id}.zip";

        /* Stream each table into the archive: header line + every row */
        $temp = tempnam(sys_get_temp_dir(), 'backup');
        $zip = new ZipArchive();
        $zip->open($temp, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $counts = [];
        foreach ($this->dumps($team->id) as $name => $query) {
            $fh = fopen('php://temp', 'r+');
            $counts[$name] = 0;
            foreach ($query->cursor() as $row) {
                $row = (array) $row;
                if ($counts[$name] === 0) {
                    fputcsv($fh, array_keys($row), ',', '"', '\\');
                }
                fputcsv($fh, $row, ',', '"', '\\');
                $counts[$name]++;
            }
            rewind($fh);
            $zip->addFromString("{$name}.csv", stream_get_contents($fh));
            fclose($fh);
        }
        $zip->close();

        $bytes = file_get_contents($temp);
        unlink($temp);
        Storage::disk('local')->put($path, $bytes);

        /* API-021: copy-off when the team has S3 configured. One attempt —
           a failed copy leaves NO row and no unreferenced local file (the
           caller maps this to a 502; the scheduler retries next tick). */
        $s3Config = $team->s3Config()->first();
        $remote = false;
        if ($s3Config) {
            try {
                $this->s3->forConfig($s3Config)->put($path, $bytes);
                $remote = true;
            } catch (Throwable $e) {
                Storage::disk('local')->delete($path);

                throw new BackupCopyException(
                    "S3 copy failed: {$e->getMessage()}",
                    0,
                    $e
                );
            }
        }

        $backup = Backup::create([
            'id' => $id,
            'team_id' => $team->id,
            'user_id' => $user->id,
            'disk' => 'local',
            'path' => $path,
            'size' => strlen($bytes),
            'item_count' => $counts['items'],
            'location_count' => $counts['locations'],
            'status_count' => $counts['statuses'],
            'label_count' => $counts['labels'],
            'remote' => $remote,
        ]);

        $this->prune($team->id);

        /* Backup rows fire no revision observer — bump explicitly */
        TeamRevision::bump($backup);

        return $backup;
    }

    /**
     * The entity tables dumped into the archive, keyed by CSV file name.
     * Plain query builder (not Eloquent): all rows, all columns, no model
     * machinery — the raw table content is the snapshot. Soft-deleted rows
     * are excluded: a savepoint reflects what a device would sync, so a
     * deleted item/location/status leaves the snapshot and a later compare
     * reports it as `removed` (not as a `deleted_at` field change).
     *
     * @param string $teamId
     * @return array<string, \Illuminate\Database\Query\Builder>
     */
    private function dumps(string $teamId): array
    {
        return [
            'items' => DB::table('items')->where('team_id', $teamId)->whereNull('deleted_at')->orderBy('created_at'),
            'locations' => DB::table('locations')->where('team_id', $teamId)->whereNull('deleted_at')->orderBy('created_at'),
            'statuses' => DB::table('statuses')->where('team_id', $teamId)->whereNull('deleted_at')->orderBy('created_at'),
            'labels' => DB::table('labels')->where('team_id', $teamId)->orderBy('created_at'),
        ];
    }

    /**
     * Retention: after each create, keep only the last `RETENTION` backups
     * of the team — older rows are deleted together with their stored files
     * (rows count: a manual DELETE frees a slot).
     *
     * @param string $teamId
     * @return void
     */
    private function prune(string $teamId): void
    {
        Backup::where('team_id', $teamId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->skip(self::RETENTION)
            ->take(PHP_INT_MAX) /* OFFSET needs a LIMIT (SQLite/Postgres) */
            ->get()
            ->each(function (Backup $stale): void {
                DB::transaction(function () use ($stale): void {
                    $stale->delete();
                    Storage::disk($stale->disk)->delete($stale->path);
                });
            });
    }
}
