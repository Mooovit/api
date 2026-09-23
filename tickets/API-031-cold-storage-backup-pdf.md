---
id: API-031
title: "Cold-storage backup PDF — GET api/backups/cold-storage/pdf renders the MVBAK1 sheet server-side"
type: feature
priority: P2
status: in-review
depends_on: [API-029, API-030]
spec: "user request 2026-09: the cold-storage route gives the PDF directly as well"
---

# API-031 — Server-rendered cold-storage backup PDF

## Context

The cold-storage backup currently ships two ways: the printable HTML sheet
(API-029, client-side QR rendering, browser → print to PDF) and the JSON
bundle (API-030). The user wants a route that returns the PDF DIRECTLY — no
browser printing step, ready for archiving/attaching. That means the server
must draw the QR codes itself (pure-PHP QR encoder + PDF writer) and lay out
the same content as the sheet: summary header, manifest QR first, then the
chunk QRs with captions.

## Scope — Must have
- [ ] `composer`: `setasign/fpdf` (^1.8.4, pure PHP, no extensions) +
      `chillerlan/php-qrcode` (^4.3 — the PHP-8.0 branch matching the
      project's `platform.php: 8.0.30`). MIT licensed, like the vendored
      qrcodejs. QRs are drawn as VECTOR rects from the module matrix
      (`getMatrix()->matrix(true)`), so no GD/imagick requirement at runtime.
- [ ] `App\Support\Backup\BackupPdf` — bundle → PDF bytes (FPDF `Output('S')`),
      mirroring the sheet layout: page 1 = title, team, exported-at, chunk
      count, payload bytes, SHA-256 fingerprint, photo-bytes warning,
      MANIFEST QR + manifest text; then chunk QRs 3×4 per A4 page, each with
      `chunk i/N` + `crc32 …` captions. Same EC-M level as the sheet/app.
- [ ] `GET api/backups/cold-storage/pdf` (API `BackupController`, fixed
      sub-path next to `cold-storage`): SAME gate as the JSON flavor —
      `item:write` on the effective team; returns `application/pdf`, inline
      disposition with a readable filename.
- [ ] Feature tests: auth matrix (401/403/403/200); `%PDF-` magic +
      content-type; page count == 1 + ceil(chunkCount / 12) (chunk count
      cross-checked against the JSON endpoint); deterministic bytes.

## Out of scope
- Decoding the printed QRs back server-side (encoder only — the app's
  scanner is the reader).
- A web/Inertia flavor or a button on the HTML sheet (the sheet keeps its
  browser-print path; the PDF endpoint is programmatic).
- Storing the PDF (derived on demand, like the JSON bundle).

## Acceptance criteria
- [ ] `GET api/backups/cold-storage/pdf` streams a valid multi-page PDF
      whose page count follows the chunk count, gated `item:write`.
- [ ] Same team + same data + same exportedAt → byte-identical PDF
      (FPDF writes no timestamps; QR layout deterministic).
- [ ] QR payload fidelity inherited from chillerlan (battle-tested encoder),
      drawn at EC-M with 4-module quiet zone — matching the HTML sheet.

## Technical notes
- `QRCode::getMatrix($data)->matrix(true)` → bool[][] including the quiet
  zone; dark modules drawn as `Rect(..., 'F')` at `qrSize / moduleCount`
  mm each (vector, sharp at any print size).
- FPDF core fonts are Latin-1: team names are transliterated UTF-8 →
  windows-1252 (`//TRANSLIT`, fallback strip) for captions — QR content is
  ASCII-safe and unaffected.
- FPDF `Output('S')` embeds no creation/modification dates → deterministic
  bytes (pinned by test).

## Tests
- `tests/Feature/ColdStorageBackupPdfTest.php`: auth matrix; PDF magic +
  headers; `/Count` page math vs the JSON endpoint's `chunkCount`;
  byte-determinism of two exports.

Run: `php artisan test --filter "ColdStorageBackupPdfTest|ColdStorageBackupApiTest"`,
then full suite.

## Documentation requirements
- PHPDoc on `BackupPdf` + controller method; server.md API-029 paragraph
  gains the PDF endpoint sentence.

## Implementation report
*(filled by the implementing agent — do not touch before starting work)*

### Blockers
- none

### Changes
- `composer require setasign/fpdf:^1.8.4 chillerlan/php-qrcode:^4.3` —
  pure-PHP PDF writer + QR encoder, both MIT; versions pinned to the
  PHP-8.0 branch (chillerlan 4.4.2) matching the project's
  `platform.php: 8.0.30`. No runtime GD/imagick requirement.
- `App\Support\Backup\BackupPdf` — bundle → PDF bytes, mirroring the HTML
  sheet: page 1 = title, summary rows (team / exported-at / chunk count /
  payload bytes / SHA-256 fingerprint), photo-bytes warning box, manifest
  QR + manifest text; chunk QRs then 3×4 per A4 page with `chunk i/N` +
  `crc32 …` captions. QRs are drawn as VECTOR rects from
  `QRCode::getMatrix($data)->matrix(true)` (EC-M, 4-module quiet zone —
  same level as the sheet's qrcodejs); UTF-8 captions transliterated to
  windows-1252 for the FPDF core fonts (QR content is ASCII-safe).
- `GET api/backups/cold-storage/pdf` (`BackupController::coldStoragePdf`) —
  same `authorizeTeam($request, 'item:write')` gate as the JSON flavor;
  returns `application/pdf`, `inline` disposition with filename
  `cold-storage-{teamId}-{exportedAt}.pdf`.
- Route registered next to `backups/cold-storage` (fixed sub-path, before
  the `{backup}` binding routes).

### Files touched
- `composer.json` / `composer.lock` (2 deps)
- `app/Support/Backup/BackupPdf.php` (new)
- `app/Http/Controllers/BackupController.php` (method + import)
- `routes/api.php` (route)
- `tests/Feature/ColdStorageBackupPdfTest.php` (new)
- `server.md`, `tickets/README.md`

### Tests run
```
php artisan test --filter "ColdStorageBackupPdfTest|ColdStorageBackupApiTest"
  PASS Tests\Feature\ColdStorageBackupApiTest (4)  — API-030 untouched
  PASS Tests\Feature\ColdStorageBackupPdfTest (6)
    guests 401 / no-item:write token 403 / Read-Only member 403 /
    owner gets a valid PDF (%PDF- magic, application/pdf, inline filename,
      1-chunk dataset → 2 pages) /
    page count follows the chunk count (60 items → chunkCount from the
      JSON endpoint → pages == 1 + ceil(chunks/12)) /
    two exports are byte identical (frozen clock)
  Tests: 10 passed

php artisan test
  Tests: 4 skipped, 380 passed
```
Plus a manual render check (synthetic 30-item snapshot → 2-page PDF):
inflated content streams carry all summary/caption text and 6356 vector
`re` fill ops (the QR modules).

### Commits
- (pending, batch commit)

### Notes for reviewer
- QR fidelity is inherited from chillerlan (battle-tested); the renderer
  only re-draws its boolean matrix as PDF rects — the same technique as
  the library's own QRFpdf output, but into a multi-page document.
- FPDF `Output('S')` embeds no timestamps → byte-deterministic (pinned);
  the only cross-call input is the exported-at line, hence the frozen
  clock in the determinism test.
- The HTML sheet (API-029) keeps its browser-print path untouched; the PDF
  endpoint is additive, derived on demand, nothing stored.
- Note: composer reports 8 pre-existing security advisories in OTHER
  packages (unrelated to these two additions) — flagged for a separate
  housekeeping pass.
