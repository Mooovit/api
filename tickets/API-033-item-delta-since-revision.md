<!--
Template for every ticket. Copy this file to tickets/API-0XX-slug.md and fill every section.
Do not delete sections; if one doesn't apply, write "N/A — reason".
The implementing agent fills: status (to in-progress/in-review), Implementation report.
-->

---
id: API-033
title: "Item delta keyed by team revision (?since_revision=N)"
type: feature
priority: P1
status: in-review        # ready | in-progress | blocked | in-review | done
depends_on: [API-003, API-005, API-006]
spec: "server.md item delta; consumed by app ticket MV-150"
---

# API-033 — Item delta keyed by team revision (`?since_revision=N`)

## Context

The item delta endpoint (`GET api/item?since=<ISO-8601>`) compares `updated_at`
at second precision against the client-supplied cursor. When rows share the
cursor's second (a batch import, a bulk op, a backup restore), the server
re-delivers the same rows forever and the client cursor can never advance past
them — the app observed `?since=2026-09-10T20:03:26.000000Z` returning the same
27 items on every cycle. The team revision counter (API-003,
`GET api/revision`, `X-Revision` response header) is a monotonic integer with
none of these precision problems: this ticket adds a revision-keyed delta so
the app can key its pull cursor on the counter instead of a timestamp.

Built on API-003 (the team write counter) and the existing `?since=` delta
envelope (API-005/API-006: `{ changed: [...], deleted_ids: [...] }`).

## Scope — Must have

- [ ] Migration: nullable `sync_revision` (unsigned bigint) column on `items`.
- [ ] Stamp `sync_revision` with the team's **post-increment** revision counter
      on every write that changes an item's app-representation:
      create / full-object update / move / assign / rename / pick / unpick
      (only when it actually mutates) / label attach + detach / transfer-in /
      soft delete / restore. Rows re-parented as a side effect (delete-detach,
      transfer detach) are stamped too.
