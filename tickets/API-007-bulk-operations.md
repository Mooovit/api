---
id: API-007
title: "Bulk move / bulk assign endpoints"
type: feature
priority: P1
status: in-review
depends_on: [API-001]
spec: "server.md §3; client contract: plan.md §2"
---

# API-007 — Bulk move / bulk assign endpoints

## Context
The Transport flow assigns status/location by updating items one at a time, and an
MV-049 scan session queues one `MOVE` per scanned box — the sync engine replays them as
N requests. A 50-item transport is 50 updates (and 50 chances to fail on warehouse
Wi-Fi) where one request should do. Per-id results keep the app's per-row session model
working: partial failures are reported per row, not as one opaque 500.

## Scope — Must have
- [x] `POST api/item/bulk-move` — body `{ids: string[], parent_id: string|null}`.
      Moves every item; `null` detaches to root.
- [x] `POST api/item/bulk-assign` — body `{ids: string[], status_id, location_id}`
      (the Transport payload).
- [x] Response: `{"results": [{id, ok, updated_at?, error?}, ...]}` — one entry per
      requested id, same order. Successful entries carry the fresh `updated_at`
      (clients adopt it, MV-014 style); failures carry a short `error` string
      (`"not_found"`, `"foreign_team"`, `"cycle"`, …). HTTP 200 even with partial
      failures.
- [x] One DB transaction for the applied subset; **one history row per item** (keep
      per-item granularity, reuse `recordItemChanges`-style records: `parent_id` row for
      move; `status_id` + `location_id` rows for assign — only for fields that actually
      change).
- [x] Validation: `ids` required array, 1–500 entries, string ids; unknown /
      foreign-team / self-cycle ids become `ok:false` rows (never abort the batch);
      `parent_id` exists + same team + not among `ids` + not a descendant of any moved
      item (bulk version of API-004's cycle rule).
- [x] Authorization once per request: `item:write` team permission on the target team +
      `tokenCan('item:write')`.
- [x] Revision counter bump once per request (API-003 helper) — not per item.
- [x] Feature tests.

## Out of scope
- Bulk create/delete/rename (add verbs when a flow needs them); client batch-queue
  changes (Android follow-up); background/queued processing (request stays synchronous,
  500 ids is small).

## Acceptance criteria
- [x] 50-item bulk-assign → 1 request, 50 history rows total, all items updated.
- [x] Mixed batch (some foreign-team ids) → per-row `ok`/`error`, valid rows applied.
- [x] Moving [a,b] under a (through the cycle rule) → `cycle` error rows, nothing applied.
- [x] `updated_at` in results matches the DB rows; absent for failed rows.
- [x] No PATCH route involved; existing single-item endpoints untouched.

## Technical notes
- Route placement: `routes/api.php`, before `Route::resource('item', …)` so
  `item/bulk-move` isn't swallowed by the `{item}` binding (Laravel matches resource
  GET/POST paths — `POST api/item` is store, so define the bulk routes explicitly before
  it to be safe).
- Chunk the ids loop; 500 × (update + history insert) inside one transaction is fine on
  SQLite/MySQL.
- `bulk-move` with `parent_id: null` = root-detach; no cycle question then.

## Tests
`vendor/bin/phpunit --filter ItemBulkTest`:
- happy path move + assign (counts, history rows, response order);
- partial failure rows; foreign-team scoping; cycle/parent validations;
- >500 ids → 422; empty ids → 422.

## Documentation requirements
- PHPDoc on both controller methods; request/response example in `server.md` §3 (mark
  implemented); plan.md §2 contract notes (additive endpoints).

---

## Implementation report (2026-09)

**Status: done.** One commit, suite green (157 tests, 495 assertions, 4 pre-existing
Jetstream skips).

### Shipped
- `routes/api.php` — `POST item/bulk-move` + `POST item/bulk-assign` declared **before**
  `Route::resource('item', …)` (per the ticket note). POST-only, consistent with the
  no-PATCH host constraint.
- `ItemController::bulkMove()` / `bulkAssign()`:
  - Global `tokenCan('item:write')` check → 403; shape validation → 422 (`ids`
    required 1–500, `ids.*` string + distinct, field-specific exists rules).
  - Per-row loop in request order inside one `DB::transaction`: `not_found`
    (missing or trashed — `Item::find` respects the soft-delete scope),
    `foreign_team` (caller lacks item:write on the item's team, or item's team ≠
    target team), `cycle` (move only). Failures never abort the batch; HTTP 200
    with partial failures.
  - Target team: bulk-move → the parent's team (trashed parent → 404 via global
    scope, matching the single move verb); bulk-assign → the status's team, with a
    422 when status and location teams differ.
  - Cycle rules: `parent_id ∈ ids` → batch-level `cycle` (every row, nothing
    applied); `parent_id` a descendant of a moved item → `cycle` row for that item
    only (new `wouldCycle()` row-level helper, refactored out of API-004's
    `ensureNoCycle`, which now delegates to it — single-verb behavior unchanged).
  - Applied rows: explicit `History` rows per actually-changed field
    (`recordItemChanges(..., onlyPresentKeys: true)`), then a **mass update** with
    explicit `updated_at` — mass updates fire no model events, so the API-003
    observer does NOT bump per item; instead one
    `Team::whereIn('id', …)->increment('revision')` per affected team at the end of
    the transaction.
  - Response rows: `{id, ok: true, updated_at}` (the batch's `appliedAt` Carbon →
    serialized in the same ISO-8601 format as models) / `{id, ok: false, error}`.
- `tests/Feature/ItemBulkTest.php` — 13 tests: move happy path (order, history per
  item, single revision bump), root-detach via `parent_id: null`, assign happy path
  (2 history rows per item), mixed batch (`foreign_team` + `not_found` rows while the
  valid row applies, 200), batch-level cycle (`parent_id ∈ ids` → nothing applied, no
  history, no bump), descendant cycle (only the ancestor row fails, others apply),
  trashed parent → 404, cross-team status/location → 422, empty ids → 422, 501 ids →
  422, duplicate ids → 422, 403 without the token ability, `Read Only` member →
  `foreign_team` rows.
- `server.md` §3 — "Implemented (API-007)" banner with the wire contract.

### Design notes / deviations
- "Authorization once per request" is interpreted as: the token ability (`tokenCan`)
  is checked once (403); team permission is enforced **per row** — a batch spanning
  teams (or a `Read Only` member targeting their team) yields per-row `foreign_team`
  results instead of one global 403, which is what keeps the per-row session model
  working. Documented in the PHPDoc.
- Revision bump: `TeamRevision::bump()` is per-model; the bulk path uses the same
  mechanism as one `whereIn` increment per affected team (one query per request),
  matching the ticket's "once per request — not per item".
- `updated_at` bumps even for rows whose field values didn't change (e.g. re-assigning
  the same status) — they are successful writes and carry a fresh `updated_at`, but no
  history row (history only tracks real changes, as everywhere else).

### Verification
- `vendor/bin/phpunit --filter ItemBulkTest` → 13 tests, 61 assertions, green.
- Full suite → **157 tests, 495 assertions, 4 skipped — PASS**.

### Reviewer notes
- Test gotcha worth remembering (bites any new test): factory-created items bump the
  revision on `created`, so bulk tests assert **relative** deltas
  (`$revisionBefore ± n`), never absolute values.
- `exists:items,id` on `parent_id` doesn't exclude trashed rows on Laravel 8 (see
  API-005 report) — the trashed-parent 404 comes from `findOrFail` after validation.
