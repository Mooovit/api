---
id: API-032
title: "Item picked state — temporary out-of-box (pick / unpick intent verbs)"
type: feature
priority: P1
status: in-review
depends_on: [API-004]
spec: "server.md §4 (intent verbs — this ticket extends the verb set); client contract: plan.md §2 (Android repo)"
---

# API-032 — Item picked state (pick / unpick)

## Context

Client request 2026-09-07 ("pick contents" from a box): from a box's detail
screen the user wants to take contents out in two distinct ways — a
**permanent delete** (already served by `DELETE api/item/:id` + API-005/008;
client side in MV-138) and a **temporary pick** ("I'm taking this out, I'll
put it back later"). Nothing in the model expresses the temporary state
today: an item is either inside its `parent_id` box or deleted. This ticket
adds a nullable `picked_at` timestamp plus a pair of intent verbs
(`pick` / `unpick`) that follow the API-004 pattern exactly — POST-only
(the host's load balancer does not support PATCH), one verb touches exactly
one field.

Deletion is NOT redefined here: deleting a picked item behaves like deleting
any item (soft-delete + API-008 child detach).

## Scope — Must have

- [ ] Migration: nullable `picked_at` TIMESTAMP column on `items`
      (null = in box; set = temporarily out). No index needed (never
      filtered server-side).
- [ ] Add `picked_at` to `Item::$fillable`.
- [ ] `POST api/item/{item}/pick` — sets `picked_at = now()`, no request
      body. Re-picking an already-picked item refreshes the timestamp
      (idempotent-in-effect: one history row per call).
- [ ] `POST api/item/{item}/unpick` — sets `picked_at = null`. Unpicking an
      item that was never picked saves nothing (no history row,
      `updated_at` unchanged — mirror the `rename` same-value rule).
- [ ] Both verbs guarded by the existing `authorizeIntentVerb`
      (`item:write` + team membership, ItemController pattern used by
      `move`/`assign`/`rename`), body-less, wrapped in the standard
      `DB::transaction` with `recordItemChanges` (history rows:
      `field_name = 'picked_at'`, `old_value`/`new_value` = previous
      timestamp or null) and returned via `$item->refresh()`.
- [ ] Payload distribution: `picked_at` rides the existing item
      serialization (`$item->toArray()` in index/show/delta payloads) —
      ISO-8601, `null` when in box. Delta sync (API-006) picks the field up
      for free once writes touch `updated_at`; the API-003 revision bump
      flows through the existing item-save observer.
- [ ] Trashed items 404 through the SoftDeletes global scope, same as every
      other verb — no special handling.

## Out of scope

- **Bulk pick endpoints** — the client replays per-item `PICK` ops from its
  offline queue exactly like bulk delete replays `DELETE_ITEM` (MV-074
  precedent). A `POST api/item/bulk-pick` can come later if the per-item
  drain proves chatty (see API-007 style).
- Web/kanban UI for the picked badge (kanban is unaffected — `picked_at`
  is just a new nullable column).
- Picked-by attribution beyond the `user_id` already written on the history
  row; expiry / auto-unpick / reminders; any change to API-008 deletion
  semantics for picked items.

## Acceptance criteria

- [ ] `POST api/item/{id}/pick` returns the item with `picked_at` set
      (ISO-8601); `unpick` returns it `null`. Team revision increments on
      each effective write (API-003); a no-op unpick does not bump.
- [ ] Both verbs write one `histories` row each (field `picked_at`,
      correct old/new, `user_id` of the caller); history is visible via
      `GET api/item/{id}/history`.
- [ ] An item picked after `?since=<t>` appears in the delta `changed` list
      with `picked_at` set.
- [ ] `403` without `item:write`; `404` for a trashed item; both verbs are
      POST (405 on PATCH).
- [ ] Web/kanban payloads unchanged for items where `picked_at` is null.

## Technical notes

- Template to copy: `ItemController::move` / `rename` (POST verb +
  `authorizeIntentVerb` + validate + `DB::transaction` +
  `recordItemChanges(..., true)` + `update` + `refresh()`). `pick` validates
  an empty body (`$request->validate([])` is fine); set the timestamp in the
  controller (`['picked_at' => now()]` / `['picked_at' => null]`) so
  `recordItemChanges` sees the diff like any other verb.
- `picked_at` needs a `$casts` entry (`'picked_at' => 'datetime'`) for
  consistent ISO-8601 serialization, matching `History::$casts`.
- Routes: add next to the other intent verbs in `routes/api.php`
  (`item/{item}/pick`, `item/{item}/unpick`) — declared after the resource
  is fine (they carry the `{item}` binding like `move` does), but keep them
  grouped with the API-004 block for readability.
- Delta payloads reuse `Item` serialization — no new resource class.

## Tests

Feature tests under `tests/Feature/` (follow the API-004 verb tests):

- pick → 200, `picked_at` set, history row written, team revision +1;
  re-pick updates the timestamp (second history row).
- unpick → `picked_at` null; unpick-when-never-picked → no history row, no
  revision bump.
- delta: pick before `GET api/item?since=` shows the item in `changed`.
- 403 with a token lacking `item:write`; 404 for a soft-deleted item.
- Serialization: index + show payloads contain `picked_at` (snake_case,
  ISO-8601, null default).

Run: `vendor/bin/phpunit --filter Pick`

## Documentation requirements

- `server.md` §4 gets a short paragraph for the new verb pair (route, body,
  history + revision effects) — keep the idea-status table current.
- Update the API-004 verb table if one exists in `server.md`/README.
- Request/response example for both verbs in the ticket's implementation
  report (the client ticket MV-139 links here).

## Implementation report
*(filled by the implementing agent — do not touch before starting work)*

### Blockers
- none

### Changes
- Migration `2026_09_07_000001_add_picked_at_to_items_table.php`: nullable
  `picked_at` TIMESTAMP on `items` (after `parent_id`).
- `Item`: `picked_at` added to `$fillable`; `$casts` introduced with
  `'picked_at' => 'datetime'` (ISO-8601 serialization everywhere).
- `ItemController::recordItemChanges`: `picked_at` added to
  `$trackableFields`. The datetime cast hands back Carbon objects, so the
  `!==` diff records one row per effective change (re-pick refreshes with a
  new row) and records nothing on a no-op unpick (null → null).
- `ItemController::pick` / `unpick`: body-less intent verbs copied from the
  `rename` template (`authorizeIntentVerb` + `DB::transaction` +
  `recordItemChanges(..., true)` + `update` + `refresh`). No-op unpick
  performs no query (`isDirty` short-circuits), so `updated_at` and the
  revision (via `BumpsTeamRevisionObserver::updated`, which fires only on
  real changes) stay untouched.
- `routes/api.php`: `item/{item}/pick` + `item/{item}/unpick` declared in
  the API-004 intent-verb block.
- Legacy `PATCH api/item/:id` unaffected: its validation rule list does not
  include `picked_at`, so the field is stripped there.

### Files touched
- `database/migrations/2026_09_07_000001_add_picked_at_to_items_table.php` (new)
- `app/Models/Item.php`
- `app/Http/Controllers/ItemController.php`
- `routes/api.php`
- `server.md` (§4 extended block)
- `tests/Feature/ItemPickStateTest.php` (new)

### Tests run
```
vendor/bin/phpunit --filter ItemPickStateTest                                  → OK (11 tests, 46 assertions)
vendor/bin/phpunit --filter "ItemIntentVerbsTest|ItemApiTest|ItemDeltaSyncTest|UpdatedAtContractTest|ItemSoftDeleteTest|RevisionApiTest|ItemHistoryApiTest" → OK (68 tests, 294 assertions)
php artisan test                                                               → 4 skipped, 391 passed
```

### Request / response examples

Pick (body-less):

```
POST /api/item/{id}/pick
Authorization: Bearer <token with item:write>

200 OK
{
    "id": "0f9d3a20-…",
    "name": "Screwdriver",
    "parent_id": "box-uuid",
    "picked_at": "2026-09-07T10:15:30.000000Z",   ← null before the pick
    "updated_at": "2026-09-07T10:15:30.000000Z",
    …
}
```

Unpick (body-less) — same payload shape with `"picked_at": null`:

```
POST /api/item/{id}/unpick

200 OK
{ …, "picked_at": null, … }
```

History rows (`GET /api/item/{id}/history`), one per effective change:

```
{ "field_name": "picked_at", "old_value": null,
  "new_value": "2026-09-07 10:15:30", "user_id": "…" }        ← the pick
{ "field_name": "picked_at", "old_value": "2026-09-07 10:15:30",
  "new_value": null, "user_id": "…" }                          ← the unpick
```

A no-op unpick (never picked) returns the item unchanged — no history row,
`updated_at` untouched, no revision bump.

### Commits
- (pending — to be committed with the client tickets' branch)

### Notes for reviewer
- `old_value`/`new_value` on the history rows store the stringified Carbon
  (`Y-m-d H:i:s`) — same storage shape as every other timestamped field
  diff; the client resolves display.
- Delta inclusion needed no code: pick/unpick go through `$item->update()`,
  which moves `updated_at`, so API-006 `?since=` reports the row as
  changed.
- Bulk pick endpoints deliberately out of scope (client replays per-item
  ops from its offline queue; see ticket).
