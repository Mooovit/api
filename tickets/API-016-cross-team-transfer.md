---
id: API-016
title: "Cross-team item transfer"
type: feature
priority: P2
status: in-review
depends_on: [API-001, API-011]
spec: "user request (Matthieu, 2026-09): move an item to another team with permission checks on both sides + documentation"
---

# API-016 — Cross-team item transfer

## Context
Items live scoped to a team, but boxes physically move between teams
(subsidiaries, customer-owned teams, a team per site). Today the only way is
export/re-import by hand. This ticket adds one endpoint that transfers an
item (with its subtree) to another team, with **permission checks on both
teams** and history/revision bookkeeping on both sides.

## Scope — Must have
- [ ] `POST api/item/{item}/transfer` — `{team_id: uuid, location_id?: uuid,
      status_id?: uuid}`. **Amended (Matthieu, 2026-09-05): the item's whole
      subtree transfers with it** — a parent link never crosses teams, so
      children and grandchildren come along; intra-subtree links are
      preserved and only the root is detached from its source-team parent.
  - requester needs `item:write` on the **source** team (the item's team) and
    on the **destination** team, via team permission **and** token ability;
  - `team_id` must exist and differ from the source (422 otherwise);
  - optional `location_id`/`status_id` (root only) must belong to the
    **destination** team (422 otherwise) — they are set in the same
    transaction as the move; when omitted they carry over; descendants keep
    their location/status;
  - the root's `parent_id` is detached to root on transfer (a parent link
    never crosses teams); one history row for it, same as API-008 semantics;
  - history rows for every changed field per item (`team_id` on every moved
    item + the root's `location_id`/`status_id`/`parent_id`);
  - team revision bumped on **both** teams (API-003);
  - labels attached to any moved item that belong to the source team are
    detached (destination labels are not auto-attached) — response reports
    them;
  - barcodes and attachments follow their item (source team's registry
    reservation moves with it) — if the destination team already holds one of
    the subtree's codes on an item outside the moved subtree, the transfer is
    refused 409 (registry uniqueness is per team);
  - trashed descendants are tombstones: they stay behind.
  - Response: the fresh root item (`show()` shape) plus additive transfer
    metadata: `{"detached_label_ids": [...], "detached_parent": bool}`.
- [ ] Documentation: `server.md` gains a §12 describing the endpoint (shape,
      permissions, side effects); the ticket itself documents the response.
- [ ] Feature tests.

## Out of scope
- Per-child destination location/status (a follow-up bulk-assign can
  re-point them); attachments: they follow the item (they are keyed by item,
  and their stored `team_id` is rewritten in the same transaction —
  documented); audit rows; moving locations/statuses between teams.

## Acceptance criteria
- [x] Happy path: item moves teams with supplied destination location/status;
      history rows exist for each changed field; both teams' revisions moved.
- [x] Permission matrix: missing `item:write` on source → 403; missing on
      destination → 403 (even with source permission); read-only member → 403.
- [x] Validation: unknown team 422; self-transfer 422; source-team
      location/status 422; destination code collision → 409 and **nothing**
      changed (transaction rolled back).
- [x] Labels of the source team detached and reported; parent detached to root.
- [x] Delta sync coherence: the subtree appears in the destination team's
      `?since=` feed as `changed`; the source's feed never carries it — its
      bumped revision sends source clients re-pulling (deviation from the
      original "(and in the source's)" wording, impossible with a
      team-scoped delta query — documented in the Report).
- [x] Additive-only: whole suite green.

## Technical notes
- One DB transaction around: item update, label detachments, barcode
  collision check, history rows, both revision bumps — any failure rolls back
  everything (pinned by the 409 test).
- `$item->team` is the source; resolve destination via `Team::findOrFail` with
  membership validation — never `currentTeam` (API-001-corrected pattern).
- The destination location/status validation must use the destination team id,
  not the item's current one.

## Tests
- New `tests/Feature/ItemTransferTest.php`: happy path (+ revisions +2 total,
  history rows), whole-subtree move (labels/barcodes/parent links, trashed
  descendant stays), permission matrix, validation matrix, barcode collision
  409 rollback, delta visibility, unknown item 404 / unauthenticated 401.

## Implementation Report

**Status**: done — 8 new tests in `tests/Feature/ItemTransferTest.php`, full
suite 251 tests / 1071 assertions / 4 skips (pre-existing Jetstream skips).
**Amended mid-implementation (Matthieu, 2026-09-05): the transfer moves the
item's whole subtree**, not a lone item.

**Production changes**
- `ItemController::transfer()` + `POST api/item/{item}/transfer` (with the
  other intent verbs; POST only — no PATCH on the host). Flow: source-side
  `item:write` check (resource team, API-001 pattern) → validate
  `{team_id, location_id?, status_id?}` → destination `Team::findOrFail` +
  self-transfer 422 + destination `item:write` check → destination-team
  ownership of the optional location/status → one transaction:
  1. subtree collection (`parent_id` BFS; SoftDeletes scope excludes trashed
     descendants — tombstones stay behind);
  2. registry collision check across ALL subtree codes vs the destination
     team's registry, excluding the subtree itself (409 sentinel, nothing
     written);
  3. history: one `team_id` row per moved item + root's `parent_id` detach
     (API-008 semantics) and optional `location_id`/`status_id` rows;
  4. source-team labels detached from every moved item (destination labels
     untouched, never auto-attached), ids reported;
  5. barcodes + attachments follow (`team_id` rewritten — attachments keep
     their stored `path`, downloads keep working; the path's embedded team id
     is cosmetic only);
  6. mass item updates (no model events) + explicit revision bumps on BOTH
     teams (API-003, once each);
  7. response: root in `show()` shape + additive `detached_label_ids` /
     `detached_parent`.

**Documented semantics**
- Descendants keep their location/status; the optional destination
  location/status applies to the root only — a follow-up bulk-assign
  (API-007) can re-point the rest.
- The source team's delta feed never carries transferred rows (they left its
  scope); its revision bump is the re-pull signal. The ticket's original
  "(and in the source's feed)" wording is unimplementable with a team-scoped
  delta query — corrected wording landed in server.md §12 and above.

**Test findings worth keeping**
- Sanctum's `RequestGuard` memoizes BOTH the resolved user AND the request:
  once any request in a test authenticates, all later requests stay
  authenticated even without the header — a 401 test needs
  `$this->app['auth']->forgetGuards()` + `flushHeaders()` after any
  authenticated request (inline in `test_unknown_item_404_and_unauthenticated_401`).
- Implicit route-model binding runs BEFORE the auth middleware: an unknown
  `{item}` id 404s regardless of authentication; the 401 case must use a
  known id.
- `assertDatabaseHas` with a `'col' => null` entry compiles `= NULL` (never
  matches) — assert the row and `assertNull` the attribute instead.

**Deviations from spec**: none beyond the documented delta wording and the
user-driven subtree amendment (recorded in Scope).
