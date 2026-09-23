---
id: API-027
title: "Deleted statuses/locations stay resolvable — trashed rows exposed in the catalogue APIs, history names survive"
type: feature
priority: P1
status: in-review
depends_on: [API-005, API-009]
spec: "— (user request: keep deleted locations/statuses visible in history, Matthieu 2026-09)"
---

# API-027 — Deleted statuses/locations stay resolvable

## Context
Statuses and locations already soft-delete (deleted_at columns + SoftDeletes
from the kanban rework), but the catalogue APIs never TELL anyone: the
`api/status` and `api/location` indexes only return live rows, so a client
that still holds history rows referencing a deleted location has no way to
render its name — server-side resolvers (kanban history, activity audit rows)
blank out too. The app resolves history value names itself from its local
catalogue (`/locations`, `/statuses`), so the catalogue must include destroyed
rows, flagged by `deleted_at`, while everything else treats them as deleted.

## Scope — Must have
- [ ] `GET api/location` and `GET api/status` indexes include soft-deleted
      rows (withTrashed) — trashed rows serialize with a non-null
      `deleted_at`; live rows keep `deleted_at: null`. Additive: no shape
      changes otherwise.
- [ ] `GET api/location/{location}` / `api/status/{status}` (route binding)
      stay default-scoped — a trashed row is a plain 404 (clients sync from
      the index).
