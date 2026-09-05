---
id: API-003
title: "Per-team revision counter (GET api/revision)"
type: feature
priority: P0
status: in-review
depends_on: [API-001]
spec: "server.md §9; client contract: plan.md §4.14"
---

# API-003 — Per-team revision counter (GET api/revision)

## Context
The Android sync icon polls the full items list just to learn "did anything change?"
(plan.md §4.14, MV-024/MV-032). Even once delta sync lands (API-006), the client asks a
question the server can answer with one integer. A per-team counter bumped on any write
lets the background worker poll a few bytes and only pull data when the counter moved.
The same counter is the upgrade path to FCM/websocket push later.

## Scope — Must have
- [x] Migration `2026_09_05_000001_add_revision_to_teams_table` — `teams.revision`
      (unsigned bigint, default 0).
- [x] Atomic bump on every team-scoped write via `App\Support\TeamRevision::bump()`
      (single `increment` SQL statement — never read-modify-write), wired through
      `App\Observers\BumpsTeamRevisionObserver` (created/updated/deleted) registered in
      `AppServiceProvider` for `Item`, `Status`, `Location`, `Label`.
- [x] **Explicit** bump calls in `LabelController::attachToItem/detachFromItem` —
      `belongsToMany` attach/detach fire no model events on a stock pivot class,
      observers alone miss them.
- [x] `GET api/revision` (auth:sanctum) → `{"revision": 42}` for the requesting user's
      current team; authorization = team membership (`belongsToTeam`) + at least one
      read `tokenCan` (item/status/location), mirroring the read endpoints.
- [x] `X-Revision: <n>` response header on `GET api/item` (index now returns a
      JsonResponse — same body, additive header only).
- [x] Feature tests — `tests/Feature/RevisionApiTest.php` (10 tests).

## Out of scope
- Delta payloads themselves (API-006); push/FCM/websocket; client polling changes
  (Android follow-up of MV-024); multi-team requests (counter is always current-team).

## Acceptance criteria
- [x] Reads never bump the counter; every write path bumps it exactly once per request
      (no-op writes — nothing dirty — correctly do NOT bump; pinned by a dedicated test).
- [x] Label attach/detach bumps the counter (regression-proofs the pivot-event gotcha).
- [x] `GET api/revision` returns the same value as the `X-Revision` header seen on the
      last `GET api/item` (pinned).
- [x] Existing clients unaffected (no existing response shape changes; header is additive;
      full suite green).

## Technical notes
- teams table is Jetstream's — a plain additive migration is fine.
- Counter is monotonic per team; no per-user/per-token scoping.
- SQLite tests: `increment` works identically; assert with `assertDatabaseHas` + fresh GET.
- Route goes in the `auth:sanctum` group of `routes/api.php` next to `/user` and `/teams`.

## Tests
`vendor/bin/phpunit --filter RevisionApiTest` (renamed from the ticket's original
`RevisionTest` suggestion to match the repo's `*ApiTest` convention):
- stable across repeated `GET api/item`; ✓
- bumps after item create / update / delete, status/location/label create/update/delete,
  label attach/detach; ✓
- 403 without read ability; header present and equal on `GET api/item`. ✓

## Documentation requirements
- PHPDoc on the helper/observers; document the endpoint + header in `server.md` §9
  (mark implemented) and add the field to the plan.md §2 contract notes (additive).

## Implementation report (2026-09-05)

**Result: full suite `vendor/bin/phpunit` → PASS (116 tests, 323 assertions, 4 skipped —
the same pre-existing Jetstream skips).**

Files added:
- `database/migrations/2026_09_05_000001_add_revision_to_teams_table.php`
- `app/Support/TeamRevision.php` — atomic `Team::whereKey(...)->increment('revision')`,
  no-op when the model carries no `team_id`.
- `app/Observers/BumpsTeamRevisionObserver.php` — created/updated/deleted →
  `TeamRevision::bump()` (delegates; `updated` only fires when attributes actually
  changed, so no-op writes leave the counter untouched).
- `tests/Feature/RevisionApiTest.php` — 10 tests: starts at 0 + stable across reads;
  item store/update/destroy bump exactly once; noop update does NOT bump;
  status/location/label CRUD bump; label attach/detach bump (pivot gotcha);
  X-Revision header on item index matches `GET api/revision`; 403 without a read ability.

Files changed:
- `app/Providers/AppServiceProvider.php` — registers the observer on the four models.
- `app/Http/Controllers/LabelController.php` — explicit `TeamRevision::bump($item)`
  after `attach`/`detach`.
- `app/Http/Controllers/ItemController.php` — index returns `response()->json(...)`
  with `X-Revision` header (body unchanged; unused `Collection` import dropped).
- `routes/api.php` — `GET api/revision` closure in the `auth:sanctum` group.
- `server.md` §9 — "Implemented" banner.
- `tests/Concerns/InteractsWithApi.php` — **bug fix to the API-001 helper discovered
  while writing these tests (see below).**

**Testing-infrastructure discovery — Sanctum's `RequestGuard` memoizes the user across
requests inside one feature test.** The first tests failed with "the write didn't bump",
but the raw DB row had been incremented — the *route* was reading a stale model.
Diagnosis: Sanctum registers its guard as an `Illuminate\Auth\RequestGuard`, whose
`user()` caches `$this->user` "for the current request"; but in feature tests the
AuthManager singleton (and thus the guard instance) **survives across the whole test**,
so every request after the first re-authenticated with the *first* token's user,
abilities, and loaded relations (`currentTeam` was stale). Production is unaffected
(the container is rebuilt per request), but any test making two `actingAsApi` calls
with different abilities/users in one method was unreliable. Fix: `actingAsApi()` now
calls `$this->app['auth']->forgetGuards()` before issuing each token. Full suite re-run
green after the fix.

## Reviewer notes
- The `InteractsWithApi` fix changes test infrastructure committed in API-001; it lands
  here because API-003 is what exposed it. No production behavior change beyond the
  additive endpoint/header/column.
- plan.md is a client-side document not present in this repo, so the "plan.md §2 contract
  notes" update is deferred to the Android-side ticket that consumes the endpoint.
