---
id: API-025
title: "Kanban QR codes per item + public share-link visibility on the kanban page"
type: feature
priority: P2
status: in-review
depends_on: [API-024]
spec: "— (user request: QR codes per box/item on the kanban page + see the share link there, Matthieu 2026-09)"
---

# API-025 — Kanban QR codes per item + share-link visibility

## Context
The kanban board is where stickers get printed and boxes get handled, so this
is where QR codes belong: each card should make it trivial to get a scannable
QR for that box, and the share link created in API-024 must be visible and
manageable from the item-details modal (create / copy / deactivate / QR).
Constraint: the kanban page is a **standalone blade page** (no Vue bundle, no
Ziggy) — `qrcode.vue` from `package.json` is unusable there, so a vanilla JS
QR library must be vendored into `public/js/vendor/`.

## Scope — Must have
- [ ] Vendor a small vanilla QR generator (MIT-licensed, single-file, e.g.
      `qrcode-generator` or davidshimjs `qrcodejs`) into
      `public/js/vendor/` + `<script>` include in `kanban.blade.php`.
- [ ] Item-details modal: new "Share & QR" section —
      - shows the current share link state (active URL / not shared);
      - "Create link" / "Deactivate" buttons calling
        `POST|DELETE /kanban/item/{id}/share` style web-route proxies (the
        blade page cannot call `/api/*` — no session cookie there; see the
        Kernel.php note: no `EnsureFrontendRequestsAreStateful` on the api
        group) **or** call the API endpoints via the web group equivalents —
        add `POST /kanban/item/{itemId}/share` + `DELETE
        /kanban/item/{itemId}/share` to `KanbanController` mirroring API-024's
        logic (reuse the same model/helpers — no duplicate rules);
      - "Show QR" renders the share URL as a QR (and a Copy button).
- [ ] Per-card QR affordance: small QR icon button on each card (or in the
      card context) that opens a QR of the item's share URL — creating the
      link on the fly if none is active (POST first, then render).
- [ ] Feature tests for the two new web routes (auth, team scoping, payloads)
      + kanban blade smoke: vendor script tag present.

## Out of scope
- Printing sheets / label layout (MV-046/MV-062 client territory).
- QR of barcodes already attached via API-011 (the registry codes are for
  scanning *items*, not sharing); only the share URL is encoded here.
- Changing the existing card markup contract (delta-sync `buildCardHtml` in
  `kanban.js` must gain the same QR button so live-created cards match — keep
  the two markup paths in sync).

## Acceptance criteria
- [ ] From a card: user gets a scannable QR that opens `/share/{token}` on a
      phone (logged-out) showing the box contents.
- [ ] Deactivating from the details modal kills the public URL (404 afterwards).
- [ ] The QR button appears on cards created by live delta application too
      (markup parity with server-rendered cards).
- [ ] Web routes are session-auth'd, team-scoped, `item:write`-gated; foreign
      item → 404; read-only member → 403.
- [ ] No request from the kanban page targets `/api/*`.

## Technical notes
- `kanban.js` patterns to follow: axios POST/DELETE with hardcoded paths,
  re-open details after mutation (`attachBarcodeToCurrentItem` style), 409
  handling with sounds where applicable.
- Existing `openItemDetails` already fetches `getItemDetails` — extend its
  payload (`KanbanController::getItemDetails`) with the share state
  (`share: {url, token, activated_at} | null`) instead of a new round-trip.
  Additive field — the Android client never reads this endpoint.
- QR lib pick: needs SVG or canvas rendering, offline/local file only (no CDN
  — the kanban page must work on the LAN). Keep the file name stable for
  caching.
- Escape the share URL into `data-` attributes, never inline JS strings.

## Tests
- `KanbanTest` additions: `POST /kanban/item/{id}/share` (201 + payload),
  `DELETE` (deactivate), re-issue ≠ old token, 403 read-only, 404 foreign,
  `getItemDetails` carries `share` state.
- Blade smoke: `/kanban/{type}` HTML contains the vendor QR script include.

