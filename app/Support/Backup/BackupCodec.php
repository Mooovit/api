<?php

namespace App\Support\Backup;

/**
 * API-029 — server-side cold-storage backup codec: the PHP twin of the
 * app's MV-135 `BackupCodec.kt` (export half). Turns a team snapshot array
 * (see BackupSnapshotBuilder) into the bundle of QR-printable frames the
 * app's scan-to-restore (MV-137 `BackupCodec.assemble`) accepts.
 *
 * Wire format — single-line, `|`-separated text frames (base64 never
 * contains the separator):
 * ```
 * manifest: MVBAK1|<schema>|M|<teamId>|<exportedAt>|<chunkCount>|<totalPayloadBytes>|<sha256hex>|<len0,len1,...>
 * chunk:    MVBAK1|<schema>|C|<chunkIndex>|<chunkCount>|<crc32hex>|<base64(payload slice)>
 * ```
 *
 * Pipeline: snapshot → JSON (declaration-order keys — deterministic) →
 * gzip (MTIME zeroed — JDK GZIPOutputStream parity, so the same snapshot
 * always yields byte-identical bundles) → 1400-byte chunks (the Kotlin
 * CHUNK_RAW_BYTES: a frame stays inside one version-40 EC-M QR).
 *
 * Field correspondence (Java → PHP): java.util.zip.CRC32 → crc32(),
 * kotlin.io.encoding.Base64 → base64_encode (RFC 4648, padded),
 * MessageDigest SHA-256 → hash('sha256'). The Kotlin parser splits frames
 * positionally on `|`, so `exportedAt` must stay pipe-free ISO-8601.
 */
class BackupCodec
{
    /** Frame magic — the first field of every frame (MV-135). */
    public const MAGIC = 'MVBAK1';

    /** Current snapshot schema version — bump only additive-only (MV-135). */
    public const SCHEMA_VERSION = 1;

    /**
     * Raw gzip bytes per chunk — mirrors the app's CHUNK_RAW_BYTES so a
     * frame fits a version-40 EC-M QR with margin on BOTH producers.
     */
    public const CHUNK_RAW_BYTES = 1400;

    /**
     * Encodes a snapshot array (BackupSnapshotBuilder::build) into the
     * printable bundle: the manifest frame (print as its own QR, first
     * page) + the ordered chunk frames.
     *
     * @param array $snapshot
     * @return array{manifest: string, frames: string[], chunkCount: int, totalPayloadBytes: int, fingerprint: string}
     */
    public static function export(array $snapshot): array
    {
        $json = json_encode(
            $snapshot,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        $payload = self::gzip((string) $json);
        $total = strlen($payload);
        $chunkCount = max(1, (int) ceil($total / self::CHUNK_RAW_BYTES));

        $lengths = [];
        for ($i = 0; $i < $chunkCount; $i++) {
            $lengths[] = min(self::CHUNK_RAW_BYTES, $total - $i * self::CHUNK_RAW_BYTES);
        }

        $fingerprint = hash('sha256', $payload);

        /* The manifest's exportedAt rides positionally (parts[4] after the
           `|` split) — the builder guarantees the pipe-free ISO-8601 form. */
        $manifest = implode('|', [
            self::MAGIC,
            self::SCHEMA_VERSION,
            'M',
            (string) $snapshot['teamId'],
            (string) $snapshot['exportedAt'],
            (string) $chunkCount,
            (string) $total,
            $fingerprint,
            implode(',', $lengths),
        ]);

        $frames = [];
        for ($i = 0; $i < $chunkCount; $i++) {
            $slice = substr($payload, $i * self::CHUNK_RAW_BYTES, $lengths[$i]);

            $frames[] = implode('|', [
                self::MAGIC,
                self::SCHEMA_VERSION,
                'C',
                (string) $i,
                (string) $chunkCount,
                sprintf('%08x', crc32($slice)),
                base64_encode($slice),
            ]);
        }

        return [
            'manifest' => $manifest,
            'frames' => $frames,
            'chunkCount' => $chunkCount,
            'totalPayloadBytes' => $total,
            'fingerprint' => $fingerprint,
        ];
    }

    /**
     * gzip with a zeroed MTIME header field: PHP stamps `time()` there,
     * the JDK writes 0 — zeroing keeps bundles byte-deterministic (and
     * byte-identical to the app's for the same payload). Gzip header:
     * magic(2) CM(1) FLG(1) MTIME(4) XFL(1) OS(1).
     *
     * @param string $bytes
     * @return string
     */
    private static function gzip(string $bytes): string
    {
        $gz = gzencode($bytes);

        return substr_replace($gz, "\x00\x00\x00\x00", 4, 4);
    }
}