- [ ] Server-side name resolution keeps working for deleted rows (history
      must stay readable): kanban `resolveValueToName` (status/location/parent
      finds), the kanban history + activity plucks, board/delta/search/details
      eager loads (cards and item payloads keep the deleted location's NAME),
      and the API-009/010 activity audit hydration (`location_name`).
- [ ] Writes reject deleted ids: `assign`, `bulk-assign`, `store`, `update`
      (item API) and the kanban barcode update no longer accept a trashed
      status_id/location_id (`exists` rules get `whereNull('deleted_at')` —
      today a trashed id silently passes because the validator has no scope).
      Transfer/bulk-move are already scoped at the Eloquent lookup layer.
- [ ] Kanban board columns (the board's own status/location models) stay
      live-only — a deleted status disappears as a column.
- [ ] Feature tests pinning all of the above.

## Out of scope
- Restore endpoints (un-delete) — not asked.
- Labels (no soft deletes) and items' parent_id exists rule (items have their
  own deletion policy, API-008).
- The public share page (`/share/{token}`) — live-only chips stay.
- The Android API contract for item payloads (raw ids; the app resolves names
  from the now trashed-inclusive catalogue — that's the design).

## Acceptance criteria
- [ ] After deleting a location, `GET api/location` still lists it with
      `deleted_at` set; the app can flag it deleted and keep resolving names.
- [ ] After deleting a status/location, kanban history (`/kanban/history`,
      `GET /kanban/item/:id`, `/kanban/activity`) still shows the NAMES, not
      raw uuids; API-009 activity audit rows keep `location_name`.
- [ ] A board card sitting in a deleted location still shows that location's
      name; the deleted status/location vanish as board columns.
- [ ] `POST api/item/{item}/assign` (and store/update/bulk-assign/barcode
      update) with a deleted status_id/location_id → 422; live ids unchanged.
- [ ] Show/route-binding of a trashed status/location → 404.

## Technical notes
- `Rule::exists('statuses', 'id')->whereNull('deleted_at')` at the five
  validation spots; the transfer verb and the barcode update's Eloquent
  re-lookups already reject trashed (global scope), so only the `exists`
  layer needs the tightening.
- withTrashed eager loads: `->with(['items.location' => fn ($q) => $q->withTrashed()])`
  etc. — nested per-relation closures on the board/delta/search/details paths.
- History rows themselves are untouched (raw ids + `*_value_name` where the
  kanban resolves server-side; `/api/item/:id/history` and `/api/activity`
  history rows carry raw ids by contract — the app resolves from the
  catalogue).

## Tests
- `StatusApiTest` / `LocationApiTest`: trashed-in-index with `deleted_at`
  flags; trashed show → 404.
- `KanbanTest`: history names survive deletion (history endpoint + item
  details); board card keeps deleted location name while columns stay live.
- `ItemApiTest`: assign/store/update/bulk-assign with trashed ids → 422.
- Activity: audit `location_name` survives location deletion.

Run: `php artisan test --filter "StatusApiTest|LocationApiTest|KanbanTest|ItemApiTest"`, then full suite.

## Documentation requirements
- PHPDoc on the touched index methods; server.md note (catalogue APIs carry
  tombstones; deleted ids are write-rejected).

## Implementation report
*(filled by the implementing agent — do not touch before starting work)*

### Blockers
- none

### Changes
- `StatusController::index` / `LocationController::index`: `withTrashed()`
  on the team-scoped query — the catalogue now carries tombstones flagged by
  the serialized `deleted_at` (ISO timestamp when deleted, null when live).
  show()/route bindings untouched (trashed → 404, pinned by tests).
- `KanbanController`:
  - `resolveValueToName`: `Status::withTrashed()->find` /
    `Location::withTrashed()->find` / `Item::withTrashed()->find` (parent_id)
    — history values resolve to names even when the referenced row was
    deleted since (previously fell back to the raw uuid);
  - `getRecentHistory` + `activity()` name plucks: status/location plucks
    withTrashed (labels have no soft deletes — untouched);
  - board (`index`), `delta`, `search`, `getItemDetails` eager loads nest
    withTrashed closures for status/location — board cards, delta rows,
    search results and the details modal keep the deleted row's NAME; the
    board's own column queries stay default-scoped (deleted columns vanish).
- `ActivityController`: audit hydration eager-loads `location` withTrashed —
  stocktake rows keep `location_name` after the location is deleted. History
  rows keep carrying raw ids (contract; app resolves from the catalogue).
- Write hardening (`Rule::exists(...)->whereNull('deleted_at')`): item
  `store`, `update`, `assign`, `bulk-assign` + kanban `updateItemByBarcode`
  now 422 on a trashed status_id/location_id (previously the validator's
  `exists` — scope-blind — silently accepted deleted ids; bulk-assign then
  died on a confusing 404 from findOrFail). `transfer`/`bulk-move` were
  already scoped at their Eloquent lookups — untouched.

### Files touched
- app/Http/Controllers/StatusController.php (index withTrashed)
- app/Http/Controllers/LocationController.php (index withTrashed)
- app/Http/Controllers/KanbanController.php (resolver, plucks, eager loads,
  barcode validation)
- app/Http/Controllers/ItemController.php (4 exists rules hardened)
- app/Http/Controllers/ActivityController.php (audit location withTrashed)
- tests: StatusApiTest (+1), LocationApiTest (+2), KanbanTest (+2),
  ItemApiTest (+1), LocationAuditTest (+1)

### Tests run
```
php artisan test --filter "StatusApiTest|LocationApiTest|KanbanTest|ItemApiTest|LocationAuditTest"
→ 87 passed (2.99s)
php artisan test → 358 passed, 4 skipped (pre-existing skips), 0 failures
```

### Commits
- (pending, batch commit)

### Notes for reviewer
- Deliberate split: the CATALOGUE apis carry tombstones (clients flag them
  deleted and keep resolving names — the user's app-side plan), while every
  WRITE path rejects tombstoned ids and route bindings stay default-scoped.
- Board cards keeping a deleted location's name is intentional ("where is
  this box?" still answers); the kanban management modals stay live-only
  because their selects come from the default-scoped view data.
- The `deleted_at` flag rides the existing model serialization — no payload
  shape change beyond rows that used to disappear entirely.
- CONSCIOUS TEST UPDATE: the pre-existing
  `test_destroy_soft_deletes_and_hides_from_index` in StatusApiTest and
  LocationApiTest pinned the OLD semantics (index count 0 after destroy);
  both were renamed to `..._and_the_index_carries_the_tombstone` and now pin
  the tombstone-in-index + show-404 contract. The activity test file is
  `LocationAuditTest` (not "ActivityApiTest" as first sketched).