- [ ] Barcode-registry writes and attachment uploads/deletes bump the team
      revision but stamp **no** item rows (the app re-reads those sets through
      the detail payloads; the delta response still carries the new counter so
      the client's cursor advances past the bump).
- [ ] `GET api/item?since_revision=N` — same response envelope as the
      timestamp delta: `changed` = team items (effective team scope) with
      `sync_revision > N`, `deleted_ids` = soft-deleted team items with
      `sync_revision > N`.
- [ ] `X-Revision` response header on the delta = the team's current revision
      (same as the full list endpoint) — lets the client converge its cursor
      in one round-trip, including bump-only deltas whose `changed` is empty.
- [ ] Validation: `since_revision` must be a non-negative integer → **422**
      otherwise (mirrors the date validation of `?since=`).
- [ ] `since_revision >= current revision` → empty delta (`changed: []`,
      `deleted_ids: []`), 200, current revision in the header.
- [ ] The `sync_revision` column stays **out** of every item JSON payload
      (resource whitelist unchanged).
- [ ] Backfill migration: all existing rows (live + trashed) get
      `sync_revision` = their team's current revision at migration time, so
      first use of a revision cursor cannot strand rows behind it.
- [ ] Keep the `?since=` timestamp delta working unchanged (older app
      versions still self-heal; deprecate later).

## Out of scope

- Per-item revision numbers *exposed* in payloads — the client never needs
  them; the team counter + `changed` rows are enough.
- Pagination of the delta window (the timestamp delta is unpaged today).
- Status/location/label/attachment index deltas — the attachment index keeps
  its own `?since=` cursor (API-029); team settings are pulled whole.
- Removing the `?since=` endpoint (do that in a later ticket once no client
  sends it).

## Acceptance criteria

- [ ] `GET api/item?since_revision=0` returns every team item in `changed`
      (equivalent to a full pull) + current revision header.
- [ ] A write to one item, then `GET api/item?since_revision=<previous>` lists
      exactly that item in `changed` and nothing else.
- [ ] A soft-deleted item appears in `deleted_ids` for the next delta and
      never again afterwards (its `sync_revision` no longer moves).
- [ ] A barcode attach (revision bump, no item row change) answered a delta
      with empty `changed`/`deleted_ids` and the **new** revision in
      `X-Revision`.
- [ ] `?since_revision=abc` → 422. `?since_revision=<current>` → empty 200.
- [ ] Item payloads in the response contain no `sync_revision` key.
- [ ] A restore (un-delete) makes the item reappear in `changed` on the next
      delta.

## Technical notes

- The counter itself already exists (API-003): read the team's current
  revision **after** incrementing it and stamp that value — rows must never be
  stamped with a counter another in-flight write could exceed.
- Stamp inside the same DB transaction as the mutation.
- `deleted_ids` today = `deleted_at > since`; the revision variant keys on the
  stamped `sync_revision` of the trashed row (soft deletes keep the row, so
  the stamp survives).
- The Android client (MV-150) treats any 422 as "cursor garbage" and falls
  back to a full pull; an un-parseable body (server predating this ticket
  answers the flat list) falls back the same way — both are safe.
- Watch out for the existing `updated_at` second-precision re-delivery when
  writing tests: use distinct revision bumps, not timestamps.

## Tests

Backend (phpunit):
- Stamp on create / move / rename / delete / label attach (each bumps + stamps).
- Delta filtering `> N` for `changed` and `deleted_ids`, team scope enforced.
- 422 on garbage, empty delta at `N = current`, header present and current.
- Column absent from the JSON payloads.
- Backfill: pre-migration rows all pass `sync_revision = team revision`.

Manual: `curl -H "Authorization: Bearer …" '.../api/item?since_revision=0' | jq`.

## Documentation requirements

- server.md item-delta section: document `since_revision`, its validation, the
  `X-Revision` header, and the stamping rules (which writes stamp, which only
  bump).
- Note the deprecation path of `?since=`.

## Implementation report
*(filled by the implementing agent — do not touch before starting work)*

### Blockers
- none (app side MV-150 ships first and falls back to full pulls until this deploys)

### Changes
- Migration `2026_09_10_000001_add_sync_revision_to_items_table.php`: nullable
  `sync_revision` (unsigned bigint) on `items`, backfilled (live + trashed) with
  each team's current counter so first-use cursors cannot strand pre-existing rows.
- `TeamRevision`: new `bumpAndGet(Model)` / `nextForId($teamId)` (bump + return the
  post-increment value — writers serialize on the team row) and `bumpAndStamp(Model)`
  (the pivot-write flavor for label attach/detach).
- Observer `BumpsTeamRevisionObserver`: bump moved to the pre-events (`creating`/
  `updating`) so the stamp rides the SAME INSERT/UPDATE; `deleted`/`restored` bump
  then stamp via a quiet `withTrashed()` query (the soft-delete UPDATE only writes
  `deleted_at`, and trashed rows are invisible to the default scope). Statuses/
  locations/labels keep bump-only (no stamp, they are not items).
- Stamped explicit mass updates: delete-detach children (destroy), bulk-move,
  bulk-assign (one stamp value per touched team per request — `??=` lazily), and
  transfer (both subtree updates carry the DESTINATION team's counter; source keeps
  its plain bump so its clients learn something left).
- Label attach/detach (API `LabelController` + kanban `KanbanController`) now
  `bumpAndStamp` the item. Barcode registry + share links stay bump-only per spec.
- `ItemController::index`: `?since_revision=N` branch (checked before `?since=`),
  same `{changed, deleted_ids}` envelope, `X-Revision` = current counter; validation
  `integer|min:0` → 422 on garbage. `?since=` kept byte-for-byte identical.
- `Item` model: `$hidden = ['sync_revision']` — the stamp never serializes
  (list/delta/show/transfer payloads), resource whitelist unchanged.
- `BackupPdf` (drive-by, fixes a flaky test): FPDF stamps `/CreationDate` with its
  real clock in `_enddoc`, which made `test_two_exports_are_byte_identical` fail
  whenever two renders straddled a real second boundary. The PDF now pins
  `CreationDate` to the bundle's `exportedAt` (overridden `_putinfo`), restoring the
  byte-determinism the test pins.
- Docs: server.md §2 (API-033 blockquote + superseded note on `?since=`), §9
  (stamping rules), tickets README mapping row.

### Files touched
- `database/migrations/2026_09_10_000001_add_sync_revision_to_items_table.php` (new)
- `app/Support/TeamRevision.php`
- `app/Observers/BumpsTeamRevisionObserver.php`
- `app/Models/Item.php`
- `app/Http/Controllers/ItemController.php` (index / destroy / bulkMove / bulkAssign / transfer)
- `app/Http/Controllers/LabelController.php`
- `app/Http/Controllers/KanbanController.php`
- `app/Support/Backup/BackupPdf.php` (flaky-test fix)
- `tests/Feature/ItemRevisionDeltaTest.php` (new)
- `server.md`, `tickets/README.md`, this ticket

### Tests run
```
vendor/bin/phpunit --filter ItemRevisionDeltaTest  → OK (14 tests, 83 assertions)
vendor/bin/phpunit --filter ColdStorageBackupPdfTest → OK (6 tests, 15 assertions)
vendor/bin/phpunit → OK (411 tests, 2302 assertions, 4 pre-existing skips)
```

### Commits
- `075141c` — API-033(feature): revision-keyed item delta — GET api/item?since_revision=N
  (10 files, 1265 insertions; note: shared-file hunks for backlog files that
  rode along in the same files — ItemController/KanbanController/docs — carry
  the API-022..032 catch-up landed one commit earlier as `79935ad`)

### Notes for reviewer
- Backfill correctness on a live DB is the one thing the suite cannot pin (tests
  run on a fresh schema where it no-ops): verify `UPDATE items SET sync_revision =
  (SELECT revision FROM teams WHERE teams.id = items.team_id)` runs in a deploy
  window where no writes interleave (or accept a stamp slightly below the counter —
  harmless, the column only has to be ≥ every pre-deploy cursor).
- No-op writes (rename-to-same, unpick-when-clean) bump and stamp nothing —
  matches the pick/unpick spec wording.
- Per-logical-write bump counts changed: a delete of a box WITH children now bumps
  twice (detach stamp + trash stamp) instead of once; harmless for the gate (the
  counter is monotonic and stamps are always ≤ pull-time counter), but revision
  counts in logs will read slightly higher.
- The restore path has no endpoint yet; the observer handles the model-level
  `restore()` so the acceptance criterion is testable (and future-proof).
