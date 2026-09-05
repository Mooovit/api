---
id: API-015
title: "Per-device (per-token) current team"
type: feature
priority: P1
status: ready
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
- [ ] Migration: nullable `current_team_id` (uuid, indexed, FK teams cascade
      null) on `personal_access_tokens`.
- [ ] Effective-team resolution for API requests: token's `current_team_id`
      when set **and** the token's user still belongs to that team, otherwise
      the user's `currentTeam` (unchanged default). One helper on the User
      model (e.g. `effectiveTeamFor($token)`) or a request macro — no
      duplicated resolution logic; the team-scoped read endpoints (item list +
      delta, revision, activity, labels, statuses, locations) and any other
      `currentTeam` consumer in `routes/api.php` go through it.
- [ ] `GET api/device-team` — `{current: {id, name}, teams: [{id, name}...]}`:
      the effective team for **this token** plus every team the token's user
      belongs to (so a device can present a picker and switch itself).
- [ ] `POST api/device-team` — `{team_id: uuid|null}`: `null` resets the token
      to the user default; otherwise the user must belong to `team_id`
      (422 otherwise). Returns the same shape as `GET api/device-team` with
      `current` updated. Any valid token may read/switch (a read-only device
      may need to switch to a team it can read); membership is the only gate.
- [ ] Backward compatible: tokens without `current_team_id` behave exactly as
      before (whole existing suite green untouched).
- [ ] Feature tests.

## Out of scope
- Per-token abilities re-mapping on switch (abilities are global to the token —
  a read-only token stays read-only on every team); web/UI for the setting
  (the Android picker + API are the clients); anything PATCH (the switch is
  POST); audit rows for switches (not a data change).

## Acceptance criteria
- [ ] Default unchanged: fresh token → `GET api/device-team.current` is the
      user's default team; item list/delta, revision, activity, labels,
      statuses, locations all scoped to it exactly as before.
- [ ] After `POST api/device-team {team_id: other}` (user member of both):
      every team-scoped read endpoint above serves the **other** team's data;
      `GET api/device-team` reports it; `GET api/revision` returns the other
      team's counter.
- [ ] Switch to a team the user does not belong to → 422; `team_id: null` →
      back to the user default.
- [ ] Resource-addressed routes stay resource-scoped (API-001 pattern):
      `GET api/item/{item}` of another team is 403 even after a switch —
      switching never widens authorization.
- [ ] Item `updated_at`-style contracts untouched; the additive-only rule
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
