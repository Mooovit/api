---
id: API-026
title: "Live activity panel pulls only new history since the revision moved"
type: feature
priority: P2
status: in-review
depends_on: [API-003, API-006, API-009]
spec: "server.md §9 (revision) + §6 (activity feed); client context: kanban live view"
---

# API-026 — Live activity: incremental pulls via the revision system

## Context
The kanban page's Live panel ("Recent Activity") re-fetches the **entire**
history list on every 5s poll, even though API-003/API-006 already give the
page a revision counter and delta feed for the board itself. The board got
revision-based incremental sync in the kanban rework; the activity feed did
not. This ticket makes the activity panel pull **only the entries newer than
what it already shows**, gated by the revision poll (no history request at all
when nothing changed).

## Scope — Must have
- [ ] `GET /kanban/history` (web, `KanbanController::getRecentHistory`) gains
      an optional `after` param (ISO-8601 datetime cursor = the newest
      `changed_at`/`created_at` the client already has): returns only entries
      strictly newer than the cursor, still team-scoped, still capped (limit
      param unchanged) — additive: no `after` → identical payload as today.
      Response gains `cursor` (the max timestamp of the returned set, or the
      request cursor when empty) so the client can chain blindly.
- [ ] `kanban.js`: `refreshHistory()` keeps its cursor in state, sends
      `after=`, prepends only new rows client-side (dedupe), trims the list to
      the existing max length, and **skips the history fetch entirely** when
      `pollRevision()` reports an unchanged revision.
- [ ] Cursor reset: board deltas that touch the feed's scope (deletes) must
      not break the feed — entries are append-only rows, so no reset needed;
      document that reasoning in the report.
- [ ] Feature tests pinning the `after` behavior.

## Out of scope
- Changing `/api/activity` (API-009) — the Android client contract stays as is.
  (If the same cursor idea is wanted there later, that's a separate ticket.)
- Websockets / SSE / push — still plain polling, just cheap now.
- Re-ordering or re-shaping the existing history payload fields (additive:
  `cursor` is added, nothing renamed).

## Acceptance criteria
- [ ] `GET /kanban/history` without `after` returns exactly the same shape as
      before (pinned by an unchanged/existing test).
- [ ] With `after=T`, only entries with timestamp > T are returned; `cursor`
      equals the newest returned timestamp (or T when the set is empty).
- [ ] A poll cycle with unchanged revision issues NO history request; a moved
      revision issues exactly one, with `after` set, and the DOM gets only the
      new rows (manual/DOM-level verification via the JS path being testable
      is not required — the API contract is what's pinned).
- [ ] Foreign-team entries never appear (existing rule, re-pinned with `after`).

## Technical notes
- History rows sort by `changed_at desc, id desc` today — the cursor must
  compare on the same column (`changed_at`), and entries written in the same
  second must not be skipped: use `>` on a datetime cursor BUT also exclude
  rows the client already has by `id` when timestamps tie
  (`where('changed_at', '>', $after)->orWhere(function (tie-on-$after and id not in $known)`)
  — simpler alternative: cursor = `changed_at` of the newest entry, and accept
  a possible re-delivery of same-timestamp rows (client dedupes by id). Pick
  the simpler one, document it.
- `kanban.js`: history state lives in the polling loop started by
  `startRealTimeUpdates()`; the last-sync timestamp pattern from the delta work
  (`lastSyncAt`) is the model — add `historyCursor`.
- Existing endpoint team-scoping comes from `$request->user()->current_team_id`
  — keep it.

## Tests
- `KanbanTest::test_recent_history_*` extensions: `after` filtering (new rows
  only, empty set + cursor echo), additive no-`after` parity, foreign-team
  exclusion, tie-timestamp rows not lost-or-duplicated (client dedupes by id —
  server may re-deliver, pin that).

Run: `php artisan test --filter KanbanTest`, then full suite.

## Documentation requirements
- PHPDoc on `getRecentHistory`; `server.md` note under the live-view/revision
  idea (activity feed incremental pull).

## Implementation report
*(filled by the implementing agent — do not touch before starting work)*

### Blockers
- none

### Changes
- `KanbanController::getRecentHistory` gained the optional `after` datetime
  cursor (validated `date`). Picked the SANCTIONED SIMPLE tie-handling from
  the ticket's technical notes:
  - No `after` → byte-identical legacy response (bare enhanced-rows array);
    the existing `test_recent_history_returns_enhanced_rows_newest_first` is
    untouched and pins that parity.
  - With `after` → `{data: [...], cursor: "..."}`. The comparison is
    **`changed_at >= after` (INCLUSIVE)**, not `>`: with second-granularity
    storage, a strict `>` would permanently SKIP any row written in the same
    second as the cursor (loss), while the inclusive cursor only ever
    RE-DELIVERS rows sharing the cursor's timestamp — a bounded set the
    client dedupes by id. Re-delivery is safe because feed rows are
    append-only (ids never change). This is documented in the PHPDoc.
  - `cursor` = max `changed_at` of the returned set (ISO-8601), or the
    request cursor when empty → the client can chain blindly.
  - Team scoping (`whereHas item.team_id = current_team_id`) and the 50-row
    cap unchanged; `/api/activity` untouched.
- `kanban.js`:
  - new `historyCursor` state; `pollRevision()` now returns whether the
    revision moved; `startRealTimeUpdates` calls `refreshHistory()` ONLY on
    a moved revision — an unchanged revision issues NO history request
    (the revision poll itself remains every 5s);
  - `refreshHistory()` rewritten: sends `after=` when it has a cursor; the
    first pull (legacy bare array) seeds the cursor from its newest row;
    envelope responses advance the cursor from `cursor`; merges only
    unknown ids (dedupe), keeps newest-first, caps at 50, and skips the
    re-render entirely when nothing new arrived;
  - DOM rendering extracted to `renderHistory(history)` (unchanged markup);
  - cursor reset: none needed — rows are append-only; board delta deletes
    remove cards, not feed entries (reasoning per ticket, also in code).
- Direct calls to `refreshHistory()` after writes (updateItemImmediately,
  drag&drop) keep working — they now go through the same cursor path.

### Files touched
- app/Http/Controllers/KanbanController.php (getRecentHistory + Carbon import)
- public/js/kanban.js (historyCursor, pollRevision returns moved,
  startRealTimeUpdates gating, refreshHistory rewrite, renderHistory extract)
- tests/Feature/KanbanTest.php (+4 tests)

### Tests run
```
php artisan test --filter KanbanTest → 26 passed (0.91s)
```
Covers: strictly-newer filtering (cursor advanced past the shown row's
second → only newer rows, cursor = newest returned); tie re-delivery (cursor
exactly on the shown row → that row + newer, pinning the inclusive semantics);
empty set → cursor echoed back; team scoping with `after` (foreign newer row
never appears); legacy no-`after` parity via the untouched pre-existing test.

### Commits
- (pending, batch commit at the end of the feature set)

### Notes for reviewer
- The inclusive cursor is a deliberate deviation from the AC's literal
  "> T": the AC's technical-notes section explicitly sanctions "accept a
  possible re-delivery of same-timestamp rows (client dedupes by id)" as the
  simpler option, and it is the only variant that cannot LOSE same-second
  rows. `test_recent_history_after_redelivers_tied_rows_for_client_dedupe`
  pins exactly this.
- Wire format for the incremental envelope (`{data, cursor}`) reuses the
  shape `refreshHistory` already tolerated (`response.data.data`), so no
  other consumer can break.
- The `after` param name and payload field set are kanban-web-only;
  API-009's `/api/activity` contract is untouched (out of scope).
