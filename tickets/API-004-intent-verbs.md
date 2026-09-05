---
id: API-004
title: "Intent verbs for anti-clobber writes (POST move/assign/rename)"
type: feature
priority: P0
status: in-review
depends_on: [API-001]
spec: "server.md §4; client contract: plan.md §2, §4.14"
---

# API-004 — Intent verbs for anti-clobber writes (POST move/assign/rename)

## Context
`PATCH api/item/:id` semantics today are "send the item object with the modified
field(s)". Two clients editing the same item concurrently last-write-wins the *whole
object*: a rename from the web kanban can silently undo a move made from a PDA — and
MV-014's queued replay of offline ops widens that window a lot. Intent verbs touch
exactly their field(s) and record exactly their history rows, so siblings can never be
clobbered. **All new verbs are POST** — server.md §4 sketched `PATCH .../rename`, but the
host does not support PATCH (commit `66207c4`, README constraint).

## Scope — Must have
- [x] `POST api/item/{item}/move` — body `{parent_id: string|null}`. Moves the box;
      `null` makes it a root. Validates: parent exists (`exists` → 422), same team
      (`checkParents` → 404), and **no cycle** (`ensureNoCycle` → 422: self-parent and
      own-descendant both rejected).
- [x] `POST api/item/{item}/assign` — body `{status_id, location_id}` (both required,
      matching the Transport payload MV-015 / `SET_STATUS_LOCATION`). Validates exists +
      same team.
- [x] `POST api/item/{item}/rename` — body `{name}`. Same name → no history row,
      `updated_at` unchanged (no dirty save).
- [x] Each verb: authorization bound to the **resource's** team (`$item->team`, the
      pattern `update()`/`destroy()` already use) + `tokenCan('item:write')` — shared
      `authorizeIntentVerb()` helper.
- [x] Each verb runs in a `DB::transaction`, applies only its own field(s), and writes
      one history row per changed field via the existing `recordItemChanges` logic —
      extended with an `$onlyPresentKeys` flag so `parent_id: null` (unparenting) still
      records a history row on `move` while legacy `update()` keeps its exact
      isset-based pinned behavior.
- [x] Response: the refreshed item (same shape as `update()` today).
- [x] Document + test that plain `update()` is already sparse — a dedicated test pins
      it and server.md §4 notes it.

## Out of scope
- Migrating the web SPA / kanban to the verbs (they can stay on the full-form update for
  now); client ops-queue rework to call the verbs (Android follow-up ticket).
- `If-Match` / `updated_at` precondition returning 409 (server.md §4 optional part —
  later ticket once clients track `updated_at` from API-002).

## Acceptance criteria
- [x] `move` never touches `name`/`status_id`/`location_id`; `rename` never touches
      `parent_id`/`status_id`/`location_id`; `assign` never touches `name`/`parent_id`
      (pinned by tests).
- [x] History rows match exactly the fields each verb changed (asserted per verb,
      including `old_value`/`new_value`).
- [x] `move` into its own descendant → 422 with a clear error; `move` to a foreign-team
      parent → 404; `assign` with a foreign-team status → 404.
- [x] No PATCH method is introduced anywhere (routes are POST-only).

## Technical notes
- Routes: added inside the `auth:sanctum` group next to the `item/{item}/history` line.
- Cycle check walks the `parent` chain up from the new parent with a seen-set
  (bounded; trees are small).
- Keep `update()` untouched except the optional `recordItemChanges` flag (default keeps
  legacy semantics).

## Tests
`vendor/bin/phpunit --filter ItemIntentVerbsTest` — 14 tests:
- per-verb isolation (sibling fields + history untouched) ✓
- move-to-root with `parent_id: null` records one row with the old parent ✓
- cycle rejection (grand-parent under grand-child → 422, nothing applied) ✓
- self-parent rejection (422) ✓
- permission matrix (read-only token → 403; other team's item → 403) ✓
- no-op rename: no history row, `updated_at` unchanged ✓
- plain `update()` still sparse ✓

## Documentation requirements
- PHPDoc on the three controller methods; verbs documented in `server.md` §4 (marked
  implemented with the POST-not-PATCH deviation noted).

## Implementation report (2026-09-05)

**Result: `vendor/bin/phpunit --filter ItemIntentVerbsTest` → PASS (14 tests, 50
assertions); full suite PASS (130 tests, 373 assertions, 4 skipped).**

Files changed:
- `app/Http/Controllers/ItemController.php` — three public verb methods (`move`,
  `assign`, `rename`), shared `authorizeIntentVerb()`, new `ensureNoCycle()` cycle
  guard (seen-set walk up the parent chain, 422 via `ValidationException`), and
  `recordItemChanges()` gained the `$onlyPresentKeys` flag (default false → legacy
  `update()` behavior byte-identical).
- `routes/api.php` — the three POST routes.
- `server.md` §4 — implemented banner (POST deviation + sparse-`update()` note).
- `tests/Feature/ItemIntentVerbsTest.php` — 14 tests.

Notes:
- Foreign-team parent/status → 404 (via the existing `checkParents` firstOrFail),
  consistent with the pinned `store()` behavior for foreign parents; nonexistent ids →
  422 via `exists` validation rules.
- The verbs bump `teams.revision` automatically through the API-003 observer (each does
  one dirty `update`); no extra code was needed.
- No PATCH route introduced; legacy `PATCH api/item/:id` remains for existing clients,
  untouched.

## Reviewer notes
- plan.md contract notes are client-side (not in this repo) — the Android follow-up
  ticket should switch the ops-queue replay to `move`/`assign`/`rename`.
