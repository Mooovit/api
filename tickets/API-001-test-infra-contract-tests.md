---
id: API-001
title: "SQLite test suite + feature tests pinning the current API contract"
type: infra
priority: P0
status: in-review
depends_on: []
spec: "server.md (all ideas need this safety net); client contract: plan.md §2"
---

# API-001 — SQLite test suite + feature tests pinning the current API contract

## Context
Every subsequent server ticket (API-002…) promises "existing clients keep working" and
"tests pass", but today there are **zero tests for the item/status/location/label/history
APIs** and the PHPUnit SQLite in-memory connection is commented out (`phpunit.xml:24-25`),
so any test run would hit the real database. This ticket builds the safety net every other
ticket stands on. It also pins (and where trivial, fixes) two defects the exploration
surfaced.

## Scope — Must have
- [x] Enable SQLite in-memory testing: uncomment `DB_CONNECTION=sqlite` /
      `DB_DATABASE=:memory:` in `phpunit.xml`; make the full migration chain
      (`php artisan migrate:fresh`) succeed on SQLite.
      The feared `items.box_id` hazard did not materialize: the existing
      `2021_07_07_060832_drop_boxes_table` migration already drops the column together
      with the `boxes` table, so `migrate:fresh` passes on SQLite as-is. No extra
      migration needed. Also raised `error_reporting` in `phpunit.xml` (24527) to silence
      PHP 8.4 deprecation notices from Laravel 8 while keeping warnings visible.
- [x] Feature test suites under `tests/Feature/` pinning the **current** behavior of:
      - `POST api/register`, `POST api/authenticate` — `AuthenticateApiTest` (token
        issued, only the part after `|` returned, 2FA users rejected with 403, bad
        credentials 401; register requires `terms => true` because Jetstream terms
        feature is enabled).
      - `GET api/item` (team-scoped, structure `id, name, parent_id, team_id,
        location_id, status_id, created_at, updated_at`), `GET api/item/:id`
        (`labels` loaded, `?childrens=1` variant — note bare `?childrens` does NOT
        trigger the load, pinned as-is), `POST api/item` (validation 422,
        `checkParents` team consistency → 404 on foreign parent, 201 on create),
        `PUT/PATCH api/item/:id` (sparse: only sent fields change; history rows only
        for actually-changed fields; legacy resource methods pinned as-is),
        `DELETE api/item/:id` (`{"success":"success"}`).
      - `GET api/item/:item/history` (rows newest-first, `user:id,name` loaded).
      - `api/status`, `api/location` resources (including the pinned quirk that
        `StatusController@store` drops the validated `position` field).
      - `api/labels` CRUD (color regex `#RRGGBB`), attach/detach on items (top-level
        items only, 409 on duplicate attach, 404 on foreign label).
      - `GET api/user` (user + userPermissions + tokenPermissions), `GET api/teams`.
- [x] Authorization tests: wrong-team resource access and token-without-ability
      (`tokenCan`) → 403, for read vs write endpoints.
- [x] **Fix the `show()` authorization gap**: `ItemController::show()` now checks
      `hasTeamPermission($item->team, …)` instead of `$user->current_team`, mirroring
      `update()`/`destroy()`. Regression test: user of team A, item of team B → 403.
- [x] Testing helper for user + team + token creation — `tests/Concerns/InteractsWithApi.php`
      (`newUserWithTeam()`, `addTeamMember()`, `actingAsApi()`) + factories for
      Item/Status/Location/Label/History (explicit `onTeam()`/`forItem()` states —
      the Laravel 8.83 `forTeam()` factory magic is broken and was not used).

## Out of scope
- Changing any request/response contract (additive or otherwise) — later tickets.
- The `StatusController@store` drops the validated `position` field (never persisted) —
  file a separate ticket if desired; do not fix here (it changes behavior tests would pin).
- CI pipeline setup.

## Acceptance criteria
- [x] `vendor/bin/phpunit` runs green against SQLite in-memory; no test touches the
      configured development database.
