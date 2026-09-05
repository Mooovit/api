---
id: API-008
title: "Deletion semantics for non-empty boxes"
type: feature
priority: P1
status: in-review
depends_on: [API-005]
spec: "server.md §5"
---

# API-008 — Deletion semantics for non-empty boxes

## Context
`DELETE api/item/:id` on a box that still has children: the client today never learns
the policy (the Transfer flow just hides the item on success). Delete-or-cascade is
exactly the kind of thing a warehouse discovers the hard way. This ticket makes the
decision explicit, enforced server-side, and visible to the client. **Decision
(recommended by server.md §5): deleting a box re-parents its children to root.**

## Scope — Must have
- [x] `DELETE api/item/:id` on a box with children: in one transaction — soft-delete the
      box (API-005) and set `parent_id = null` on every direct child (they become
      roots).
- [x] Response gains `{"success": "success", "detached_ids": ["<uuid>", ...]}` —
      additive field; clients that ignore it behave exactly as today.
- [x] One history row per detached child: `field_name=parent_id`, `old_value=<deleted
      box id>`, `new_value=null` ("removed from deleted box X" renders client-side from
      this — matches MV-077's device-action history rendering).
- [x] Deep boxes: children are detached (not deleted, not recursively flattened
      further); grandchildren keep their own parents.
- [x] Documentation of the decision in `server.md` §5 (mark decided + implemented).
- [x] Feature tests.

## Out of scope
- The refuse-until-confirmed alternative (`409` + `?detach_children=1`) — server.md §5
  offers either; we ship the recommended cascade-and-report. Revisit only if real
  accidents happen.
- Restoring a deleted box with its children back (no restore endpoints exist).
- Status/location/label cleanup on delete (labels pivot rows of a trashed box stay —
  invisible, harmless; revisit if item soft-deletes get restored).

## Acceptance criteria
- [x] Box with 3 children deleted → 3 children now have `parent_id = null` and appear
      as roots; each has one `parent_id` history row; `detached_ids` lists exactly them.
- [x] Box without children → `detached_ids: []`, response otherwise unchanged.
- [x] All in one transaction: a forced failure mid-detach leaves no half-detached
      children (test with a model event throwing, or verify transaction wrapping by
      code review + unit test on the service method).
- [x] Authorization unchanged (`item:write` + resource team); kanban no longer shows
      detached children under the deleted box (they were never shown under a trashed
      parent anyway — pin the root listing).

## Technical notes
- Implementation: extend `ItemController::destroy()` or extract an
  `App\Actions\DeleteItem` action — the kanban barcode flow may reuse it later.
- Children fetch: `Item::where('parent_id', $item->id)->update(['parent_id' => null])`
  inside the transaction — but write history rows per child first (bulk insert) to keep
  row counts exact.
- `updated_at` of detached children changes (they were modified) — API-006 delta will
  report them as changed; that is desired, note it.

## Tests
`vendor/bin/phpunit --filter ItemDeletionPolicyTest`:
- detach counts, history rows, response field;
- nested box grandchild survival;
- no-children shape; permission matrix.

## Documentation requirements
- `server.md` §5 decision record + implemented note; PHPDoc on the delete action;
  plan.md §2 note (`detached_ids` is additive).

---

## Implementation report (2026-09)

**Status: done.** One commit, suite green (165 tests, 534 assertions, 4 pre-existing
Jetstream skips).

### Shipped
- `ItemController::destroy()` — one `DB::transaction` now: fetch direct children
  (soft-delete scope → trashed children untouched), one `History` row per child
  (`parent_id: <box id> → null`, `changed_at` shared), mass
  `update(['parent_id' => null, 'updated_at' => $detachedAt])` (single query, no model
  events), then `$item->delete()` (its `deleted` event bumps the team revision once via
  the API-003 observer). Response becomes
  `{"success": "success", "detached_ids": [...]}` — additive; authorization and the
  legacy `success` field unchanged. Kept in the controller rather than an extracted
  `App\Actions\DeleteItem` — nothing reuses it yet (YAGNI), trivially extractable when
  the kanban needs it.
- `tests/Feature/ItemDeletionPolicyTest.php` — 8 tests:
  1. box + 3 children → `detached_ids` lists exactly them, children rooted, one
     history row each;
  2. grandchild survival (only the direct child detaches; grandchild follows its
     now-root parent; exactly 1 history row);
  3. childless box → `detached_ids: []`, no history rows;
  4. detached children listed as roots (box absent from the list);
  5. history rows reference the deleting user;
  6. **rollback**: an `Item::deleted` listener that throws inside the transaction →
     500, child still attached, box not tombstoned, zero history rows;
  7. delta integration: next `?since=` pull reports the child in `changed` and only
     the box id in `deleted_ids` (pinned per the ticket's technical note);
  8. `Read Only` member → 403, nothing touched.
- `tests/Feature/ItemSoftDeleteTest.php` — the interim-policy test
  (`test_children_of_deleted_box_keep_their_parent_id`) asserted the behavior API-008
  just replaced; rewritten as `test_children_of_deleted_box_are_detached_to_root`
  (thin pin; the detail lives in ItemDeletionPolicyTest).
- `app/Models/Item.php` — class docblock updated: the tombstone contract now states
  the final API-008 policy instead of the interim one.
- `server.md` — §5 "Decided + implemented" banner; §2's API-005 banner marks the
  interim children policy as superseded.

### Design notes / deviations
- None of substance: the recommended cascade-and-report option shipped exactly as
  specified. `detached_ids` ordering is DB return order (no contract made about it —
  clients get a set).
- Trashed children of a deleted box are deliberately left pointing at the tombstone
  (consistent with API-005: trashed rows are never modified); they are invisible and
  the row is unrecoverable without restore endpoints (out of scope).

### Verification
- `vendor/bin/phpunit --filter 'ItemDeletionPolicyTest|ItemSoftDeleteTest|ItemApiTest'`
  → 33 tests, 111 assertions, green.
- Full suite → **165 tests, 534 assertions, 4 skipped — PASS**.

### Reviewer notes
- Clients that ignore `detached_ids` see byte-identical behavior except the extra
  field (additive contract change, as specified).
- The revision counter bumps exactly once per delete (via `deleted`); the children's
  mass detach fires no events by design.
