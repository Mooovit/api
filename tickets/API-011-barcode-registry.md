---
id: API-011
title: "Barcode registry separate from item ids"
type: feature
priority: P2
status: ready
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
- [ ] Migration: `item_barcodes` table — uuid PK, `item_id` (FK cascade), `team_id`
      (indexed), `code` (string, indexed), `type` (nullable string, e.g. `code128`,
      `ean128`, `qr`), `timestamps`; **unique constraint on (`team_id`, `code`)** —
      uniqueness is scoped to the team, validated server-side.
- [ ] Additive payload field: item resources include `barcodes: ["<code>", ...]` —
      eager-loaded in `show()` (and `index()` — decide by payload-size measurement;
      if index stays lean, list-only is acceptable and documented). Old clients ignore
      unknown fields (additive rule).
- [ ] `POST api/item/{item}/barcodes` — body `{code, type?}` → attaches; 409 on
      duplicate code within the team (mirrors the label-attach style of
      `LabelController::attachToItem`); `item:write` + resource-team authorization.
- [ ] `DELETE api/item/{item}/barcodes/{barcode}` — detaches (id by barcode row id;
      also accept `?code=` lookup for convenience).
- [ ] Feature tests.

## Out of scope
- `GET api/item?barcode=<code>` server-side resolution (optional later per server.md §8
  — only if client-side resolution proves insufficient); printing (client, MV-046/
  MV-062 territory); migrating existing id-embedded stickers (they keep working — ids
  remain the primary scan target, MV-027 unchanged).

## Acceptance criteria
- [ ] Same code twice in one team → 409; same code in two teams → both fine.
- [ ] Item payloads list attached codes; clients that never call the new routes see no
      behavioral change.
- [ ] Foreign-team item → 403/404 on attach/detach; read-only token → 403.
- [ ] Deleting (soft-deleting, API-005) an item hides its barcodes from payloads; codes
      free for reuse is acceptable and documented (rows cascade on hard delete only).

## Technical notes
- Keep `barcodes` off the delta-sync `changed` comparison problem: writes bump the team
  revision (API-003 helper) and touch item `updated_at`? — decision: attach/detach does
  **not** modify the item row's `updated_at` (it's pivot data like labels today);
  document that clients must re-pull an item to see new barcodes (or include the
  relation when they request show).
- Code normalization: store verbatim (case-sensitive, trimmed) — no uppercasing;
  document it.
- Max length guard: `code` string 191; validate `string|max:191`.

## Tests
`vendor/bin/phpunit --filter ItemBarcodeTest`:
- attach/list/detach happy path; duplicate 409; cross-team duplicate allowed;
- payload contains `barcodes`; permission matrix; soft-deleted item hides codes.

## Documentation requirements
- PHPDoc on model + controller; `server.md` §8 mark implemented (note the client-side
  resolution contract stands); plan.md §2 contract notes (`barcodes` field is additive).
