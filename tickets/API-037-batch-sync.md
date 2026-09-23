---
id: API-037
title: "Batch sync — POST api/sync/batch (ordered offline mutations, three-way merge, batch receipts)"
type: feature
priority: P1
status: in-review        # ready | in-progress | blocked | in-review | done
depends_on: [API-003, API-015, API-032, API-033]
spec: "server.md §2 (batch sync); plan.md §4.14 (Android PendingOp queue — app repo, not edited); client side: to be ticketed (MV)"
---

# API-037 — Batch sync (`POST api/sync/batch`)

## Context

Phones push offline mutations one-by-one (the intent verbs of API-004/032,
label attach/detach, item create/delete) and every request counts against the
global 60 req/min per-user limiter — a device coming back from a day in a
warehouse basement burns its whole rate budget on the push and then cannot
pull. The user (2026-09-22): "create a route to accept a batch of changes,
applied one by one … return a batch id, and a way of listing/confirming all
operations in the batch so the phones can know that it's good. The phone will
send the current revision it's on — if there's a new revision on an item,
there's a conflict; the backend will try to auto-resolve it if possible, and
if not, it sends the conflict back to the app. Auto-resolve = one device
changed the name, the other the location → fine; two devices changed the same
field and one is not up to date → conflict, the user decides."

Built on API-003 (team revision counter), API-033 (`items.sync_revision`
stamps + `X-Revision`), API-015 (effective team per token) and API-032
(pick/unpick semantics). The Android queue this targets is the PendingOp FIFO
of plan.md §4.14 (MV-108); the client side is ticketed separately (MV).

## Scope — Must have

- [x] `POST api/sync/batch` (auth:sanctum): `operations[]` of 1..200, each
      `{op, base_revision, item_id?, changes?, base?, label_id?}` with `op`
      ∈ create | rename | move | assign | pick | unpick | delete |
      label_add | label_remove. Envelope validated first (422 persists
      nothing); deeper per-op problems are per-op `error` results — one
      malformed op never sinks the batch.
- [x] Ops applied ONE BY ONE in submission order, each in its own DB
      transaction with per-op isolation; a `SyncBatchOperation` receipt row
      (payload, result, detail, applied_at) persists for every op.
- [x] `SyncBatch`/`SyncBatchOperation` tables (uuid PKs, cascade on
      team/user, **no revision observer** — recording the receipt must not
      bump the sync cursor) + models.
- [x] `GET api/sync/batch/{batchId}` — reconciliation: totals + per-op
      receipts replayed from persistence; foreign-team batch = 404 (no
      existence leak).
- [x] Three-way merge on field ops: when the item's `sync_revision` moved
      past the op's `base_revision` (excluding stamps THIS batch produced —
      the phone's own earlier ops are not divergence), per field:
      server == base → merge; server == mine → noop; else → conflict
      `{base, yours, server}`. Atomic per op — any conflict applies
      nothing, zero bumps.
