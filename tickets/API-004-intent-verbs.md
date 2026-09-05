---
id: API-004
title: "Intent verbs for anti-clobber writes (POST move/assign/rename)"
type: feature
priority: P0
status: ready
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
- [ ] `POST api/item/{item}/move` — body `{parent_id: string|null}`. Moves the box;
      `null` makes it a root. Validates: parent exists, same team, and **no cycle**
      (an item may not become a descendant of itself — today's `update()` only checks
      team via `checkParents`; MV-076 shows the client already needs this rule).
- [ ] `POST api/item/{item}/assign` — body `{status_id, location_id}` (both required,
      matching the Transport payload MV-015 / `SET_STATUS_LOCATION`). Validates exists +
      same team.
- [ ] `POST api/item/{item}/rename` — body `{name}`.
- [ ] Each verb: authorization exactly like `ItemController::update` but bound to the
      **resource's** team (`$item->team`, the pattern `update()`/`destroy()` already use)
      + `tokenCan('item:write')` for `api/*`.
- [ ] Each verb runs in a transaction, applies only its own field(s), and writes one
      history row per changed field via the existing `recordItemChanges` logic
      (extract/reuse it — do not duplicate the loop).
- [ ] Response: the refreshed item (same shape as `update()` today).
- [ ] Document + test that plain `update()` is already sparse (only sent fields applied,
      only changed fields get history rows) so clients know both paths are safe.

## Out of scope
- Migrating the web SPA / kanban to the verbs (they can stay on the full-form update for
  now); client ops-queue rework to call the verbs (Android follow-up ticket).
- `If-Match` / `updated_at` precondition returning 409 (server.md §4 optional part —
  later ticket once clients track `updated_at` from API-002).

## Acceptance criteria
- [ ] `move` never touches `name`/`status_id`/`location_id`; `rename` never touches
      `parent_id`/`status_id`/`location_id`; `assign` never touches `name`/`parent_id`
      (pinned by tests).
- [ ] History rows match exactly the fields each verb changed.
- [ ] `move` into its own descendant → 422 with a clear error; `move` to a foreign-team
      parent → 422/404; `assign` with a foreign-team status → 422/404.
- [ ] No PATCH method is introduced anywhere.

## Technical notes
- Routes: add inside the `auth:sanctum` group, `routes/api.php` next to the existing
  `item/{item}/history` line. Route-model binding uses UUID PKs (route names won't
  collide with `Route::resource('item', …)`).
- Cycle check: walk `parent` chain up from the new parent (bounded by team item count) —
  items trees are small; a simple loop is fine.
- `rename` with the same name → no history row, `updated_at` unchanged (no save).
- Keep `update()` untouched except extracting `recordItemChanges` if needed.

## Tests
`vendor/bin/phpunit --filter ItemIntentVerbsTest`:
- per-verb isolation (sibling fields + history untouched);
- cycle rejection (a→b, b→a via move);
- permission matrix (read-only token → 403; other team's item → 403/404);
- response shape identical to `update()`.

## Documentation requirements
- PHPDoc on the three controller methods; document the verbs in `server.md` §4 (mark
  implemented, note the POST-not-PATCH deviation) and in the plan.md §2 contract notes.
