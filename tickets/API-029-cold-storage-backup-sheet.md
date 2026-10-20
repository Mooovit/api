---
id: API-029
title: "Cold-storage backup sheet — the server prints the MV-135 QR backup (manifest + chunk frames, app-scannable)"
type: feature
priority: P2
status: in-review
depends_on: [API-027]
spec: "user request 2026-09: backend produces the cold-storage sheet of MV-135..137 (wire-format exact)"
---

# API-029 — Server-produced cold-storage backup sheet

## Context

The app gained a cold-storage backup (MV-135 codec, MV-136 print, MV-137
scan-to-restore): the team dataset → canonical JSON → gzip → 1400-byte
chunks → text frames (`MVBAK1|…`), printed as QR pages, scannable back in
any order. The user wants the BACKEND to produce the same sheet — a
server-side printout of the authoritative dataset, no app sync required.
That means the server must emit frames the app's `BackupCodec.assemble`
accepts: the wire format is mirrored EXACTLY (grammar, chunk size, CRC32,
SHA-256, gzip, snapshot JSON field names), verified against the Kotlin
source (`BackupCodec.kt`, `BackupSnapshotBuilder.kt`).

## Scope — Must have
- [ ] `app/Support/Backup/BackupCodec.php` — PHP port of the Kotlin export
      half: snapshot array → `json_encode` (declaration-order keys) →
      `gzencode` (MTIME zeroed — JDK parity, deterministic bundles) →
      1400-byte chunks → frames:
      `MVBAK1|<schema>|C|<index>|<count>|<crc32 %08x>|<base64(slice)>` +
      manifest `MVBAK1|<schema>|M|<teamId>|<exportedAt>|<count>|<total>|<sha256hex>|<lengths>`.
      Schema version 1, chunk capacity 1400 raw bytes (the Kotlin
      `CHUNK_RAW_BYTES`).
- [ ] `app/Support/Backup/BackupSnapshotBuilder.php` — team snapshot with
      the Kotlin `BackupSnapshot` field names EXACTLY (`parentId`,
      `locationId`, `statusId`, `isBox`, `itemId`/`labelId`, camelCase):
      statuses/locations trashed-INCLUSIVE with `deletedAt` (API-027
      semantics, mirrors the app builder); labels with `order: null`
      (forward-compat field); LIVE items only (a trashed item has no
      `deletedAt` representation in schema v1 — mirrors the app skipping
      its deletion queue) with `isBox = parent_id === null` (the app's own
      derivation, `LocalInventoryRepository.kt:406`) and
      `locationId`/`statusId` as `""` when null (the Kotlin field is
      non-nullable — an explicit null would kill the parse); item↔label
      cross-refs, barcodes (`id`,`itemId`,`code`,`type`) and attachment
      METADATA (`id`,`itemId`,`caption`) scoped to the live items;
      deterministic ordering throughout (stable frames across runs).
- [ ] `GET /kanban/backup` (web, session): renders the printable sheet —
      summary header (team, exported-at, chunk count, payload bytes,
      SHA-256 fingerprint, warning that photo bytes are not included),
      manifest QR first, then the chunk QRs in a 3×4-per-page grid with
      `chunk i/N` + CRC captions and print page-breaks; vendored qrcodejs
      (EC-M, matching MV-135/MV-136), client-side rendering like the kanban
      QRs. Gate: team owner or Administrator (`item:write` team
      permission) — an archive is an admin-level action; Read-Only
      members get 403.
- [ ] Feature tests pinning the snapshot mapping + the sheet route;
      unit tests pinning the wire format byte-for-byte (grammar, CRC,
      SHA-256, length rules, gzip determinism) and a full codec round-trip
      decoded the way the app decodes it.

## Out of scope
- Server-side IMPORT (a restored device converges or stays offline by
  design — MV-137 out-of-scope note applies here too).
