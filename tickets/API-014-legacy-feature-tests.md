---
id: API-014
title: "Feature tests for the original (pre-API-002) surfaces"
type: test
priority: P2
status: ready
depends_on: [API-001]
spec: "user request (Matthieu, 2026-09): pin the features that predate the ticket series"
---

# API-014 — Feature tests for the original (pre-API-002) surfaces

## Context
The API-002..013 series each shipped with their own feature tests, but several
surfaces that **predate** the series were never pinned: the whole
`KanbanController` web surface (used by the kanban pages), `POST api/register`,
and the `GET api/teams` / `GET api/user` aux endpoints. These are part of the
de-facto contract for the web SPA / kanban pages and the Android login flow —
a regression there would go unnoticed. This ticket adds the missing feature
tests **without changing any behavior**.

## Scope — Must have
- [ ] Survey current coverage (`tests/Feature/*`) and pin the gaps:
  - [ ] Kanban read surface: `GET /kanban/activity`, `GET /kanban/history`,
        `GET /kanban/search?q=`, `GET /kanban/item/{itemId}`,
        `GET /kanban/{type}` (items/statuses/locations indexes),
        `GET /kanban/labels`.
  - [ ] Kanban write surface: `POST /kanban/update-item` (barcode-driven item
        update), status/location/label CRUD (`POST` create, `PATCH` update,
        `DELETE` delete — legacy PATCH routes on the web surface are pinned as
        they exist today), label attach/remove on an item
        (`POST /kanban/item/{itemId}/labels`, `DELETE .../labels/{labelId}`).
  - [ ] Kanban team scoping + authorization: rows of other teams are invisible
        (indexes, search, item details, updates), unauthenticated → redirect or
        401 per current behavior.
  - [ ] `POST api/register` — creates the user + personal team, returns a token
        (`AuthenticateApiTest` covers `api/authenticate`; register does not
        exist as a test today).
  - [ ] `GET api/teams` (list of the user's teams) and the `GET api/user`
        payload shape (`user`, `userPermissions` map, `tokenPermissions`).
- [ ] Everything is pinned as-is: if a behavior looks odd, it still gets pinned
      and noted — no drive-by fixes.

## Out of scope
- Any production code change (including the legacy `PATCH /kanban/*` methods —
  the no-PATCH rule applies to *new* endpoints only); fixing the kanban
  surface's known quirks; Jetstream/Fortify tests (already shipped by the
  skeleton); coverage of API-002..013 features (each has its own tests).

## Acceptance criteria
- [ ] Every kanban route responds as today and the response shapes are asserted
      (keys pinned, not whole payloads where they contain volatile data).
- [ ] `POST api/register` returns a usable token; the new user has a personal
      team and can immediately read its (empty) collections.
- [ ] `GET api/teams` / `GET api/user` shapes pinned.
- [ ] Full suite green; no production file touched by the commit.

## Technical notes
- Kanban routes are web-stack (`routes/web.php`) but behind `auth:sanctum` —
  exercise them with `actingAs($user)` session auth (they are consumed by the
  Inertia kanban pages) and, where convenient, also with a token to prove the
  dual-guard behavior. Match whatever the controller actually requires.
- `POST /kanban/update-item` resolves an item by scanned barcode/id — see
  `KanbanController::updateItemByBarcode` for the exact input contract before
  writing the test.
- Reuse `InteractsWithApi` for team/permission fixtures where it helps.

## Tests
- New `tests/Feature/KanbanTest.php` (read + write + scoping/authorization).
- New `tests/Feature/RegisterApiTest.php` (register → token → first list calls).
- Extend `UserApiTest` only if the `teams`/`user` shapes are not yet pinned.
