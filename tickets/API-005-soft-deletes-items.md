---
id: API-005
title: "Soft deletes for items (tombstone foundation)"
type: feature
priority: P1
status: in-review
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
- [x] Migration: add nullable `deleted_at` timestamp to `items`, with an index
      (`$table->softDeletesIndex()` or explicit composite as needed for API-006's
      `WHERE deleted_at > ?`).
- [x] Add the `SoftDeletes` trait to `App\Models\Item`.
- [x] `DELETE api/item/:id` becomes a soft delete — response shape unchanged
      (`{"success":"success"}`).
- [x] Verify nothing else exposes trashed rows: `index()`/`show()` exclude them
      automatically with the trait; `checkParents`' `exists:items,id` validation rejects
      trashed parents (Laravel `exists` ignores soft-deleted rows — confirm by test);
      children of a deleted box keep their `parent_id` (interim behavior, documented —
      the policy decision is API-008); kanban + activity queries on `items` likewise
      exclude trashed rows automatically — spot-check them.
- [x] Route-model binding must 404 for trashed items (default behavior — pin with test).
- [x] Feature tests.

## Out of scope
- Soft deletes for statuses/locations/labels (follow-up if delta sync for the tiny
  tables is ever needed — server.md §2 calls them lower priority).
- Deletion semantics for non-empty boxes (API-008), `?with_trashed` exposure, restore
  endpoints, delta feed itself (API-006).

## Acceptance criteria
- [x] Deleted item: gone from `GET api/item`, `GET api/item/:id` → 404, absent from
      kanban; its `histories` rows survive.
- [x] Row still in DB with `deleted_at` set (assert `withTrashed` in tests).
- [x] Creating an item with a soft-deleted `parent_id` → validation error.
- [x] Existing API-001 contract tests still pass unchanged (clients see no difference).

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

---

## Implementation report (2026-09)

**Status: done.** One commit, suite green (136 tests, 391 assertions, 4 pre-existing
Jetstream skips).

### Shipped
- `database/migrations/2026_09_05_000002_add_soft_deletes_to_items_table.php` —
  `$table->softDeletes()->index()` (the index serves API-006's
  `WHERE deleted_at > ?` tombstone scan; `down()` drops index then column).
- `app/Models/Item.php` — `SoftDeletes` trait + tombstone-contract docblock
  (children keep `parent_id` pointing at the trashed parent — interim policy,
  API-008 decides the final semantics).
- `tests/Feature/ItemSoftDeleteTest.php` — 6 tests:
  1. delete → `{success}` unchanged, gone from index, show 404s, tombstone row
     present via `withTrashed`;
  2. histories of a deleted item survive (rows not cascaded);
  3. `GET api/item/:id/history` of a deleted item → 404 (binding excludes trashed);
  4. children of a deleted box keep their `parent_id` (interim policy pinned);
  5. trashed parent rejected on **store** → 404;
  6. trashed parent rejected on **move** → 404, item untouched.
- `tests/Feature/ItemApiTest.php` — `test_destroy_deletes_item` amended: instead of
  `assertDatabaseMissing` (which would now contradict the tombstone contract), it
  asserts `Item::withTrashed()->find($id)->deleted_at` is set. The HTTP contract is
  byte-identical; only the DB-level assertion changed.

### Premise corrections found during implementation
- **"Laravel `exists` ignores soft-deleted rows" is false on this stack.** Laravel 8's
  `exists:items,id` rule does **not** exclude trashed rows — `withoutTrashed()` only
  exists from Laravel 9 (`Rule::exists()->withoutTrashed()`). The trashed-parent
  rejection actually happens later, in `checkParents()`, whose `Item::findOrFail`
  (global scope) throws → **404**, not 422. The tests pin the observed 404 on both
  store and move. If a 422 is preferred later, the rule must be swapped for an
  explicit closure/`Rule::exists` with a `whereNull('deleted_at')` — noted for API-008.
- Zero production queries needed changing: every items read (API index/show, kanban
  controllers) goes through Eloquent, so the `SoftDeletes` global scope covers them
  all. The kanban spot-check found no raw DB queries on `items`.

### Docs
- `server.md` §2 now carries a "Prerequisite done (2026-09, API-005)" banner naming the
  interim children policy and its test file.
- `Item.php` documents the tombstone contract at the class level.

### Verification
- `vendor/bin/phpunit --filter 'ItemSoftDeleteTest|ItemApiTest'` → 25 tests, 72
  assertions, green.
- Full suite → **136 tests, 391 assertions, 4 skipped — PASS** (skips are the
  pre-existing Jetstream feature skips).

### Reviewer notes
- `destroy()` controller code is unchanged — Eloquent's `delete()` on a `SoftDeletes`
  model performs the soft delete. Response shape untouched.
- Deletion **does** bump `teams.revision`: the API-003 observer listens to `deleted`,
  which `SoftDeletes` fires (pinned by `RevisionApiTest`).
