---
id: API-005
title: "Soft deletes for items (tombstone foundation)"
type: feature
priority: P1
status: ready
depends_on: [API-001]
spec: "server.md §2 (prerequisite)"
---

# API-005 — Soft deletes for items (tombstone foundation)

## Context
server.md §2 (delta sync) requires deletions to survive as ids — today `destroy()`
hard-deletes the row (ItemController.php:216) and no `SoftDeletes` exists anywhere in
`app/`. This ticket lays the tombstone foundation: deleted items keep a `deleted_at`
row so API-006 can report `deleted_ids`, while every existing query/route behaves
exactly as before from the clients' point of view.

## Scope — Must have
- [ ] Migration: add nullable `deleted_at` timestamp to `items`, with an index
      (`$table->softDeletesIndex()` or explicit composite as needed for API-006's
      `WHERE deleted_at > ?`).
- [ ] Add the `SoftDeletes` trait to `App\Models\Item`.
- [ ] `DELETE api/item/:id` becomes a soft delete — response shape unchanged
      (`{"success":"success"}`).
- [ ] Verify nothing else exposes trashed rows: `index()`/`show()` exclude them
      automatically with the trait; `checkParents`' `exists:items,id` validation rejects
      trashed parents (Laravel `exists` ignores soft-deleted rows — confirm by test);
      children of a deleted box keep their `parent_id` (interim behavior, documented —
      the policy decision is API-008); kanban + activity queries on `items` likewise
      exclude trashed rows automatically — spot-check them.
- [ ] Route-model binding must 404 for trashed items (default behavior — pin with test).
- [ ] Feature tests.

## Out of scope
- Soft deletes for statuses/locations/labels (follow-up if delta sync for the tiny
  tables is ever needed — server.md §2 calls them lower priority).
- Deletion semantics for non-empty boxes (API-008), `?with_trashed` exposure, restore
  endpoints, delta feed itself (API-006).

## Acceptance criteria
- [ ] Deleted item: gone from `GET api/item`, `GET api/item/:id` → 404, absent from
      kanban; its `histories` rows survive.
- [ ] Row still in DB with `deleted_at` set (assert `withTrashed` in tests).
- [ ] Creating an item with a soft-deleted `parent_id` → validation error.
- [ ] Existing API-001 contract tests still pass unchanged (clients see no difference).

## Technical notes
- `App\Traits\Uuids` PKs are unaffected by SoftDeletes.
- History rows referencing the trashed item keep working — `HistoryController@index`
  uses `$item->histories()`; fetching history of a trashed item 404s at binding (fine,
  document).
- Keep `use SoftDeletes` limited to `Item` in this ticket.

## Tests
`vendor/bin/phpunit --filter ItemSoftDeleteTest`:
- delete → invisible everywhere, tombstone present;
- children keep pointing at the trashed parent (pinned interim behavior);
- `exists` validation rejects trashed parent on create/move;
- history of a deleted item → 404.

## Documentation requirements
- PHPDoc noting the tombstone contract; `server.md` §2 note that the soft-delete
  prerequisite is done; document the interim children behavior for API-008.
