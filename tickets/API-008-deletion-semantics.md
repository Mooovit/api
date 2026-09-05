---
id: API-008
title: "Deletion semantics for non-empty boxes"
type: feature
priority: P1
status: ready
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
- [ ] `DELETE api/item/:id` on a box with children: in one transaction — soft-delete the
      box (API-005) and set `parent_id = null` on every direct child (they become
      roots).
- [ ] Response gains `{"success": "success", "detached_ids": ["<uuid>", ...]}` —
      additive field; clients that ignore it behave exactly as today.
- [ ] One history row per detached child: `field_name=parent_id`, `old_value=<deleted
      box id>`, `new_value=null` ("removed from deleted box X" renders client-side from
      this — matches MV-077's device-action history rendering).
- [ ] Deep boxes: children are detached (not deleted, not recursively flattened
      further); grandchildren keep their own parents.
- [ ] Documentation of the decision in `server.md` §5 (mark decided + implemented).
- [ ] Feature tests.

## Out of scope
- The refuse-until-confirmed alternative (`409` + `?detach_children=1`) — server.md §5
  offers either; we ship the recommended cascade-and-report. Revisit only if real
  accidents happen.
- Restoring a deleted box with its children back (no restore endpoints exist).
- Status/location/label cleanup on delete (labels pivot rows of a trashed box stay —
  invisible, harmless; revisit if item soft-deletes get restored).

## Acceptance criteria
- [ ] Box with 3 children deleted → 3 children now have `parent_id = null` and appear
      as roots; each has one `parent_id` history row; `detached_ids` lists exactly them.
- [ ] Box without children → `detached_ids: []`, response otherwise unchanged.
- [ ] All in one transaction: a forced failure mid-detach leaves no half-detached
      children (test with a model event throwing, or verify transaction wrapping by
      code review + unit test on the service method).
- [ ] Authorization unchanged (`item:write` + resource team); kanban no longer shows
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
