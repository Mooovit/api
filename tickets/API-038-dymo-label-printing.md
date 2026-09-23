---
id: API-038
title: "DYMO label printing — framework 2.x, print buttons, scan-a-box print station"
type: feature
priority: P2
status: in-review
depends_on: [API-028]
spec: "no server.md section — web-only printing follow-up to API-028 (identity QR); client side: web only"
---

# API-038 — DYMO label printing

## Context
Physically identifying packed goods needs paper stickers. Workstations that have a
DYMO LabelWriter attached (with DYMO Connect installed) should get one-touch printing
of an item label: item **name** + the item's **uuid** encoded **twice** (Code128 and QR,
mirroring the API-028 identity-QR convention so any scanner resolves straight back to
`GET /kanban/item/{uuid}`). Builds on API-028 (uuid QR) and API-034/036 (box contents +
location paths). The 2015-era framework 1.x sample (labelwriter.com, NPAPI) is dead in
modern browsers — this uses the supported DYMO Connect Web API (framework 2.x, localhost
web service). Web-only surface: **no API endpoints, no DB changes**.

## Scope — Must have
- [x] Vendor `dymo.connect.framework.js` (framework 2.x) into `public/js/vendor/` — no runtime CDN (LAN rule)
- [x] `public/js/dymo.js` — `window.Dymo` service: cached printer detection (`body.dymo-ready`), localStorage printer/template prefs (per workstation), label XML builders, sequential print queue
- [x] Label templates (user-selectable, name + Code128 + QR of the uuid): 30334 3.5"×2.125" (default), 30252 3.5"×1.125", 30336 2.25"×1.25" (QR only — Code128 can't share that width)
- [x] Print buttons on kanban: card (blade renderer **and** `buildCardHtml` parity), details modal header, per-child rows in the details modal Contents list
- [x] Label print station page `/label-print` (session auth): scan box uuid QR → contents grid → touch rows to print; DYMO status strip; printer + template selectors
- [x] Sidebar link "Label print station" on the kanban page

## Out of scope
- Framework 1.x / NPAPI integration (dead tech).
- Server-side label rendering (PDF etc.) — printing happens client-side via DYMO Connect.
- Tape printers (Macho/P touch) beyond framework pass-through — UI filters to LabelWriter.
- Any `routes/api.php` / DB change. Reuses `GET /kanban/item/{itemId}` (item + children).

## Acceptance criteria
- [ ] Guest hitting `/label-print` is redirected to login; authed session gets 200.
- [ ] No `.dymo-print` button is visible until detection succeeds (`body.dymo-ready`); with no DYMO service/printers the pages degrade gracefully with a reason on the station status strip.
- [ ] `Dymo.buildLabelXml(id, name, uuid)` yields DieCutLabel XML containing `NAME`, `BARCODE` (Code128) and (except 30336) `QRCODE` objects, both barcodes carrying the uuid.
- [ ] Printer + template choices persist across reloads (localStorage), selection applied on print.
- [ ] Blade card renderer and `buildCardHtml` both render the print button (parity after a delta refresh).

## Technical notes
- Framework file sourcing: DYMO CDN links are unstable; fetch from a DYMO Connect install
  (`http://localhost:41951/DYMO/DLS/Printing/dymo.connect.framework.js`) or a pinned
  GitHub mirror commit — record the exact source in the Implementation report.
- Web service is `http://localhost:41951`, CORS-enabled; Safari+HTTPS may need the cert
  trusted — detection failure names the reason instead of a generic error.
- Label XML is DieCutLabel, Units=Twips (1440/inch); QR is a `BarcodeObject` with
  `<Type>QRCode</Type>`. Validate by round-tripping the generated XML through DYMO
  Connect before wiring the button handlers (console smoke: `Dymo.buildLabelXml(...)`).
- Kanban card button placement: blade `kanban.blade.php` lines ~253-258 (next to
  `showCardQr`), JS `buildCardHtml` kanban.js lines ~335-340. Name resolution from the
  card's `.font-medium` child (precedent: kanban.js ~1715).
- Details modal: header action div kanban.js ~632-637; Contents rows ~723-731
  (`event.stopPropagation()` guards).

## Tests
Automated (`vendor/bin/phpunit --filter LabelPrintTest`):
- guest → 302 login redirect on `/label-print`;
- authed session → 200, view contains `labelScanInput`, `js/dymo.js`, `dymo.connect.framework.js`;
- kanban board page still renders (200) and carries the `dymo-print` card hook.

Real printing cannot be automated here — manual checklist in the reviewer notes:
print one label per template on hardware; scan the QR **and** the Code128; verify
buttons hidden without the DYMO service running.

## Documentation requirements
- PHPDoc on `LabelPrintController`.
- `server.md`: implemented-entries section gained the "DYMO label printing (API-038, WEB-ONLY)" entry.
- This ticket's mapping row in `tickets/README.md`.

## Implementation report
*(filled by the implementing agent — do not touch before starting work)*

### Blockers
- none

### Changes
- Vendored the official DYMO Connect framework 2.x build
  (`https://download.dymo.com/dymo/Software/JavaScript/dymo.connect.framework.js`,
  344 KB, `/* DYMO.Label.Framework */` build with getPrintersAsync /
  checkEnvironment / openLabelXml / printLabel) into
  `public/js/vendor/` — no runtime CDN.
- `public/js/dymo.js` (`window.Dymo`): cached detection (5s timeout,
  `body.dymo-ready` + `dymo-checked` markers), localStorage printer/template
  prefs, three DieCutLabel templates (30334 name+Code128+QR default, 30252
  name+Code128+QR slim, 30336 name+QR only), `printItem` +
  `printItemQueued` (serialized queue).
- Kanban hooks: card print icon (blade + `buildCardHtml` parity), details
  modal header "Print label" button, per-child print icons in the Contents
  list, sidebar link, `printItemLabel` global (name resolved from DOM, ids
  only through inline handlers).
- Print station `GET /label-print` (`LabelPrintController` + blade +
  `label-print.js`): DYMO status strip with printer/template selects,
  scan-to-load via existing `GET /kanban/item/{itemId}`, touch-to-print
  contents grid with per-row state, box's own label button; childless items
  offer their own label (any sticker re-scans into the station).

### Files touched
- new: `public/js/vendor/dymo.connect.framework.js`, `public/js/dymo.js`,
  `public/js/label-print.js`, `resources/views/label-print.blade.php`,
  `app/Http/Controllers/LabelPrintController.php`,
  `tests/Feature/LabelPrintTest.php`, this ticket
- modified: `routes/web.php` (`/label-print` in the auth:sanctum group),
  `resources/views/kanban.blade.php`, `public/js/kanban.js`,
  `server.md`, `tickets/README.md`

### Tests run
```
vendor/bin/phpunit --filter LabelPrintTest  → PASS, 4 tests, 16 assertions
vendor/bin/phpunit                          → PASS, 457 tests (4 pre-existing Jetstream skips)
node --check public/js/{dymo,label-print,kanban}.js → syntax OK
```

### Commits
- `api-038-dymo-labels` branch tip: `API-038(feat): DYMO label printing — framework 2.x, print buttons, print station`

### Notes for reviewer
Manual hardware checklist (printing cannot be exercised in CI):
- [ ] Install/launch DYMO Connect on the workstation (menu-bar service running).
- [ ] Kanban page: print icons appear ONLY when the service + a LabelWriter are up
      (`.dymo-print` is CSS-gated on `body.dymo-ready`); station status strip names
      the failure reason when not.
- [ ] Print one label per template (30334/30252/30336) from the print station.
- [ ] Scan the QR AND the Code128 of each printed label back into Quick Scan —
      each must open the item's details. Watch the 30252 Code128 density; if a
      scanner can't read it, the fallback is a small mono uuid text under the
      barcode (contingency in the plan).
- [ ] Selections (printer/template) persist across reloads via localStorage.
- [ ] After a delta board refresh, newly rendered cards still show the print icon
      (blade/`buildCardHtml` parity).
