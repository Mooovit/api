---
id: API-011
title: "Barcode registry separate from item ids"
type: feature
priority: P2
status: in-review
depends_on: [API-001]
spec: "server.md §8; client context: MV-027/MV-012"
---

# API-011 — Barcode registry separate from item ids

## Context
Scans resolve by *raw item UUID* today (MV-027), which works only because the team's
current stickers embed the ids. Real EAN-128/Code128 carrier labels can't be adopted,
sticker collisions with another item's id are possible, and there is no uniqueness
validation anywhere. This ticket adds a per-team barcode registry attached to items.
Resolution stays client-side (`ScanCodeResolver` gains a barcode index — server.md §8);
the server only stores, validates uniqueness, and ships the codes on item payloads.

## Scope — Must have
- [x] Migration: `item_barcodes` table — uuid PK, `item_id` (FK cascade), `team_id`
      (indexed), `code` (string 191, indexed), `type` (nullable string 191, e.g.
      `code128`, `ean128`, `qr`), `timestamps`; **unique constraint on (`team_id`,
      `code`)** — uniqueness is scoped to the team, validated server-side.
- [x] Additive payload field: item resources include `barcodes: [...]` — loaded in
      `show()`; index/delta stay lean (decision documented, see Report).
- [x] `POST api/item/{item}/barcodes` — body `{code, type?}` → attaches; 409 on
      duplicate code within the team (mirrors the label-attach style of
      `LabelController::attachToItem`); `item:write` + resource-team authorization.
- [x] `DELETE api/item/{item}/barcodes/{barcode}` — detaches (id by barcode row id;
      also accepts a code in the path, or `?code=` which wins when present).
- [x] Feature tests.

## Out of scope
- `GET api/item?barcode=<code>` server-side resolution (optional later per server.md §8
  — only if client-side resolution proves insufficient); printing (client, MV-046/
  MV-062 territory); migrating existing id-embedded stickers (they keep working — ids
  remain the primary scan target, MV-027 unchanged).

## Acceptance criteria
- [x] Same code twice in one team → 409; same code in two teams → both fine.
- [x] Item payloads list attached codes; clients that never call the new routes see no
      behavioral change (additive: `show()` only — index unchanged, pinned by test).
- [x] Foreign-team item → 403 on attach/detach; read-only token → 403.
- [x] Deleting (soft-deleting, API-005) an item hides its barcodes from payloads (show
      404s); codes stay reserved while the item is trashed — documented (rows cascade
      on hard delete only).

## Technical notes
- Keep `barcodes` off the delta-sync `changed` comparison problem: attach/detach bumps
  the team revision (API-003 helper) but does **not** modify the item row's
  `updated_at` (pivot data like labels today); clients re-pull an item (show) to see
  new barcodes.
- Code normalization: store verbatim (case-sensitive, trimmed) — no uppercasing.
- Max length guard: `code`/`type` string 191; validate `string|max:191`.

## Report (implementation)
- **Storage**: `2026_09_05_000006_create_item_barcodes_table` (uuid PK, `item_id` FK
  cascade, `team_id` indexed, `code`/`type` string(191), `unique(team_id, code)` at
  the DB level); `App\Models\ItemBarcode` (`Uuids`, `item()`/`team()`);
  `Item::barcodes()` hasMany; `App\Http\Controllers\ItemBarcodeController`
  (`attach`/`detach`); routes `POST item/{item}/barcodes` +
  `DELETE item/{item}/barcodes/{barcode}` in the `auth:sanctum` group.
- **attach**: `item:write` on the item's own team + token ability → 403; validation
  `code required|string|max:191`, `type nullable|string|max:191`; code stored
  whitespace-trimmed, case-sensitive; duplicate within the team (checked against the
  raw table — soft-deleted items' rows included, so trashed items keep their codes
  reserved) → **409** with `{error}`; on success creates the row, bumps the team
  revision once (`TeamRevision::bump` — registry rows fire no observer), returns
  `{success, item: fresh(['barcodes'])}` with 201 so clients get the updated payload
  in one shot.
- **detach**: `{barcode}` matches row id first, then code within that item; `?code=`
  overrides both; the row must belong to the path item (else 404). Deletes the row,
  bumps the revision once, returns `{success, item: fresh(['barcodes'])}`.
- **Payload decision (documented, pinned by test)**: `show()` returns the full
  `barcodes` row objects (parity with the `labels` relation already there; the row id
  doubles as the DELETE target); `index()`/`?since=` deltas stay **lean — no
  barcodes**. This is the ticket's sanctioned "list-only" alternative; clients re-pull
  an item after a registry write (the revision bump tells them *something* changed).
  Deviation note: payload carries row objects instead of the sketched bare-string
  array — same rationale (ids for DELETE + parity with labels).
- **Semantics pinned by tests**: registry writes never touch the item's `updated_at`
  (deltas carry no item body for barcode changes) but bump the revision; trashed-item
  codes stay reserved (409 on reuse) until hard delete.
- **Tests**: `tests/Feature/ItemBarcodeTest.php` — 11 tests: attach/payload/201 +
  optional type; lean index; trim+case-sensitivity; duplicate 409 (same + other item);
  cross-team same code ok; detach by id / code-path / `?code=`; revision-vs-updated_at;
  403 matrix (token, Read-Only member, foreign item, detach); 404s (unknown id, code
  on another item); soft-delete hiding + reservation; validation (missing code, >191
  lengths).
- **Verification**: `--filter ItemBarcodeTest` 11/11; full suite 202 tests / 717
  assertions / 4 pre-existing Jetstream skips, green.
