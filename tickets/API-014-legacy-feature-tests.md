---
id: API-014
title: "Feature tests for the original (pre-API-002) surfaces"
type: test
priority: P2
status: in-review
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
- [x] Survey current coverage (`tests/Feature/*`) and pin the gaps:
  - [x] Kanban read surface: `GET /kanban/activity`, `GET /kanban/history`,
        `GET /kanban/search?q=`, `GET /kanban/item/{itemId}`,
        `GET /kanban/{type}` (status/location board views),
        `GET /kanban/labels` (see Report: route is shadowed — pinned as-is).
  - [x] Kanban write surface: `POST /kanban/update-item` (barcode-driven item
        update), status/location/label CRUD (`POST` create, `PATCH` update,
        `DELETE` delete — legacy PATCH routes on the web surface are pinned as
        they exist today), label attach/remove on an item
        (`POST /kanban/item/{itemId}/labels`, `DELETE .../labels/{labelId}`).
  - [x] Kanban team scoping + authorization: rows of other teams are invisible
        (indexes, search, item details, updates), unauthenticated → 401 (JSON).
  - [x] `POST api/register` — **already pinned** by `AuthenticateApiTest`
        (creates user + personal team; validation). No new tests needed — the
        survey finding replaces the planned coverage.
  - [x] `GET api/teams` and the `GET api/user` payload shape — **already
        pinned** by `UserApiTest`. No new tests needed.
- [x] Everything is pinned as-is: if a behavior looks odd, it still gets pinned
      and noted — no drive-by fixes (one found — see Report).

## Out of scope
- Any production code change (including the legacy `PATCH /kanban/*` methods —
  the no-PATCH rule applies to *new* endpoints only); fixing the kanban
  surface's known quirks; Jetstream/Fortify tests (already shipped by the
  skeleton); coverage of API-002..013 features (each has its own tests).

## Acceptance criteria
- [x] Every kanban route responds as today and the response shapes are asserted
      (keys pinned, not whole payloads where they contain volatile data).
- [x] `POST api/register` behavior — already pinned by `AuthenticateApiTest`
      (user + personal team creation, validation; the endpoint returns HTTP 200
      with an empty body and mints no token — corrected from the original
      scope assumption).
- [x] `GET api/teams` / `GET api/user` shapes pinned (already in `UserApiTest`).
- [x] Full suite green; no production file touched by the commit.

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
- ~~New `tests/Feature/RegisterApiTest.php`~~ — not needed: `AuthenticateApiTest`
  already covers register.
- ~~Extend `UserApiTest`~~ — not needed: shapes already pinned.

## Report (implementation)
- **Survey finding**: `api/register`, `GET api/teams` and `GET api/user` were
  already pinned (`AuthenticateApiTest` — including register; `UserApiTest`).
  The real gap was the whole kanban surface → all new tests live in
  `tests/Feature/KanbanTest.php` (16 tests). No production file touched.
- **Pinned**: status board view (column grouping, root-items-only, resolved
  `status_name`/`location_name`, label `text_color` luminance rule —
  `#FF0000` → `#ffffff`, `#FFFF00` → `#000000`); location board (incl.
  unlocated items appearing in no column); activity view team scoping;
  `GET /kanban/history` JSON (newest first, `old_value_name`/`new_value_name`
  resolution — id fields → names, `name` stays raw); `GET /kanban/search`
  (LIKE + exact-id, `filter=boxes|items` via children, `children_count`,
  team scope); `GET /kanban/item/:id` (`{item, children, history}` incl.
  `parent_id` → parent name; foreign team 404 `{error: "Item not found"}`;
  token without `item:read` → 403); `POST /kanban/update-item` (history rows
  only for actually-changed fields; 422 unknown item; foreign-team
  status/location 404; foreign item 403; Read-Only member 403); status/
  location/label CRUD (create/update/delete, foreign 404, Read-Only 403,
  label color regex 422); label attach/remove (duplicate 409 with
  `{message}`, foreign label/item 404); unauthenticated 401s.
- **Quirk found + pinned as-is** (documented in the test docblock):
  `GET /kanban/labels` is registered **after** `GET /kanban/{type}` in
  `routes/web.php`, so the generic route captures it —
  `KanbanController::getLabels()` is unreachable and the request renders the
  status board view. Left untouched per this ticket's no-drive-by rule;
  fixing it would be a separate conscious change that flips
  `test_labels_route_is_shadowed_by_the_type_route`.
- **Note**: kanban routes are consumed with session auth (`actingAs`);
  `tokenCan()` passes via Sanctum's `TransientToken` for session users, and
  real-token behavior is additionally pinned once (narrow-ability 403).
- **Verification**: `--filter KanbanTest` 16/16; full suite 232 tests /
  932 assertions / 4 pre-existing Jetstream skips, green.

