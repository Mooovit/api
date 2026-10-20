---
id: API-028
title: "Item details shows the box's identity QR — the item's uuid, rendered inline on the kanban details modal"
type: feature
priority: P2
status: in-review
depends_on: [API-025]
spec: "— (user request: see the box's uuid QR on the kanban item details, Matthieu 2026-09)"
---

# API-028 — Identity QR (uuid) on the kanban item details

## Context
The kanban details modal already shows the item's uuid as text (the `ID:`
row) and offers the PUBLIC SHARE QR (API-025, encodes the share URL). The
user wants the box's own identity QR on the details: a QR encoding the
item's **uuid** — the identifier attached to every box/item — so scanning it
yields the raw uuid (search resolves an exact id match; the PDA/app can
resolve the item directly). Web-surface only: `GET /kanban/item/{itemId}`
already carries the uuid as `item.id`, no API change needed.

## Scope — Must have
- [ ] `kanban.js` `displayEnhancedItemDetails`: render an inline QR of
      `data.item.id` in the Item Information panel (vendored qrcodejs,
      same pattern as the share QR), with the uuid as caption and a
      copy-uuid button.
- [ ] `copyItemUuid(uuid)` helper + window export (inline onclick in the
      JS-generated markup resolves against window); share copy wording
      stays intact for share actions.
- [ ] No API/payload change; the share QR/modal is untouched.

## Out of scope
- Card QR buttons (stay share-URL QRs, API-025).
- Any deep-link/URL scheme inside the QR (bare uuid is the contract).
- The Android app (renders its own surfaces).

## Acceptance criteria
- [ ] Opening an item's details shows a QR whose content is the item's
      uuid, next to the uuid text; a copy button copies exactly the uuid.
- [ ] Share section behavior unchanged (pinned by existing tests).

## Tests
- `KanbanTest`: smoke pinning the identity-QR plumbing on the details
  surface (`detailsUuidQr` target + `copyItemUuid`) in the served
  `public/js/kanban.js`, alongside the existing vendored-QR smoke test.

Run: `php artisan test --filter KanbanTest`, then full suite.

## Documentation requirements
- server.md note (identity QR on the details modal).

## Implementation report
*(filled by the implementing agent — do not touch before starting work)*

### Blockers
- none

### Changes
- `public/js/kanban.js`:
  - `displayEnhancedItemDetails` now renders an inline "identity QR" block
    in the Item Information panel: a 160px QR of the item's uuid (vendored
    qrcodejs), the uuid as a `break-all` caption, and a copy button. The
    block re-renders on every details open (fresh DOM node each render —
    no stale canvas).
  - `copyText(text, successMessage)` extracted from `copyShareUrl`
    (clipboard API with the LAN/HTTP textarea fallback);
    `copyShareUrl(url)` delegates (wording unchanged, all existing call
    sites intact); new `copyItemUuid(uuid)` copies the uuid with its own
    wording and is window-exported for the inline onclick.
- No server/payload change: `data.item.id` (the uuid) was already in
  `getItemDetails`.

### Files touched
- public/js/kanban.js (identity-QR block in the details render,
  copyText extraction, copyItemUuid, window export)
- tests/Feature/KanbanTest.php (+1 smoke test)

### Tests run
```
node --check public/js/kanban.js → OK
php artisan test --filter KanbanTest → 29 passed (0.89s)
php artisan test → 359 passed, 4 skipped (pre-existing skips), 0 failures
```

### Commits
- (pending, batch commit)

### Notes for reviewer
- The QR content is the BARE uuid (36 chars → small QR, error level M):
  scanning with a generic scanner yields the uuid text; the kanban search
  resolves an exact id, and the app/PDA can use it directly. Share URLs
  remain a separate affordance (card button + Share & QR section).
- Web-only by design — the details payload already carried the uuid.
