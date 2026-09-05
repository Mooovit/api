---
id: API-003
title: "Per-team revision counter (GET api/revision)"
type: feature
priority: P0
status: ready
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
- [ ] Migration: add `revision` (unsigned bigint, default 0) to the `teams` table.
- [ ] Atomic bump on every team-scoped write: items (create/update/delete), statuses,
      locations, labels, item-label attach/detach. Use `increment('revision')` (single
      SQL statement) — never read-modify-write.
- [ ] Implementation suggestion: a small helper (e.g. `App\Support\BumpsTeamRevision` or
      an observer on `Item`, `Status`, `Location`, `Label`) + **explicit** bump calls in
      `LabelController::attachToItem/detachFromItem` — `belongsToMany` attach/detach fire
      no model events on a stock pivot class, observers alone will miss them.
- [ ] `GET api/revision` (auth:sanctum) → `{"revision": 42}` for the requesting user's
      current team. Authorization mirrors the read endpoints' style (team membership +
      any read `tokenCan`).
- [ ] `X-Revision: <n>` response header on `GET api/item` (other GETs optional) so a
      client that just pulled can remember the revision without a second call.
- [ ] Feature tests.

## Out of scope
- Delta payloads themselves (API-006); push/FCM/websocket; client polling changes
  (Android follow-up of MV-024); multi-team requests (counter is always current-team).

## Acceptance criteria
- [ ] Reads never bump the counter; every write path bumps it exactly once per request.
- [ ] Label attach/detach bumps the counter (regression-proofs the pivot-event gotcha).
- [ ] `GET api/revision` returns the same value as the `X-Revision` header seen on the
      last `GET api/item`.
- [ ] Existing clients unaffected (no existing response shape changes; header is additive).

## Technical notes
- teams table is Jetstream's — a plain additive migration is fine.
- Counter is monotonic per team; no per-user/per-token scoping.
- SQLite tests: `increment` works identically; assert with `assertDatabaseHas` + fresh GET.
- Route goes in the `auth:sanctum` group of `routes/api.php` next to `/user` and `/teams`.

## Tests
`vendor/bin/phpunit --filter RevisionTest`:
- stable across repeated `GET api/item`;
- bumps after item create / update / delete, status/location/label create/update/delete,
  label attach/detach;
- 403 without read ability; header present and equal on `GET api/item`.

## Documentation requirements
- PHPDoc on the helper/observers; document the endpoint + header in `server.md` §9
  (mark implemented) and add the field to the plan.md §2 contract notes (additive).
