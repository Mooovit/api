---
id: API-007
title: "Bulk move / bulk assign endpoints"
type: feature
priority: P1
status: ready
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
- [ ] `POST api/item/bulk-move` — body `{ids: string[], parent_id: string|null}`.
      Moves every item; `null` detaches to root.
- [ ] `POST api/item/bulk-assign` — body `{ids: string[], status_id, location_id}`
      (the Transport payload).
- [ ] Response: `{"results": [{id, ok, updated_at?, error?}, ...]}` — one entry per
      requested id, same order. Successful entries carry the fresh `updated_at`
      (clients adopt it, MV-014 style); failures carry a short `error` string
      (`"not_found"`, `"foreign_team"`, `"cycle"`, …). HTTP 200 even with partial
      failures.
- [ ] One DB transaction for the applied subset; **one history row per item** (keep
      per-item granularity, reuse `recordItemChanges`-style records: `parent_id` row for
      move; `status_id` + `location_id` rows for assign — only for fields that actually
      change).
- [ ] Validation: `ids` required array, 1–500 entries, string ids; unknown /
      foreign-team / self-cycle ids become `ok:false` rows (never abort the batch);
      `parent_id` exists + same team + not among `ids` + not a descendant of any moved
      item (bulk version of API-004's cycle rule).
- [ ] Authorization once per request: `item:write` team permission on the target team +
      `tokenCan('item:write')`.
- [ ] Revision counter bump once per request (API-003 helper) — not per item.
- [ ] Feature tests.

## Out of scope
- Bulk create/delete/rename (add verbs when a flow needs them); client batch-queue
  changes (Android follow-up); background/queued processing (request stays synchronous,
  500 ids is small).

## Acceptance criteria
- [ ] 50-item bulk-assign → 1 request, 50 history rows total, all items updated.
- [ ] Mixed batch (some foreign-team ids) → per-row `ok`/`error`, valid rows applied.
- [ ] Moving [a,b] under a (through the cycle rule) → `cycle` error rows, nothing applied.
- [ ] `updated_at` in results matches the DB rows; absent for failed rows.
- [ ] No PATCH route involved; existing single-item endpoints untouched.

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