- [x] All 9 op types with single-endpoint semantics: create (mirror
      store), rename/move (incl. cycle guard), assign, pick (re-pick
      refreshes), unpick (already-clean = noop), delete (mirror destroy:
      child history rows + mass re-parent stamp + soft delete,
      `detached_ids` in the receipt), label_add/label_remove (idempotent
      noops instead of the single endpoint's 409 / unconditional bump).
- [x] Response: `{batch_id, results:[{index, op, item_id, result, item?,
      conflicts?, error?}], revision}` + `X-Revision` header; result ∈
      applied | conflict | noop | error.
- [x] Per-op error codes: `not_found`, `foreign_team`, `cycle`,
      `bad_reference`, `has_parent`, `label_not_found`, `invalid_op`,
      `exception`.

## Out of scope

- Same-batch create→reference (an op referencing an item created earlier in
  the SAME batch) — the phone cannot know server UUIDs before the create
  round-trips; ops may only reference items that existed at submission.
- Any change to the single-item endpoints or their semantics (the batch is
  additive; the phone migrates op-by-op).
- A dedicated batch rate limiter — the ONE request consumes one of the
  global 60 req/min like any other; that is the whole point.
- Server-side resolution UI for reported conflicts (the app owns the user
  decision; the server just reports `{base, yours, server}`).
- Status/location/label catalogue writes in the batch (items only —
  catalogues are small and rarely offline-dirty).

## Acceptance criteria

- [x] A 5-op field batch (rename, move-to-root, assign, pick, unpick)
      returns every op `applied`, bumps the team revision exactly 5 times
      and writes exactly 6 history rows (assign = 2 fields).
- [x] Same-field divergence (other device renamed, phone renames on a
      stale base) → `conflict` with `{base, yours, server}`; nothing
      applied, zero bumps, zero history rows.
- [x] Cross-field divergence (other device changed status, phone renames
      on a stale base) → merged `applied`; one history row; both changes
      survive.
- [x] Diverged-but-same-value → `noop` (no bump, no history).
- [x] Idempotent noops: unpick-when-clean, rename-to-current-name,
      duplicate label_add, absent label_remove, delete-of-deleted — all
      `noop` with zero bumps.
- [x] Delete replicates destroy: children re-parented to root with one
      history row each, `detached_ids` persisted in the receipt; the
      delete itself ignores a diverged base_revision (deletes converge —
      documented last-writer-wins).
- [x] Label attach/detach bump + stamp the item (visible in the
      `?since_revision` delta); child item → `has_parent`; foreign label →
      `label_not_found`.
- [x] A bad op between two good ones → both good ops apply, HTTP 200.
- [x] Envelope 422s (empty ops, >200 ops, unknown op, missing
      base_revision/item_id) persist no `sync_batches` row.
- [x] GET returns the persisted receipts (incl. per-op `detail`) + totals;
      foreign team → 404.
- [x] Read-only member / write-ability-less token → 403; unauthenticated
      → 401.
- [x] Receipt writes never bump the counter (response `revision` ==
      `teams.revision` after the request).

## Technical notes

- **Same-batch self-divergence**: all queued ops carry the phone's last
  sync `base_revision`, but the batch's own applied ops move the item past
  that point. `store()` records every revision value the batch produces and
  the divergence check excludes those stamps — otherwise op N on the same
  item as op K < N would self-conflict. A genuine third-party write between
  ops produces a stamp outside the set → normal three-way merge.
- Field comparisons normalize types: datetime fields compare by timestamp
  (the phone sends ISO-8601, the server holds Carbon), everything else by
  string, `null === null`.
- `required_unless:operations.*.op,create` evaluates per-item with
  wildcards on this Laravel version (verified) — creates are exempt from
  `item_id` at the envelope level.
- The private helpers (`recordItemChanges`, `wouldCycle`) are verbatim
  ports of ItemController's; `checkParents` became a boolean variant
  yielding per-op `bad_reference` instead of a request-level 404.
- Receipts keep the exact op payload (`payload` JSON) so support can replay
  what a phone claimed; `detail` is shaped per op (conflict fields,
  `detached_ids`, `{error: code}`).
- Files: `app/Http/Controllers/SyncBatchController.php`,
  `app/Models/SyncBatch.php`, `app/Models/SyncBatchOperation.php`,
  `database/migrations/2026_09_22_000001/000002_*`, `routes/api.php`.

## Tests

`tests/Feature/SyncBatchTest.php` (21 tests, 147 assertions) — pin revision
deltas and history-row counts exactly (ItemPickStateTest style):

happy paths (5-op field batch, create) / conflict matrix (same-field,
cross-field merge, diverged-same-value) / noop semantics / delete
(detach + receipt + delete-of-deleted) / labels (attach stamp + delta
visibility, duplicate noop, has_parent, foreign label) / per-op isolation /
error codes (not_found, foreign_team, cycle, bad_reference) / envelope 422s
/ GET receipts + totals / foreign-batch 404 / authorization matrix /
receipt-no-bump pin.

Run: `vendor/bin/phpunit --filter SyncBatchTest` then the full suite.

## Documentation requirements

- `server.md` §2: "Implemented 2026-09" API-037 entry — endpoints, op
  table, merge rules, result/error codes, envelopes, limits.
- `tickets/README.md` §2 mapping row.

## Implementation report

*(filled by the implementing agent — do not touch before starting work)*

### Blockers
- none.

### Changes
- Migrations `2026_09_22_000001_create_sync_batches_table` (uuid PK,
  team/user FKs, `total/applied/conflicted/noop/failed` counters, nullable
  `finished_at`, index(team_id, created_at)) and
  `2026_09_22_000002_create_sync_batch_operations_table` (uuid PK, batch FK
  cascade, `op`, `index`, nullable `item_id`, `payload` json, `result`,
  nullable `detail` json, nullable `applied_at`, index(batch_id, index)).
- Models `SyncBatch` (+`operations()` hasMany ordered by index, explicit
  `batch_id` FK — Laravel would derive `sync_batch_id` from the class name)
  and `SyncBatchOperation` (+ result constants). Both use the `Uuids` trait,
  are NOT observed by the revision machinery and have no SoftDeletes.
- `SyncBatchController`: `store()` (auth via effectiveTeam + item:write
  permission + ability; envelope validation; per-op `DB::transaction` with
  Throwable→`error` isolation; `ownRevisions` same-batch divergence
  exclusion; totals + `finished_at`; response + X-Revision), `show()`
  (team-scoped firstOrFail → 404; receipts + totals), `applyOp()` dispatch
  and per-type handlers (`opCreate`, `opDelete`, `opLabel`, `opField` with
  the three-way merge), verbatim `recordItemChanges`/`wouldCycle` ports,
  `fieldMatches()` type-normalizing comparison.
- Routes: `POST sync/batch`, `GET sync/batch/{batchId}` in the sanctum
  group under a fresh `sync` prefix (no {item}-style binding collisions).

### Files touched
- `database/migrations/2026_09_22_000001_create_sync_batches_table.php` (new)
- `database/migrations/2026_09_22_000002_create_sync_batch_operations_table.php` (new)
- `app/Models/SyncBatch.php` (new), `app/Models/SyncBatchOperation.php` (new)
- `app/Http/Controllers/SyncBatchController.php` (new)
- `routes/api.php`
- `tests/Feature/SyncBatchTest.php` (new)
- `server.md`, `tickets/README.md`, this ticket

### Tests run
```
vendor/bin/phpunit --filter SyncBatchTest → OK (21 tests, 147 assertions)
vendor/bin/phpunit → OK (453 tests, 2562 assertions, 4 pre-existing skips)
```

### Notes for reviewer
- The rate limiter is untouched: a batch is one request in the global
  60/min budget; with the 200-op cap one batch replaces up to 200 pushes.
- Conflicts are per-FIELD and per-OP: a mixed batch can apply some ops and
  report conflicts on others; the phone re-applies reported conflicts as
  fresh ops once the user decides (client ticket pending).
- `GET api/location`/`api/item` and every existing endpoint are untouched —
  the only shared file is `routes/api.php` (additive lines).
- Deletes ignore `base_revision` by design (documented LWW); everything
  else honors the merge. A phone that omits `base` values gets conflicts
  whenever an item diverged — sending per-field bases is what buys the
  auto-resolve.
- SQLite-safe migrations (no FK enforcement issues in the ALTER-free
  CREATEs); verified against the in-memory test DB via RefreshDatabase.
