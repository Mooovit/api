---
id: API-006
title: "Delta sync: ?since= + deletions feed on GET api/item"
type: feature
priority: P1
status: ready
depends_on: [API-005]
spec: "server.md §2; client contract: plan.md §2, §4.14"
---

# API-006 — Delta sync: ?since= + deletions feed on GET api/item

## Context
Every client refresh downloads the entire items table; each poll is a full payload and a
full Room clobber (MV-014 §4.14). With `since=`, a client pulls only what changed plus
the ids that disappeared, and applies a diff — no more wipe-and-refill. Builds on
API-005's tombstones. Response stays byte-compatible when `since` is absent, so current
clients (Android plan.md §2, kanban) notice nothing.

## Scope — Must have
- [ ] `GET api/item?since=<ISO-8601 timestamp>` →
      `{"changed": [ <item>, ... ], "deleted_ids": [ "<uuid>", ... ]}`.
      - `changed`: full item rows (exact same serialization as the plain list, so the
        client reuses its parser) where `updated_at > since` — creations included,
        since `updated_at >= created_at`.
      - `deleted_ids`: soft-deleted rows with `deleted_at > since` (via `onlyTrashed`),
        team-scoped.
- [ ] No `since` → today's plain array, unchanged (backward compat pinned by the
      API-001 contract tests).
- [ ] Migration: index on `items.updated_at` (missing today); API-005 already indexed
      `deleted_at`.
- [ ] Validate `since` (`date` rule) → 422 on garbage; document the expected client
      value (the max `updated_at` seen last pull, or the `X-Revision` moment — see
      API-003 pairing).
- [ ] Team scoping and authorization identical to `index()`.
- [ ] Feature tests.

## Out of scope
- `since` for `api/status` / `api/location` / `api/labels` (tiny tables, server.md §2
  deprioritizes — follow-up if needed; until then clients keep full-pulling them).
- Pagination of `changed` (teams are small; revisit if a team grows past ~5k items).
- Client-side diff application (Android follow-up ticket; server just ships the shape).

## Acceptance criteria
- [ ] Create / update / delete an item → one delta call returns it in `changed` /
      `deleted_ids` respectively; unchanged items never appear.
- [ ] Second identical call with the same `since` returns empty results (idempotent).
- [ ] Plain `GET api/item` response is a bare array, byte-shape unchanged.
- [ ] Delta rows are team-scoped (foreign-team changes never leak).
- [ ] Tombstones only ever surface as ids — no deleted row bodies in the response.

## Technical notes
- Timestamp precision gotcha: `timestamps()` columns have second precision — two writes
  within the same second can collide. Acceptable here (clients re-pull on revision bump,
  API-003); document it. Do **not** widen column precision in this ticket.
- Compare with `where('updated_at', '>', $since)` — strict `>`, matching the documented
  client rule "remember the max updated_at you saw".
- Return both arrays even when empty (`changed: []`, `deleted_ids: []`) — clients should
  not special-case absence.
- Eager-load nothing new: `changed` rows use the same shape as `index()` (no labels —
  same as today's list).

## Tests
`vendor/bin/phpunit --filter ItemDeltaSyncTest`:
- no-`since` compat array;
- create/update/delete visibility windows across the `since` boundary;
- team scoping; 422 on bad `since`;
- tombstones surface once (then stay — repeated call still lists the id if within the
  window, i.e. `deleted_at > since` remains true — pin that deliberate behavior).

## Documentation requirements
- PHPDoc on the controller; response-shape example in `server.md` §2 (mark implemented);
  plan.md §2 contract notes updated (additive).
