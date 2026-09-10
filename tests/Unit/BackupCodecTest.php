<?php

namespace Tests\Unit;

use App\Support\Backup\BackupCodec;
use PHPUnit\Framework\TestCase;

/**
 * API-029 — the PHP twin of the app's MV-135 `BackupCodec.kt` export half.
 * These tests decode the bundle the way the app's `FrameAccumulator.assemble`
 * does (per-frame CRC → length rules → SHA-256 → gunzip → JSON) so a wire
 * drift from the Kotlin grammar fails here, not on a printed sheet.
 */
class BackupCodecTest extends TestCase
{
    /** A representative snapshot, keyed like the Kotlin BackupSnapshot. */
    private function snapshot(): array
    {
        return [
            'schemaVersion' => 1,
            'teamId' => '0f9d3a20-0000-0000-0000-000000000001',
            'exportedAt' => '2026-09-07T10:00:00Z',
            'statuses' => [
                ['id' => 'status-1', 'name' => 'In stock', 'deletedAt' => null],
                ['id' => 'status-2', 'name' => 'Retired', 'deletedAt' => '2026-09-01T08:00:00.000000Z'],
            ],
            'locations' => [
                ['id' => 'location-1', 'name' => 'Shelf A', 'deletedAt' => null],
            ],
            'labels' => [
                ['id' => 'label-1', 'name' => 'Fragile', 'color' => '#FF0000', 'order' => null],
            ],
            'items' => [
                ['id' => 'item-1', 'name' => 'Box A', 'parentId' => null, 'teamId' => 'team-1',
                 'locationId' => 'location-1', 'statusId' => 'status-1', 'isBox' => true],
                ['id' => 'item-2', 'name' => 'Screw', 'parentId' => 'item-1', 'teamId' => 'team-1',
                 'locationId' => 'location-1', 'statusId' => 'status-1', 'isBox' => false],
            ],
            'itemLabels' => [
                ['itemId' => 'item-1', 'labelId' => 'label-1'],
            ],
            'barcodes' => [
                ['id' => 'barcode-1', 'itemId' => 'item-1', 'code' => '4006381333931', 'type' => 'EAN13'],
            ],
            'attachments' => [
                ['id' => 'attach-1', 'itemId' => 'item-1', 'caption' => 'Under the stairs'],
            ],
        ];
    }

    /** Decodes frames exactly like the app's FrameAccumulator.assemble. */
    private function assemble(array $bundle): array
    {
        $manifest = $bundle['manifest'];
        $this->assertSame('MVBAK1', strtok($manifest, '|'));

        $parts = explode('|', $manifest);
        $this->assertGreaterThanOrEqual(9, count($parts));
        $this->assertSame('M', $parts[2]);
        $chunkCount = (int) $parts[5];
        $total = (int) $parts[6];
        $sha = $parts[7];
        $lengths = array_map('intval', explode(',', $parts[8]));
        $this->assertCount($chunkCount, $lengths);

        $slices = [];
        foreach ($bundle['frames'] as $frame) {
            $f = explode('|', $frame);
            $this->assertSame('MVBAK1', $f[0]);
            $this->assertSame('C', $f[2]);
            $index = (int) $f[3];
            $this->assertSame($chunkCount, (int) $f[4]);
            $slice = base64_decode($f[6], true);
            $this->assertNotFalse($slice, 'the payload must be valid padded base64');
            $this->assertSame(strtolower($f[5]), sprintf('%08x', crc32($slice)), "CRC of chunk $index");
            $slices[$index] = $slice;
        }

        ksort($slices);
        $payload = '';
        for ($i = 0; $i < $chunkCount; $i++) {
            $this->assertArrayHasKey($i, $slices, "chunk $i present");
            $this->assertSame($lengths[$i], strlen($slices[$i]), "chunk $i length matches the manifest");
            $payload .= $slices[$i];
        }
        $this->assertSame($total, strlen($payload));
        $this->assertSame($sha, hash('sha256', $payload));

        $decoded = gzdecode($payload);
        $this->assertNotFalse($decoded);

        return json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_manifest_grammar_is_exact(): void
    {
        $bundle = BackupCodec::export($this->snapshot());

        $parts = explode('|', $bundle['manifest']);
        $this->assertSame('MVBAK1', $parts[0]);
        $this->assertSame('1', $parts[1]);
        $this->assertSame('M', $parts[2]);
        $this->assertSame('0f9d3a20-0000-0000-0000-000000000001', $parts[3]);
        $this->assertSame('2026-09-07T10:00:00Z', $parts[4]);
        $this->assertSame((string) $bundle['chunkCount'], $parts[5]);
        $this->assertSame((string) $bundle['totalPayloadBytes'], $parts[6]);
        $this->assertSame($bundle['fingerprint'], $parts[7]);
        /* SHA-256: 64 lowercase hex chars */
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $parts[7]);
    }

