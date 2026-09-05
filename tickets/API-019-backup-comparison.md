---
id: API-019
title: "Backup comparison (added / removed / changed)"
type: feature
priority: P2
status: in-review
depends_on: [API-018]
spec: "user request (Matthieu, 2026-09): a user could then decide to compare two backups (what was changed, what was added/removed/...)"
---

# API-019 — Backup comparison (added / removed / changed)

## Context
Second slice of the savepoint feature: after creating a savepoint before an
operation and another one after (or comparing "last week" vs "now"), the user
wants to know *"what was changed, what was added/removed"* between two
backups. The CSVs stored by API-018 are the source of truth — comparison
happens against the snapshots, not the live tables, so old states remain
comparable even after retention would have rotated live history.

## Scope — Must have
- [x] `GET api/backup/{backup}/compare/{other}` — both backups must belong to
      the requester's team (`item:read`; foreign-team pair → 403). Order
      matters and is explicit: `{backup}` is the **base**, `{other}` is the
      **target** ("what happened going from base to target").
- [x] Response (per entity type, items/locations/statuses/labels):
      `{"items": {"added": [row...], "removed": [row...], "changed": [{"id",
      "diff": {field: {from, to}}...}...], "counts": {...}},
      "locations": {...}, ...}` where rows are the parsed CSV rows (assoc
      arrays); `changed` lists only entities with at least one field
      difference, each with per-field `{from, to}`. Both sides must carry the
      same entity files (they always do — same dump code).
- [x] Semantics, pinned by tests:
  - identity = row `id` (uuid);
  - scalar fields compared as strings after normalization (null ↔ empty
    string treated as equal — CSV round-trip artifact);
  - `added` = in target, not in base; `removed` = in base, not in target
    (soft-deleted items appearing/disappearing show up here, exactly as a
    savepoint consumer would expect);
  - `counts` per type: `{added, removed, unchanged, changed}`;
  - top-level `{"generated_at": ..., "types": {...}}` or flat — pick a shape,
    pin it, document it in the ticket report.
- [x] Feature tests.

## Out of scope
- Diff of item histories/attachments (not in the dumps); UI (the endpoint
  serves the app/web); three-way merges; comparing backups of different teams
  (403) or a backup against live data (out of the ask — create a fresh
  savepoint instead).

## Acceptance criteria
- [x] Deterministic fixture: create backup A, mutate (add item, delete item,
      rename item, move item, add+remove label), create backup B → comparison
      reports exactly those events per type with `{from, to}` values matching
      the mutations; untouched rows counted `unchanged`.
- [x] Symmetry check pinned: comparing B→A reports the inverse sets.
- [x] Foreign-team pair → 403; unknown id → 404; read-only token allowed.
- [x] Large-ish team (a few hundred rows) comparison completes without
      timeouts (no test pin needed — implementation note only).

## Technical notes
- Parse both zips' CSVs with `fgetcsv` into `id => row` maps, `arrayDiffKeys`
  for added/removed, per-field `!==` loop for changed — no new dependencies.
- Normalization: trim both sides; cast numeric-looking columns? No — compare
  raw strings except treating `""` and `null` as equal; document exactly what
  is pinned (the tests are the spec).
- Memory: build maps per type, release before the next type — teams are small
  enough that full maps are fine.

## Tests
- New `tests/Feature/BackupCompareTest.php`: the mutation fixture above
  (added/removed/changed per type, counts, `{from,to}` values), inverse
  direction, 403/404 paths, empty-vs-empty, label/status/location events.

## Implementation Report

**Status**: done — 4 new tests in `tests/Feature/BackupCompareTest.php`
(61 assertions), full suite 265 tests / 1259 assertions / 4 skips
(pre-existing Jetstream skips).

**Production changes**
- `BackupController::compare()` + `GET api/backup/{backup}/compare/{other}`
  (inside the auth group). Authorization: requester needs `item:read` (team
  permission **and** token ability) on the base backup's team, and the pair
  must share `team_id` — a cross-team pair or a stranger is 403; unknown
  ids 404 via binding before authorization (established pattern).
- `readRows()` reads one entity CSV out of the stored zip into an
  `id => row` map (assoc arrays, raw CSV strings; empty/missing file →
  empty map — handles the empty-team case where the CSV has no header
  line). `compareType()` does set differences by id and a per-field
  trimmed-string loop (`null ≡ ''`). Response shape pinned FLAT:
  `{"generated_at", "items", "locations", "statuses", "labels"}`, each type
  `{added: [row], removed: [row], changed: [{id, diff: {field: {from,
  to}}}], counts: {added, removed, unchanged, changed}}` — documented in
  server.md §13.
- No new dependencies (`ZipArchive` + `str_getcsv`); memory-wise one id-map
  pair per type at a time — linear scans, fine for hundreds of rows.

**Pinned semantics**
- Tombstone honesty (correcting the ticket's parenthetical): the API-018
  dumps INCLUDE soft-deleted rows, so an item soft-deleted between A and B
  is **`changed`** (`deleted_at` from `''` to the timestamp; `updated_at`
  may ride along) — only rows that truly left the table (hard delete /
  cascade) are `removed`. The fixture pins both: `forceDelete` →
  `removed`, soft delete → `changed`. A savepoint consumer filters on
  `deleted_at` if they want live-only views — the snapshot data says so.
- "add+remove label" in the fixture = label ROWS added/removed (the pivot
  is not part of any dump); a label rename surfaces as `labels.changed`.
- Inverse direction: `added ↔ removed` and `from ↔ to` swap; a row that
  changed stays `changed` in both directions.

**Test findings worth keeping**
- Do NOT assert other diff fields beyond the intended one without second
  granularity care: asserting `updated_at` inside the tombstone diff
  passed standalone (times straddled a second) and failed in the full
  suite (all within one second → equal strings → no diff). Fixed-column
  assertions in CSV round-trips are timing-sensitive at second
  granularity — assert the field you mutated, nothing else.
- `created_at` (set at creation, never bumped) is the natural
  "unchanged-canary" column; `updated_at` is not.

**Deviations from spec**: the soft-delete parenthetical corrected to
`changed`-on-`deleted_at` (documented above + server.md §13); response
shape picked flat (documented). Otherwise as specified.
