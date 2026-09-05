---
id: API-009
title: "Team-level activity feed endpoint"
type: feature
priority: P2
status: in-review
depends_on: [API-001]
spec: "server.md §6; client contract: plan.md §2"
---

# API-009 — Team-level activity feed endpoint

## Context
History exists only per item (`GET api/item/:id/history`). Everything the Android app
added recently — timeline upgrade (MV-048), dashboards (MV-047), location audit
(MV-050) — has to derive "what happened in this team/location" from the full items
table, which is inference, not fact. The `histories` table is already team-wide in
nature (rows carry `item_id`); this ticket exposes it as a filterable feed.

## Scope — Must have
- [x] `GET api/activity` (auth:sanctum) — the same change rows as item history, plus
      the `item_id` they belong to and the item's `name`, newest first (`changed_at`
      desc, `id` as tiebreaker), paginated.
      - Query params: `since` (ISO-8601), `item_id`, `location_id`, `type`
        (= `field_name`: name|location_id|status_id|parent_id), `page`/`per_page`
        (default per_page 50, max 200).
      - `location_id` filter semantics: rows describing moves *into* that location —
        `field_name = 'location_id' AND new_value = <location_id>` (covers "recent moves
        in location X" for MV-050 post-audit traces).
- [x] Team scoping: join `histories` → `items` and filter `items.team_id` = current
      team. Authorization like other reads (`item:read` + team permission).
- [x] Response shape per row:
      `{id, item_id, item_name, user_id, user_name, field_name, old_value, new_value,
      changed_at}`.
- [x] Migration: index on `histories.changed_at` (missing today — the kanban activity
      page already orders by it, `KanbanController::getRecentHistory`).
- [x] Feature tests.

## Out of scope
- Audit rows in the feed (API-010 will emit into it); label attach/detach history rows
  (label changes are not audited today — potential future ticket); websocket/push;
  replacing `api/item/:item/history` (keep it working as-is; it can become a filtered
  view of this query later, not in this ticket).

## Acceptance criteria
- [x] Feed returns rows from all team items newest-first, paginated; page 2 continues
      cleanly.
- [x] Each filter (`since`, `item_id`, `location_id`, `type`) narrows correctly; combos
      work (`?location_id=X&type=parent_id`).
- [x] Foreign-team rows never leak; 403 without `item:read`.
- [x] Existing kanban/activity pages unaffected (read-only addition + one index).

## Technical notes
- `old_value`/`new_value` are stored as strings (nullable text) — for `*_id` fields they
  are raw UUIDs; the client resolves names (matches how item history works today, so
  MV-048's renderer can be reused). Optionally include resolved `old_name`/`new_name`
  via a lookup for `location_id`/`status_id` fields — nice-to-have, keep additive if
  added.
- `changed_at` is a separate column from `created_at` (set via `now()` at write) —
  order by `changed_at`, but note both exist.
- Query pattern: `History::query()->join('items', ...)->where('items.team_id', ...)` or
  `whereHas('item')` — join + select is friendlier for the index.

## Tests
`vendor/bin/phpunit --filter TeamActivityFeedTest`:
- ordering + pagination; each filter; combo filters;
- team scoping (two teams interleaved writes);
- permission matrix; rows include `user_name` and `item_name`.

## Documentation requirements
- PHPDoc on the controller; `server.md` §6 mark implemented + response example;
  plan.md §2 contract notes (additive endpoint).

---

## Implementation report (2026-09)

**Status: done.** One commit, suite green (175 tests, 577 assertions, 4 pre-existing
Jetstream skips).

### Shipped
- `app/Http/Controllers/ActivityController.php` (new) — `GET api/activity`:
  - Authorization mirrors the other reads: membership on the token's current team +
    `item:read` team permission + token ability (403 otherwise).
  - `whereHas('item', team_id)` for scoping — the Item global scope (SoftDeletes)
    applies inside, so **trashed items' histories are excluded** (consistent with
    `api/item/:id/history`, which 404s for trashed items; pinned as deliberate).
  - Eager-loads `item:id,name` + `user:id,name`; `paginate()` +
    `through()` maps each row to the contract shape exactly — `changed_at` goes
    through the Eloquent datetime cast, so it serializes as ISO-8601 like item
    history does (a raw join would have leaked the DB's `Y-m-d H:i:s` format —
    deviation from the ticket's join suggestion, documented below).
  - Filters: `since` (strict `>` on `changed_at`), `item_id`, `type`
    (validated `in:name,location_id,status_id,parent_id`), `location_id`
    (`field_name='location_id' AND new_value=<id>` — moves INTO), `page`,
    `per_page` (default 50, max 200). Validation failures → 422.
  - Ordering: `changed_at` desc, `id` desc tiebreaker.
- `routes/api.php` — `Route::get('activity', …)` inside the sanctum group.
- `database/migrations/2026_09_05_000004_index_histories_changed_at.php` —
  `histories.changed_at` index (the kanban `getRecentHistory` sort benefits too).
- `tests/Feature/TeamActivityFeedTest.php` — 10 tests: newest-first + row contract
  (names resolved), pagination across 3 pages with no overlap + `total`, `since`,
  `item_id`, `type`, `location_id` (into-only: a move-out row and a status row don't
  match), combo filters, team scoping, 403 without `item:read`, 422 matrix (bad
  `type`, garbage `since`, `per_page=500`).
- `server.md` §6 — "Implemented" banner with the full contract.

### Design notes / deviations
- **`whereHas` + eager-load instead of a raw join**: the ticket suggested a join for
  index friendliness, but a join returns query-builder rows whose `changed_at` skips
  the Eloquent datetime cast (DB format, not ISO-8601 — would violate the API-002
  timestamp contract). `whereHas` + eager-load keeps the cast and still uses the new
  `changed_at` index for ordering; team sizes are small, the subquery cost is fine.
- `old_name`/`new_name` resolution was not added (ticket called it optional
  nice-to-have; raw values match item history today).
- Trashed-item exclusion is a decision, not an oversight — recorded in the PHPDoc and
  server.md §6.

### Verification
- `vendor/bin/phpunit --filter TeamActivityFeedTest` → 10 tests, 43 assertions, green.
- Full suite → **175 tests, 577 assertions, 4 skipped — PASS**.

### Reviewer notes
- API-010 (audit endpoint) will append rows to this feed — the feed's `type` filter
  whitelist may need a new value when audit rows land; revisit then.
- Pagination uses Laravel's standard LengthAwarePaginator JSON shape
  (`data`, `current_page`, `total`, …) — additive endpoint, no existing client
  depends on it.
