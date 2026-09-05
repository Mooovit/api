---
id: API-009
title: "Team-level activity feed endpoint"
type: feature
priority: P2
status: ready
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
- [ ] `GET api/activity` (auth:sanctum) — the same change rows as item history, plus
      the `item_id` they belong to and the item's `name`, newest first (`changed_at`
      desc, `id` as tiebreaker), paginated.
      - Query params: `since` (ISO-8601), `item_id`, `location_id`, `type`
        (= `field_name`: name|location_id|status_id|parent_id), `page`/`per_page`
        (default per_page 50, max 200).
      - `location_id` filter semantics: rows describing moves *into* that location —
        `field_name = 'location_id' AND new_value = <location_id>` (covers "recent moves
        in location X" for MV-050 post-audit traces).
- [ ] Team scoping: join `histories` → `items` and filter `items.team_id` = current
      team. Authorization like other reads (`item:read` + team permission).
- [ ] Response shape per row:
      `{id, item_id, item_name, user_id, user_name, field_name, old_value, new_value,
      changed_at}`.
- [ ] Migration: index on `histories.changed_at` (missing today — the kanban activity
      page already orders by it, `KanbanController::getRecentHistory`).
- [ ] Feature tests.

## Out of scope
- Audit rows in the feed (API-010 will emit into it); label attach/detach history rows
  (label changes are not audited today — potential future ticket); websocket/push;
  replacing `api/item/:item/history` (keep it working as-is; it can become a filtered
  view of this query later, not in this ticket).

## Acceptance criteria
- [ ] Feed returns rows from all team items newest-first, paginated; page 2 continues
      cleanly.
- [ ] Each filter (`since`, `item_id`, `location_id`, `type`) narrows correctly; combos
      work (`?location_id=X&type=parent_id`).
- [ ] Foreign-team rows never leak; 403 without `item:read`.
- [ ] Existing kanban/activity pages unaffected (read-only addition + one index).

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
