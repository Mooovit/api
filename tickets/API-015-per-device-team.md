---
id: API-015
title: "Per-device (per-token) current team"
type: feature
priority: P1
status: in-review
depends_on: [API-001, API-012]
spec: "user request (Matthieu, 2026-09), refined: take the user current team as a default, but allow the device to be placed into another team (and an endpoint for devices to fetch the teams they have access to and switch themselves)"
---

# API-015 — Per-device (per-token) current team

## Context
A PDA is a shared object: the same scanner may work the inbound dock of team A
in the morning and team B after lunch, while its operator belongs to both.
Today every team-scoped read endpoint (item list/delta, revision, activity,
labels, statuses, locations) resolves the team from the **user's** current
team (`$user->currentTeam`, Jetstream default = personal team), so a device
cannot be pointed at a different team without rotating accounts.

Matthieu's refinement (verbatim): *"for 015, take the user current team as a
default, but allow the device to be placed into another team (and if we can
create an endpoint for the devices to be able to fetch the team they have
access to and switch themselves, that's even better)"*.

The device identity from API-012 is the Sanctum **token**, so the setting
lives on `personal_access_tokens`.

## Scope — Must have
- [x] Migration: nullable `current_team_id` (uuid, indexed, FK teams cascade
      null) on `personal_access_tokens`.
- [x] Effective-team resolution for API requests: token's `current_team_id`
      when set **and** the token's user still belongs to that team, otherwise
      the user's `currentTeam` (unchanged default). One helper on the User
      model (e.g. `effectiveTeamFor($token)`) or a request macro — no
      duplicated resolution logic; the team-scoped read endpoints (item list +
      delta, revision, activity, labels, statuses, locations) and any other
      `currentTeam` consumer in `routes/api.php` go through it.
- [x] `GET api/device-team` — `{current: {id, name}, teams: [{id, name}...]}`:
      the effective team for **this token** plus every team the token's user
      belongs to (so a device can present a picker and switch itself).
- [x] `POST api/device-team` — `{team_id: uuid|null}`: `null` resets the token
      to the user default; otherwise the user must belong to `team_id`
      (422 otherwise). Returns the same shape as `GET api/device-team` with
      `current` updated. Any valid token may read/switch (a read-only device
      may need to switch to a team it can read); membership is the only gate.
- [x] Backward compatible: tokens without `current_team_id` behave exactly as
      before (whole existing suite green untouched).
- [x] Feature tests.

## Out of scope
- Per-token abilities re-mapping on switch (abilities are global to the token —
  a read-only token stays read-only on every team); web/UI for the setting
  (the Android picker + API are the clients); anything PATCH (the switch is
  POST); audit rows for switches (not a data change).

## Acceptance criteria
- [x] Default unchanged: fresh token → `GET api/device-team.current` is the
      user's default team; item list/delta, revision, activity, labels,
      statuses, locations all scoped to it exactly as before.
- [x] After `POST api/device-team {team_id: other}` (user member of both):
      every team-scoped read endpoint above serves the **other** team's data;
      `GET api/device-team` reports it; `GET api/revision` returns the other
      team's counter.
- [x] Switch to a team the user does not belong to → 422; `team_id: null` →
      back to the user default.
- [x] Resource-addressed routes stay resource-scoped (API-001 pattern):
      `GET api/item/{item}` of another team is 403 even after a switch —
      switching never widens authorization.
- [x] Item `updated_at`-style contracts untouched; the additive-only rule
      holds (whole suite green).

## Technical notes
- `personal_access_tokens` is Sanctum's table — the migration must not touch
  Sanctum's own columns; `$token->current_team_id` accessor flows through
  `$request->user()->currentAccessToken()`.
- Guard against stale memberships: if the stored team is no longer the user's,
  fall back to the default (and let the next `GET api/device-team` reflect it —
  no need to actively rewrite the column on read).
- Keep resolution in ONE place; the `/api/revision` closure and controllers
  currently call `$user->currentTeam` directly — route them through the helper.

## Tests
- New `tests/Feature/DeviceTeamTest.php`:
  - default = user's current team for every scoped endpoint;
  - switch → each scoped endpoint (item list + delta, revision, activity,
    labels, statuses, locations) serves the new team;
  - reset to default; switch to non-member team 422;
  - resource-addressed 403 stays 403 after switch;
  - membership loss falls back to default;
  - `GET api/device-team` shape; unauthenticated 401.

## Implementation Report

**Status**: done — 11 new tests in `tests/Feature/DeviceTeamTest.php`, full
suite 243 tests / 1001 assertions / 4 skips (the pre-existing Jetstream skips).

**Production changes**
- `database/migrations/2026_09_05_000008_add_current_team_to_personal_access_tokens_table.php`:
  nullable uuid `current_team_id` + index + FK `teams` `nullOnDelete` (team
  deletion silently resets tokens to the user default — the fallback covers
  it the same way a membership loss does).
- `App\Models\User::effectiveTeam(): ?Team` — the single resolution point:
  `currentAccessToken()` override when it is a Sanctum token with
  `current_team_id` set **and** `belongsToTeam($team)` still true, else
  `$this->currentTeam` (unchanged default). Session-auth users
  (`TransientToken`) and override-less tokens resolve exactly as before.
- `App\Http\Controllers\DeviceTeamController` — `show()` returns
  `{current: {id, name}, teams: [{id, name}...]}` (`allTeams()`); `update()`
  validates `team_id nullable|string|exists:teams,id`, 403s session auth
  (TransientToken — devices are tokens), 422s non-membership via
  `ValidationException::withMessages`, persists the column, returns
  `show($request)`. Membership is the only gate — no `tokenCan` on the switch
  (a read-only device must still be able to switch to a team it can read).
- Consumers swapped to `effectiveTeam()`: `ItemController::index` (plain +
  delta branches, `X-Revision`), `/api/revision` closure,
  `ActivityController::index`, `StatusController::index`,
  `LocationController::index`, all six `LabelController` methods (ownership
  checks `$label->team_id !== $team->id` / `$item->team_id !== $team->id`
  now compare against the effective team). Resource-addressed routes (item
  show/update, status/location/label show/update) deliberately stay
  resource-team-authorized — a switch re-points the *scope*, never widens
  *authorization*.

**Tests / patterns worth keeping**
- `DeviceTeamTest` mints raw token strings (`explode('|', ...)[1]`) and calls
  `$this->app['auth']->forgetGuards()` before **every** request (`asToken` /
  `mintToken` helpers). Sanctum's RequestGuard memoizes the resolved user —
  without the reset, cached relations (pivot, memberships) leak across
  requests within one test and produce bogus 500s (Jetstream `HasTeams.php`
  reads `->membership` on null when `belongsToTeam` is stale-true after a
  `detach`). This is a test-artifact issue, not production behavior.
- `withToken()` persists in `defaultHeaders` — chaining requests for two
  tokens in one test requires re-setting the header per request.
- Delta `since` params must be `urlencode()`d (the `+` of `+00:00` otherwise
  arrives as a space → 422), same pattern as ItemDeltaSyncTest.
- Verified: a switch bumps no team revision (pure token-column write).

**Deviations from spec**: helper named `effectiveTeam()` (no argument — reads
the token off the user) rather than `effectiveTeamFor($token)`; the
non-member switch is a 422 carrying the validation message "You do not
belong to this team." (matches the ticket's 422 requirement).
