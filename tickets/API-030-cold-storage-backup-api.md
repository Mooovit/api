---
id: API-030
title: "Cold-storage backup API — GET api/backups/cold-storage returns the MVBAK1 bundle as JSON"
type: feature
priority: P2
status: in-review
depends_on: [API-029]
spec: "user request 2026-09: an endpoint to GET the cold-storage backup"
---

# API-030 — Cold-storage backup API endpoint

## Context

API-029 gave the backend the exact MV-135 wire format (`BackupCodec`) and a
PRINTABLE sheet (`GET /kanban/backup`) — but no machine-readable way to FETCH
the bundle. A client (CLI, script, the app, a secondary server) should be able
to pull the same `MVBAK1` bundle over the API: same frames the sheet prints,
JSON-shaped, behind the normal token auth matrix. The cold-storage backup is
generated on the fly from the live dataset (no storage, no retention) — the
endpoint is a pure read over the API-029 builders.

## Scope — Must have
- [ ] `GET api/backups/cold-storage` on the API `BackupController` (the
      API-018 family home): `authorizeTeam($request, 'item:write')` — the
      SAME gate as the sheet (an archive is an admin-level action; Read-Only
      members and write-less tokens are 403), team = API-015 `effectiveTeam`.
- [ ] Response = the MVBAK1 bundle as JSON: `schemaVersion`, `teamId`,
      `exportedAt` (ISO-8601 Z), `manifest`, `frames[]` (chunk frames in
      order), `chunkCount`, `totalPayloadBytes`, `fingerprint` — exactly the
      values the sheet renders, decodable by the app's MV-137 `assemble`
      (per-frame CRC32 → manifest lengths → whole-stream SHA-256 → gunzip →
      kotlinx parse).
- [ ] Fixed route declared with the other fixed `backups/` sub-paths (before
      any `{backup}` binding concerns).
- [ ] Feature tests: guest 401; token without `item:write` ability → 403;
      Read-Only member (full-ability token) → 403; owner/admin `item:write`
      token → 200 with the exact bundle shape + grammar; decoded frames ==
      `BackupSnapshotBuilder::build` output (team-scoped, foreign team absent).

## Out of scope
- Storing/archiving the bundle server-side (it is derived on demand; savepoint
  backups API-018 remain the stored flavor).
- Import (unchanged — MV-137 out-of-scope note applies).
- A web/Inertia flavor (the sheet page already renders the human view).

## Acceptance criteria
- [ ] The JSON bundle decodes (frames → concat → sha256 == fingerprint →
      gunzip → parse) to exactly the snapshot the builder emits for the team.
- [ ] Auth matrix: 401 guest / 403 no-`item:write` token / 403 Read-Only
      member / 200 owner-Administrator.
- [ ] Foreign-team data never appears in the decoded snapshot.

## Technical notes
- Reuses `BackupSnapshotBuilder::build` + `BackupCodec::export` verbatim —
  no wire-format change, so `BackupCodecTest` (API-029) keeps pinning the
  bytes; the new tests only pin the HTTP surface + JSON envelope.
- `now()` as `exportedAt` — same as the sheet; two calls may differ in the
  timestamp only (payload deterministic per dataset).

## Tests
- `tests/Feature/ColdStorageBackupApiTest.php`: auth matrix; bundle shape +
  frame grammar; decode == builder snapshot; foreign-team scoping.

Run: `php artisan test --filter "ColdStorageBackupApiTest|BackupCodecTest"`,
then full suite.

## Documentation requirements
- PHPDoc on the controller method; server.md API-029 paragraph gains the
  endpoint sentence.

## Implementation report
*(filled by the implementing agent — do not touch before starting work)*

### Blockers
- none

### Changes
- `GET api/backups/cold-storage` (`BackupController::coldStorage`) —
  builds the API-029 bundle on the fly (`BackupSnapshotBuilder::build` +
  `BackupCodec::export`) and returns it as JSON:
  `{schemaVersion, teamId, exportedAt, manifest, frames, chunkCount,
  totalPayloadBytes, fingerprint}` — exactly the values the printable sheet
  renders. No wire-format change; the frames decode through the app's
  MV-137 `assemble` unchanged.
- Auth matrix mirrors the sheet: `authorizeTeam($request, 'item:write')` —
  effective team (API-015) + Jetstream team permission + token ability.
  Guest 401, write-less token 403, Read-Only member 403, owner/Admin 200.
- Route registered with the fixed `backups/` sub-paths (after
  `backups/bucket`, before the `{backup}` binding routes).

### Files touched
- `app/Http/Controllers/BackupController.php` (method + imports)
- `routes/api.php` (route)
- `tests/Feature/ColdStorageBackupApiTest.php` (new)
- `server.md`, `tickets/README.md`

### Tests run
```
php artisan test --filter "ColdStorageBackupApiTest|BackupCodecTest|KanbanBackupSheetTest"
  PASS Tests\Unit\BackupCodecTest (6)          — API-029 wire format untouched
  PASS Tests\Feature\ColdStorageBackupApiTest (4)
    guests 401 / no-item:write token 403 / Read-Only member 403 /
    owner gets the MVBAK1 bundle as JSON (structure + grammar +
    sha256-over-frames + decoded == BackupSnapshotBuilder output at the
    returned exportedAt + foreign team absent)
  PASS Tests\Feature\KanbanBackupSheetTest (5) — API-029 sheet untouched
  Tests: 15 passed

php artisan test
  Tests: 4 skipped, 374 passed
```

### Commits
- (pending, batch commit)

### Notes for reviewer
- Pure read over the API-029 builders — no storage, no retention, no
  revision bump (nothing mutated).
- The bundle is derived from the LIVE dataset each call; `exportedAt` is
  `now()`, so two calls differ at most in the timestamp (payload is
  deterministic per dataset — pinned by API-029's codec tests).
- `item:write` (not `item:read`) on purpose: the archive exposes the whole
  dataset, same rationale as the sheet gate.
