---
id: API-016
title: "Cross-team item transfer"
type: feature
priority: P2
status: ready
depends_on: [API-001, API-011]
spec: "user request (Matthieu, 2026-09): move an item to another team with permission checks on both sides + documentation"
---

# API-016 — Cross-team item transfer

## Context
Items live scoped to a team, but boxes physically move between teams
(subsidiaries, customer-owned teams, a team per site). Today the only way is
export/re-import by hand. This ticket adds one endpoint that transfers an item
(and nothing else) to another team, with **permission checks on both teams**
and history/revision bookkeeping on both sides.

## Scope — Must have
- [ ] `POST api/item/{item}/transfer` — `{team_id: uuid, location_id?: uuid,
      status_id?: uuid}`:
  - requester needs `item:write` on the **source** team (the item's team) and
    on the **destination** team, via team permission **and** token ability;
  - `team_id` must exist and differ from the source (422 otherwise);
  - optional `location_id`/`status_id` must belong to the **destination**
    team (422 otherwise) — they are set in the same transaction so the item
    never lands in the destination with a source-team location/status;
  - `parent_id` is detached to root on transfer (a parent link never crosses
    teams); one history row for it, same as API-008 semantics;
  - history rows for every changed field (`team_id` + `location_id`/
    `status_id` when supplied) against the item;
  - team revision bumped on **both** teams (API-003);
  - labels attached to the item that belong to the source team are detached
    (destination labels are not auto-attached) — response reports them;
  - barcodes: codes stay attached to the item but the source team's registry
    reservation moves with it — if the destination team already holds the same
    code on another item, the transfer is refused 409 (registry uniqueness is
    per team).
  - Response: the fresh item (`show()` shape) plus additive transfer metadata:
    `{"detached_label_ids": [...], "detached_parent": bool}`.
- [ ] Documentation: `server.md` gains a §12 describing the endpoint (shape,
      permissions, side effects); the ticket itself documents the response.
- [ ] Feature tests.

## Out of scope
- Transferring whole subtrees (single item only — caller transfers children
  one by one); attachments: they follow the item (they are keyed by item, and
  their stored `team_id` is rewritten in the same transaction — documented);
  audit rows; moving locations/statuses between teams.

## Acceptance criteria
- [ ] Happy path: item moves teams with supplied destination location/status;
      history rows exist for each changed field; both teams' revisions moved.
- [ ] Permission matrix: missing `item:write` on source → 403; missing on
      destination → 403 (even with source permission); read-only member → 403.
- [ ] Validation: unknown team 422; self-transfer 422; source-team
      location/status 422; destination code collision → 409 and **nothing**
      changed (transaction rolled back).
- [ ] Labels of the source team detached and reported; parent detached to root.
- [ ] Delta sync coherence: transferred item appears in the destination team's
      `?since=` feed as `changed` (and in the source's, with its new state).
- [ ] Additive-only: whole suite green.

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
  history rows), permission matrix, validation matrix, label/parent
  detachment reporting, barcode collision 409 rollback, delta visibility,
  unknown item 404.
