---
id: API-006
title: "Delta sync: ?since= + deletions feed on GET api/item"
type: feature
priority: P1
status: in-review
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
- [x] `GET api/item?since=<ISO-8601 timestamp>` →
      `{"changed": [ <item>, ... ], "deleted_ids": [ "<uuid>", ... ]}`.
      - `changed`: full item rows (exact same serialization as the plain list, so the
        client reuses its parser) where `updated_at > since` — creations included,
        since `updated_at >= created_at`.
      - `deleted_ids`: soft-deleted rows with `deleted_at > since` (via `onlyTrashed`),
        team-scoped.
- [x] No `since` → today's plain array, unchanged (backward compat pinned by the
      API-001 contract tests).
- [x] Migration: index on `items.updated_at` (missing today); API-005 already indexed
      `deleted_at`.
- [x] Validate `since` (`date` rule) → 422 on garbage; document the expected client
      value (the max `updated_at` seen last pull, or the `X-Revision` moment — see
      API-003 pairing).
- [x] Team scoping and authorization identical to `index()`.
- [x] Feature tests.

## Out of scope
- `since` for `api/status` / `api/location` / `api/labels` (tiny tables, server.md §2
  deprioritizes — follow-up if needed; until then clients keep full-pulling them).
- Pagination of `changed` (teams are small; revisit if a team grows past ~5k items).
- Client-side diff application (Android follow-up ticket; server just ships the shape).

## Acceptance criteria
- [x] Create / update / delete an item → one delta call returns it in `changed` /
      `deleted_ids` respectively; unchanged items never appear.
- [x] Second identical call with the same `since` returns empty results (idempotent).
- [x] Plain `GET api/item` response is a bare array, byte-shape unchanged.
- [x] Delta rows are team-scoped (foreign-team changes never leak).
- [x] Tombstones only ever surface as ids — no deleted row bodies in the response.

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

---

## Implementation report (2026-09)

**Status: done.** One commit, suite green (144 tests, 434 assertions, 4 pre-existing
Jetstream skips).

### Shipped
- `database/migrations/2026_09_05_000003_index_items_updated_at.php` —
  `items.updated_at` index (complements API-005's `deleted_at` index).
- `ItemController::index()` — when `since` is filled: validate (`date` → 422),
  parse via `$request->date('since')` and return
  `{ changed, deleted_ids }`:
  - `changed` = team-scoped rows with `updated_at > since`. The SoftDeletes global
    scope keeps tombstones out of this list **for free** — a deleted box never
    surfaces as a body even though its `updated_at` was bumped at delete time
    (`runSoftDelete` touches `updated_at`).
  - `deleted_ids` = `onlyTrashed()` team-scoped with `deleted_at > since`, plucked to
    ids only.
  - Both shapes carry `X-Revision` (documented — clients can remember the counter in
    the same request).
  - Without `since` (or with `since=` empty — `filled()` semantics): the plain array,
    byte-identical to before.
- `tests/Feature/ItemDeltaSyncTest.php` — 8 tests: plain-array compat (asserts no
  `changed`/`deleted_ids` keys leak into the no-since shape); empty baseline;
  create/update/delete window (pins exactly 2 changed rows, the tombstone as id only,
  untouched/removed names absent from the body, list serialization on rows);
  idempotent repeat (tombstone id persists while `deleted_at > since` holds);
  team scoping for `changed` and for `deleted_ids`; 422 on garbage; `X-Revision`
  parity between shapes.
- `server.md` §2 — "Implemented (API-006)" banner with the full wire contract.

### Design notes / deviations
- **Deleted items are excluded from `changed`** by the global scope (not by an extra
  condition). Because a soft delete also bumps `updated_at`, a naive reader might
  expect the tombstone body in `changed` — the tests pin that it never appears.
- Raw query string values are never compared to the column directly (SQLite string
  comparison of ISO-8601 with `T`/`Z` against `Y-m-d H:i:s` would be wrong); the value
  is parsed to Carbon and compiled through the connection's date format.
- `plan.md` lives in the Android repo — the contract note is recorded in `server.md`
  §2 instead; the client ticket (MV-044/MV-014 follow-up) should copy it across.

### Verification
- `vendor/bin/phpunit --filter ItemDeltaSyncTest` → 8 tests, 43 assertions, green.
- Full suite → **144 tests, 434 assertions, 4 skipped — PASS**.

### Reviewer notes
- The no-since path is the same `return` as before — API-001/002 contract tests are
  the byte-compat proof.
- Known accepted limitation (documented): second-precision timestamps mean two writes
  inside the same second can be merged into one delta window; the revision counter
  (API-003) is the authoritative "something moved" signal.