    public function test_chunk_frame_grammar_is_exact(): void
    {
        $bundle = BackupCodec::export($this->snapshot());

        foreach ($bundle['frames'] as $index => $frame) {
            $parts = explode('|', $frame);
            $this->assertSame('MVBAK1', $parts[0]);
            $this->assertSame('1', $parts[1]);
            $this->assertSame('C', $parts[2]);
            $this->assertSame((string) $index, $parts[3]);
            $this->assertSame((string) $bundle['chunkCount'], $parts[4]);
            $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $parts[5], 'crc32 as %08x');
            $this->assertNotFalse(base64_decode($parts[6], true));
        }
    }

    public function test_small_snapshot_is_one_chunk_and_round_trips(): void
    {
        $bundle = BackupCodec::export($this->snapshot());

        $this->assertSame(1, $bundle['chunkCount']);
        $this->assertCount(1, $bundle['frames']);
        $this->assertSame($bundle['fingerprint'], $bundle['manifest'] ? explode('|', $bundle['manifest'])[7] : '');

        $decoded = $this->assemble($bundle);
        $this->assertSame($this->snapshot(), $decoded);
    }

    public function test_large_snapshot_chunks_and_rules_hold(): void
    {
        $snapshot = $this->snapshot();
        /* ~400 items ≈ tens of KB of JSON → dozens of gzip chunks */
        for ($i = 0; $i < 400; $i++) {
            $snapshot['items'][] = [
                'id' => "item-x$i", 'name' => "Generated box number $i with a reasonably long name",
                'parentId' => null, 'teamId' => 'team-1',
                'locationId' => 'location-1', 'statusId' => 'status-1', 'isBox' => true,
            ];
        }

        $bundle = BackupCodec::export($snapshot);

        $this->assertGreaterThan(1, $bundle['chunkCount']);
        $this->assertCount($bundle['chunkCount'], $bundle['frames']);
        $this->assertSame(
            $bundle['chunkCount'],
            (int) explode('|', $bundle['manifest'])[5]
        );

        /* Only the last chunk may be short; every other is exactly 1400 */
        $total = 0;
        foreach ($bundle['frames'] as $index => $frame) {
            $raw = base64_decode(explode('|', $frame)[6], true);
            if ($index < $bundle['chunkCount'] - 1) {
                $this->assertSame(BackupCodec::CHUNK_RAW_BYTES, strlen($raw));
            }
            $total += strlen($raw);
        }
        $this->assertSame($bundle['totalPayloadBytes'], $total);
        $this->assertLessThanOrEqual(BackupCodec::CHUNK_RAW_BYTES, $total % BackupCodec::CHUNK_RAW_BYTES ?: BackupCodec::CHUNK_RAW_BYTES);

        $decoded = $this->assemble($bundle);
        $this->assertSame($snapshot, $decoded);
    }

    public function test_bundle_is_deterministic_and_gzip_mtime_is_zeroed(): void
    {
        $first = BackupCodec::export($this->snapshot());
        $second = BackupCodec::export($this->snapshot());

        /* Byte-identical bundles (JSON key order + zeroed gzip MTIME) */
        $this->assertSame($first['manifest'], $second['manifest']);
        $this->assertSame($first['frames'], $second['frames']);
        $this->assertSame($first['fingerprint'], $second['fingerprint']);

        /* Gzip header MTIME field (bytes 4..7) is zero — JDK parity */
        $frame = explode('|', $first['frames'][0]);
        $payload = base64_decode($frame[6], true);
        if (strlen($payload) >= 10) {
            $this->assertSame("\x00\x00\x00\x00", substr($payload, 4, 4));
        }
    }

    public function test_reordered_frames_reassemble_identically(): void
    {
        $bundle = BackupCodec::export($this->snapshot());
        $snapshot = $this->snapshot();

        /* Force a multi-chunk payload so shuffling means something */
        for ($i = 0; $i < 200; $i++) {
            $snapshot['items'][] = [
                'id' => "item-y$i", 'name' => "Another generated box $i",
                'parentId' => null, 'teamId' => 'team-1',
                'locationId' => 'location-1', 'statusId' => 'status-1', 'isBox' => true,
            ];
        }
        $bundle = BackupCodec::export($snapshot);
        $this->assertGreaterThan(1, $bundle['chunkCount']);

        $shuffled = $bundle;
        $shuffled['frames'] = array_reverse($bundle['frames']);

        $this->assertSame($snapshot, $this->assemble($shuffled));
    }
}
