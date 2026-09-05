---
id: API-019
title: "Backup comparison (added / removed / changed)"
type: feature
priority: P2
status: ready
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
- [ ] `GET api/backup/{backup}/compare/{other}` — both backups must belong to
      the requester's team (`item:read`; foreign-team pair → 403). Order
      matters and is explicit: `{backup}` is the **base**, `{other}` is the
      **target** ("what happened going from base to target").
- [ ] Response (per entity type, items/locations/statuses/labels):
      `{"items": {"added": [row...], "removed": [row...], "changed": [{"id",
      "diff": {field: {from, to}}...}...], "counts": {...}},
      "locations": {...}, ...}` where rows are the parsed CSV rows (assoc
      arrays); `changed` lists only entities with at least one field
      difference, each with per-field `{from, to}`. Both sides must carry the
      same entity files (they always do — same dump code).
- [ ] Semantics, pinned by tests:
  - identity = row `id` (uuid);
  - scalar fields compared as strings after normalization (null ↔ empty
    string treated as equal — CSV round-trip artifact);
  - `added` = in target, not in base; `removed` = in base, not in target
    (soft-deleted items appearing/disappearing show up here, exactly as a
    savepoint consumer would expect);
  - `counts` per type: `{added, removed, unchanged, changed}`;
  - top-level `{"generated_at": ..., "types": {...}}` or flat — pick a shape,
    pin it, document it in the ticket report.
- [ ] Feature tests.

## Out of scope
- Diff of item histories/attachments (not in the dumps); UI (the endpoint
  serves the app/web); three-way merges; comparing backups of different teams
  (403) or a backup against live data (out of the ask — create a fresh
  savepoint instead).

## Acceptance criteria
- [ ] Deterministic fixture: create backup A, mutate (add item, delete item,
      rename item, move item, add+remove label), create backup B → comparison
      reports exactly those events per type with `{from, to}` values matching
      the mutations; untouched rows counted `unchanged`.
- [ ] Symmetry check pinned: comparing B→A reports the inverse sets.
- [ ] Foreign-team pair → 403; unknown id → 404; read-only token allowed.
- [ ] Large-ish team (a few hundred rows) comparison completes without
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
