<?php

namespace App\Support;

use App\Models\Backup;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * Savepoint diff (API-019), extracted from BackupController so the web
 * management UI can diff two savepoints through the exact same logic —
 * one compare implementation, two entry points.
 */
class BackupCompare
{
    /**
     * Diff two savepoints of the same team: `$base` is the reference,
     * `$target` the destination ("what happened going from base to target").
     * Per entity type: `added`/`removed` full rows (identity = row id),
     * `changed` only rows with at least one field difference as
     * `{field: {from, to}}`, plus `counts` `{added, removed, unchanged,
     * changed}`. Compared against the snapshots, not the live tables.
     * Fields compare as trimmed strings; null and empty string are equal
     * (CSV round-trip artifact). The dumps exclude soft-deleted rows, so a
     * soft-delete between the two savepoints surfaces as `removed` — the
     * row simply disappears from the target snapshot.
     *
     * @param Backup $base
     * @param Backup $target
     * @return array
     */
    public static function diff(Backup $base, Backup $target): array
    {
        return [
            'items' => self::compareType($base, $target, 'items'),
            'locations' => self::compareType($base, $target, 'locations'),
            'statuses' => self::compareType($base, $target, 'statuses'),
            'labels' => self::compareType($base, $target, 'labels'),
        ];
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
    public static function readRows(Backup $backup, string $type): array
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
     * Diff one entity type between base and target (see diff()).
     *
     * @param Backup $base
     * @param Backup $target
     * @param string $type
     * @return array
     */
    private static function compareType(Backup $base, Backup $target, string $type): array
    {
        $baseRows = self::readRows($base, $type);
        $targetRows = self::readRows($target, $type);

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
                if (self::normalize($from) !== self::normalize($to)) {
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
    private static function normalize(?string $value): string
    {
        return trim((string) $value);
    }
}