- [x] Every existing `api/*` route has at least one happy-path and one authorization test.
- [x] Sparse-PATCH behavior is pinned by a test: sending only `parent_id` leaves
      `name`/`status_id`/`location_id` untouched and writes exactly one history row.
- [x] Cross-team `GET api/item/:id` returns 403 after the `show()` fix.
- [x] `php artisan migrate:fresh` succeeds on SQLite and on the default dev connection.

## Technical notes
- Use `RefreshDatabase` (with `:memory:` it recreates the schema per process — fast enough
  here); `DatabaseTransactions` would leak state against a real DB, avoid.
- Laravel 8, PHP `^7.3|^8.0` (`composer.json:8,14`) — no PHP 8.1-only syntax.
- Controllers return Eloquent models directly (JSON = model serialization); tests should
  assert against `assertJsonFragment`/`assertJsonStructure` so the pinned contract is
  explicit.
- The web route `POST /item/{item}` (`routes/web.php:32`) bypasses `tokenCan` on purpose
  (non-`api/*` path, `ItemController.php:176`) — pin that too, do not "fix" it.
- UUID PKs via `App\Traits\Uuids` — create models through factories/Eloquent so ids are
  generated.

## Tests
This ticket **is** tests. Run `vendor/bin/phpunit`; expect all new suites green.
Document the final count in the implementation report.

## Documentation requirements
- Short section in `README.md`: how to run the test suite.
- Note in `server.md` (top) that the API contract is now pinned by feature tests.
- PHPDoc on the new test helpers.

## Implementation report (2026-09-05)

**Result: `vendor/bin/phpunit` → PASS. 100 tests, 217 assertions, 4 skipped**
(the 4 skips are pre-existing Jetstream feature-suite skips, unrelated to this ticket).

Files added:
- `database/factories/ItemFactory.php`, `StatusFactory.php`, `LocationFactory.php`,
  `LabelFactory.php`, `HistoryFactory.php` — explicit state methods (`onTeam()`,
  `inLocation()`, `withStatus()`, `childOf()`, `forItem()`) because Laravel 8.83's
  `forTeam()` relationship magic throws a TypeError (`Factory::__call` passes the model
  where an array is expected).
- `tests/Concerns/InteractsWithApi.php` — `newUserWithTeam()` (user + personal team,
  `current_team_id` set), `addTeamMember($user, $team, $role)` (Jetstream roles:
  `admin` / `Read Only`), `actingAsApi($user, $abilities)` — creates a **real Sanctum
  personal access token** (`createToken`) because `actingAs($user, $abilities)` produces
  a `TransientToken` that does not carry abilities, so `tokenCan()` checks would always
  pass.
- `tests/Feature/AuthenticateApiTest.php`, `UserApiTest.php`, `ItemApiTest.php`,
  `StatusApiTest.php`, `LocationApiTest.php`, `LabelApiTest.php`,
  `ItemHistoryApiTest.php` — 57 new feature tests.

Files changed:
- `phpunit.xml` — SQLite `:memory:` enabled; `error_reporting` = 24527 (PHP 8.4 running
  Laravel 8 emits deprecations that would otherwise pollute output).
- `app/Http/Controllers/ItemController.php` — `show()` authorization gap fixed
  (`$item->team` instead of `$user->current_team`).
- `README.md` — how to run the suite + contract-pinning note.
- `server.md` — status banner pointing at the pinned contract.

Behavior discovered while pinning (documented in tests, unchanged):
- Store endpoints return **201** (Eloquent `wasRecentlyCreated`), not 200.
- `?childrens` without a value does **not** trigger the children load — clients send
  `?childrens=1`.
- `LabelController@index` has no `tokenCan` check — pinned as-is (candidate for a later
  hardening ticket, not changed here).
- Validation runs before authorization in `ItemController@store`, so the 403 test needs
  a complete payload.

## Reviewer notes
- The `show()` fix and its regression test land in the same commit as the suite so the
  pinned contract includes the fix from day one.
- No contract change of any kind; old clients unaffected.