- PDF generation server-side (the browser prints the sheet to PDF/paper —
  MV-136's print dialog equivalent; no composer PDF/QR dependency).
- Attachment bytes (never printable — same as MV-135).
- Delta/incremental backups (v1 is a full snapshot).

## Acceptance criteria
- [ ] A seeded team exports to frames a scanner could feed into MV-137's
      `assemble`: per-chunk CRCs valid, lengths sum to the payload total,
      only the last chunk short, SHA-256 over the reassembled gzip stream
      matches the manifest, gunzip+parse yields the seeded snapshot.
- [ ] Same team + same data → byte-identical bundle (deterministic JSON +
      MTIME-zeroed gzip).
- [ ] The sheet shows the manifest first, chunk codes with captions, and
      carries only the current team's rows (foreign-team data absent).

## Technical notes
- Java `CRC32` == PHP `crc32()`; `kotlin.io.encoding.Base64` == PHP
  `base64_encode` (RFC 4648, padded); JDK `GZIPOutputStream` writes
  MTIME=0 — PHP `gzencode` stamps `time()`, so the codec zeroes header
  bytes 4–7 after encoding (documented, pinned by test).
- kotlinx JSON with `encodeDefaults = true` writes the null defaults —
  the PHP snapshot mirrors that (every field always present).
- The manifest's `exportedAt` must be pipe-free ISO-8601
  (`Y-m-d\TH:i:s\Z` UTC) — the Kotlin parser splits on `|` positionally.

## Tests
- `tests/Unit/BackupCodecTest.php`: exact manifest/chunk grammar pins;
  length rules; CRC + SHA-256 self-verification; gzip MTIME zeroed;
  multi-chunk export; decode round-trip (frames → concat → sha → gunzip →
  parse == snapshot); determinism.
- `tests/Feature/KanbanBackupSheetTest.php`: 401 guests; 403 Read-Only;
  owner/admin 200; manifest + chunk frames present; team scoping;
  snapshot mapping (trashed status/location included, trashed item
  excluded, isBox derivation, null location/status → "", cross-refs,
  barcodes, attachment metadata).

Run: `php artisan test --filter "BackupCodecTest|KanbanBackupSheetTest"`,
then full suite.

## Documentation requirements
- PHPDoc on the codec + builder; server.md note (MV-135 interop: frame
  grammar, snapshot mapping rules, sheet route).

## Implementation report
*(filled by the implementing agent — do not touch before starting work)*

### Blockers
- none

### Changes
- `App\Support\Backup\BackupCodec` — PHP port of the Kotlin export half,
  byte-exact: canonical JSON (`JSON_UNESCAPED_UNICODE|UNESCAPED_SLASHES`,
  declaration-order keys) → `gzencode` with the gzip header MTIME zeroed
  (`substr_replace` bytes 4–7 — JDK `GZIPOutputStream` parity, deterministic
  bundles) → 1400-byte raw chunks → `MVBAK1|1|C|<i>|<n>|<crc32 %08x>|<base64>`
  frames + `MVBAK1|1|M|<teamId>|<exportedAt>|<n>|<total>|<sha256hex>|<lengths>`
  manifest. Returns `{manifest, frames, chunkCount, totalPayloadBytes,
  fingerprint}`; `chunkCount >= 1` even for empty payloads (the Kotlin
  behaviour).
- `App\Support\Backup\BackupSnapshotBuilder` — team snapshot with the exact
  Kotlin `BackupSnapshot` field names: statuses/locations `withTrashed()`
  ordered name,id (tombstones carry `deletedAt` ISO — API-027 semantics);
  labels ordered name,id with forward-compat `order: null`; LIVE items only
  ordered created_at,id with `isBox = parent_id === null` and
  `locationId`/`statusId` as `""` when null (Kotlin non-nullable strings);
  `itemLabels`/`barcodes`/attachment METADATA (`id`,`itemId`,`caption` — no
  bytes, no storage paths) `whereIn` the live item ids, deterministically
  ordered; `exportedAt` normalized to pipe-free `Y-m-d\TH:i:s\Z` UTC (the
  Kotlin manifest parser splits on `|` positionally).
- `GET /kanban/backup` (`BackupSheetController::sheet`, web session) —
  current-team sheet; gate `item:write` team permission (owner/Administrator;
  Read-Only → 403, missing team → 404). Builds snapshot + bundle, passes
  manifest, per-chunk `{index, crc, frame}` and the summary fields to the
  view.
- `resources/views/backup-sheet.blade.php` — printable page on the vendored
  qrcodejs (EC-M, same as MV-136): summary table + photo-bytes warning +
  manifest QR on page 1, chunk QRs in 3×4-per-page grids with `chunk i/N` +
  `crc32 …` captions, print page-breaks, `window.print()` button.
- Route registered in the auth:sanctum kanban group (before the generic
  `{type}` route).

### Files touched
- `app/Support/Backup/BackupCodec.php` (new)
- `app/Support/Backup/BackupSnapshotBuilder.php` (new)
- `app/Http/Controllers/BackupSheetController.php` (new)
- `resources/views/backup-sheet.blade.php` (new)
- `routes/web.php` (route + import)
- `tests/Unit/BackupCodecTest.php` (new)
- `tests/Feature/KanbanBackupSheetTest.php` (new)
- `server.md`, `tickets/README.md`

### Tests run
```
php artisan test --filter "BackupCodecTest|KanbanBackupSheetTest"
  PASS Tests\Unit\BackupCodecTest (6)
    manifest grammar is exact / chunk frame grammar is exact /
    small snapshot is one chunk and round trips /
    large snapshot chunks and rules hold (only last chunk short,
      lengths vs manifest, sha256 over the concat) /
    bundle is deterministic and gzip mtime is zeroed /
    reordered frames reassemble identically
  PASS Tests\Feature\KanbanBackupSheetTest (5)
    guests 401 / Read-Only 403 / owner 200 with manifest+chunks /
    snapshot mapping matches the Kotlin BackupSnapshot /
    seeded team exports a bundle that decodes back (250 items, multi-chunk)
  Tests: 11 passed

php artisan test
  Tests: 4 skipped, 370 passed
```

### Commits
- (pending, batch commit)

### Notes for reviewer
- The wire format was verified against the Kotlin source, not the tickets:
  `BackupCodec.kt` frames are decimal TEXT fields (pipe-split), not binary —
  the earlier ticket prose suggested binary widths; the implementation
  (Kotlin and now PHP) is what counts.
- PHP `crc32()` == Java `CRC32`; PHP `base64_encode` == Kotlin padded
  Base64; `gzencode` stamps MTIME=time() so the codec zeroes header bytes
  4–7 — pinned by `test_bundle_is_deterministic_and_gzip_mtime_is_zeroed`.
- Schema-v1 mapping decisions mirroring the app builder: tombstoned
  statuses/locations included (history may reference them), items LIVE-only
  (`BackupItem` has no deletedAt), null location/status → `""` (kotlinx
  non-nullables — a JSON null would abort the app-side parse of the whole
  bundle).
- The sheet is an archive of the whole dataset — hence the `item:write`
  gate (Read-Only members 403) and the printed warning that photo bytes are
  not included.
- No server-side PDF/QR composer dependency: the browser renders + prints;
  interop target is the app's scanner, not the paper.