Run: `php artisan test --filter KanbanTest`, then full suite.

## Documentation requirements
- PHPDoc on the new controller methods; `server.md` note (kanban QR + share
  visibility); comment in `kanban.blade.php` near the script include naming
  the vendored lib + license.

## Implementation report
*(filled by the implementing agent — do not touch before starting work)*

### Blockers
- none

### Changes
- Vendored davidshimjs/qrcodejs 1.0.0 (MIT) into
  `public/js/vendor/qrcode.min.js` — vanilla JS, global `QRCode`, renders
  SVG/table, zero CDN dependency (works on the LAN); auto-selects the
  smallest QR version that fits the URL (verified `makeCode` → `_getTypeNumber`
  loop), so the ~90-char share URLs are fine.
- Extracted the share-link semantics into `App\Support\ItemShare` (activate /
  revoke / state / payload) — one source of truth now shared by the API
  controller (ItemShareController, refactored to delegate, responses
  unchanged) and the new kanban session flavor.
- `KanbanController`:
  - `shareItem` (POST /kanban/item/{itemId}/share) — current-team scoped
    (404 foreign), item:write-gated, 201 first activation / 200 re-issue,
    returns `{success, share}`;
  - `unshareItem` (DELETE …/share) — idempotent revoke;
  - `getItemDetails` gained the additive `share` state field (no extra
    round-trip for the modal).
- Routes registered in the kanban auth group before the generic `{type}` route.
- kanban.blade.php: vendored script include (comment names lib + license),
  "Public Share QR" modal (name, QR target, URL, copy button), and a QR icon
  button on every server-rendered card (`event.stopPropagation()` so it
  doesn't trigger openItemDetails).
- kanban.js:
  - `buildCardHtml` gained the same QR button — markup parity with
    server-rendered cards (delta-created cards included);
  - `showCardQr(itemId)` — ensures an active link via POST then renders the
    QR modal (creates on the fly per acceptance criteria);
  - details modal "Share & QR" section from `data.share`: active link +
    Show QR / Copy / Deactivate, or "Create link" when none;
  - `createShareFromDetails` / `deactivateShareFromDetails` re-open details
    after mutating (attachBarcode pattern); `currentShareUrl` captured on
    each render;
  - clipboard write with `execCommand` fallback (HTTP LAN contexts);
  - qrModal added to the click-outside close handler + window exports.

### Files touched
- public/js/vendor/qrcode.min.js (new, vendored)
- app/Support/ItemShare.php (new)
- app/Http/Controllers/ItemShareController.php (refactored to delegate)
- app/Http/Controllers/KanbanController.php (shareItem, unshareItem,
  getItemDetails share field)
- routes/web.php
- resources/views/kanban.blade.php (vendor include, QR modal, card button)
- public/js/kanban.js (buildCardHtml parity, showCardQr, QR modal, details
  share section, exports)
- tests/Feature/KanbanTest.php (+3 tests)

### Tests run
```
php artisan test --filter "KanbanTest|ItemShareLinkTest" → 34 passed (0.94s)
```
Covers: kanban activate 201 + payload, public URL live; idempotent re-activate
200 same token; deactivate kills the URL (404); re-issue ≠ old token, old URL
stays dead; foreign item 404; Read-Only member 403 on POST+DELETE;
getItemDetails `share` state (empty → populated); blade smoke (vendor script
tag + showCardQr button + file on disk). Two test-side notes: the blade card
needs a status (items without one render in no column), and static assets
404 through the framework router in tests — asserted on disk instead.

### Commits
- (pending, batch commit at the end of the feature set)

### Notes for reviewer
- The kanban page never targets `/api/*` — the two web proxies reuse
  `App\Support\ItemShare`, so no rule is duplicated (per ticket).
- API-024's ItemShareController responses are byte-identical after the
  refactor — its 12 tests pass untouched.
- QR correctLevel M + auto type sizing chosen for scan reliability of
  ~90-char URLs on printed stickers.
