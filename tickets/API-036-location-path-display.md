---
id: API-036
title: "Location path display — \"Garage > Black shelf\" on the web pages (kanban, details, selects, public share)"
type: feature
priority: P2
status: done
depends_on: [API-034, API-027]
spec: "no server.md section — web-only display follow-up to API-034; client contract: plan.md §2, §4.14 (Android resolves the tree client-side, MV-152); client side: web only"
---

# API-036 — Location path display on the web pages

## Context

API-034 gave locations a hierarchy (`locations.parent_id`), but it is
invisible in the web UI: cards, column titles, the details modal, search
results, selects and the Manage Locations tab all show the BARE location
name, so an item in "Black shelf" (child of "Garage") has no hint of where
that shelf hangs. The ticket of record for API-034 explicitly left "Kanban /
web SPA UI changes" out ("client-side concern"). The user (2026-09-22):
"show on the pages (kanban, location, ...) when an object is in a
sub-location … the path to that sub location".

Today `Location` has `parent()`/`children()` (Location.php:35-48) and NO
path helper; `KanbanController::boardRow()` denormalizes `location_name`
only (line 56); the JS renders `item.location.name` in the details modal /
search / filter (kanban.js 611/1144/1898); `public/box.blade.php` renders
`$box->location->name`. The mobile API is a hard no-go: `api/location` and
`api/item` must gain NOTHING (Android builds the tree client-side, MV-152;
a model `$appends` would leak a computed field into every serialization AND
N+1 the full-pull settings refresh).

## Scope — Must have

- [x] **Helper** (`Location::pathsForTeam(string $teamId): array`): id =>
      `"Garage > Black shelf"` (roots map to their own name). ONE
      trashed-inclusive team query (API-027 tombstone names still render),
      parent chains walked in PHP — no N+1; visited-set + depth cap 20 so
      a corrupt cycle terminates with partial paths; per-request static
      memoization + `clearPathsCache()` for tests. Never serialized — no
      `$appends`, no API Resource.
- [x] **Board rows** (`boardRow()`): additive `location_path` next to
      `location_name` (falls back to the bare name when the id is missing
      from the map); `index()` and `delta()` compute the map once per
      request and pass it in — the delta/live view inherits the field for
      free.
- [x] **Location-board column titles**: a sub-location column shows the
      path ("Garage > Black shelf"); status-board columns stay bare names.
- [x] **Blade** (`kanban.blade.php`): card chip renders `location_path ??
      location_name`; batch-move + filter location selects show path labels
      on plain-id options; the Manage Locations list shows a path hint under
      the editable name; `window.KANBAN_LOCATION_PATHS = @json(...)` is
      injected for the JS consumers.
- [x] **JS** (`public/js/kanban.js`, no build step): delta-rendered chip
      uses `row.location_path || row.location_name`; details modal / search
      results / filter results resolve via `locationDisplay(location)`
      (map lookup, name fallback).
- [x] **Activity feed**: `resolveValueName`'s location case resolves
      through the path map (id => path array replaces the name pluck in
      `activity()` + `getRecentHistory()`); the item-details modal's
      history (`resolveValueToName`) resolves location_id through the map
      too (team id passed from the item).
- [x] **Public share page** (`PublicShareController` + `public/box.blade.php`):
      box header + content rows render the path — the map is resolved
      server-side, only composed strings reach the markup (no ids leak).
- [x] **Contract check**: `GET api/location` / `GET api/item` byte-shape
      unchanged — pinned by a regression test asserting no `path`/
      `location_path` key on index rows.

## Out of scope

- Any mobile API surface change (Android resolves the hierarchy itself).
- Re-ordering location-board columns parent-then-children (display order
  stays the catalogue's).
- Creating/re-parenting sub-locations from the web UI (kanban session
  `createLocation`/`updateLocation` stay name-only — API-034's web follow-up
  would be a separate ticket).
- The cold-storage snapshot gaining `parent_id`/paths (API-018..020
  follow-up, backups restore flat).

## Acceptance criteria

- [x] Status board: item in "Black shelf" (parent "Garage") renders the
      chip "Garage > Black shelf"; a root-location item stays its bare name.
- [x] `GET /kanban/delta` rows carry `location_path` so live-updated cards
      render the path without a page reload.
- [x] Location board: the sub-location column title shows the path.
- [x] Batch/filter selects show path labels; values stay plain ids.
- [x] A trashed sub-location still resolves (trashed-inclusive, API-027).
- [x] A corrupt parent cycle does not hang the walk (terminates, partial
      paths).
- [x] The public share page shows the path and still leaks no ids.
- [x] `GET api/location` rows gain no computed path field (regression pin).

## Technical notes

- `pathsForTeam()` walks from each row UPWARD (`array_unshift` the name,
  jump to the parent row from the keyed map) — the depth cap bounds the
  `while (true)` even before the visited set fires.
- The Blade-injected `KANBAN_LOCATION_PATHS` map is page-load-snapshot:
  modal/search/filter paths can go one refresh stale after a rename, but
  the board cards and delta rows always carry fresh server-computed paths.
- `resolveValueName()`'s `$locations` parameter changed from a Collection
  (name pluck) to the id => path ARRAY — both call sites updated; the
  status/labels plucks are untouched.
- Files: `app/Models/Location.php`, `app/Http/Controllers/KanbanController.php`,
  `app/Http/Controllers/PublicShareController.php`,
  `resources/views/kanban.blade.php`, `resources/views/public/box.blade.php`,
  `public/js/kanban.js`.

## Tests

- `tests/Feature/KanbanTest.php` (7 new): status-board path + bare-name
  parity + escaped HTML chip; location-board column titles + select option
  labels; delta rows carry `location_path`; trashed sub-location keeps the
  path; `pathsForTeam` cycle safety (self-parent terminates); history +
  item-details resolve sub-location paths; the page ships
  `KANBAN_LOCATION_PATHS` and kanban.js has the resolvers.
- `tests/Feature/ItemShareLinkTest.php` (1 new): public page renders
  "Garage > Black shelf" for the box header, bare "Garage" for the content
  row, and still contains no uuids.
- `tests/Feature/LocationApiTest.php` (1 new): index rows never gain
  `path`/`location_path`.

Run: `vendor/bin/phpunit --filter "KanbanTest|ItemShareLinkTest|LocationApiTest"`
then the full suite.

## Documentation requirements

- PHPDoc on `Location::pathsForTeam()/clearPathsCache()` and the touched
  controller helpers (semantics: trashed-inclusive, cycle-safe, never
  serialized).
- `server.md`: "Implemented 2026-09" — API-036 note (web-only, API
  contract unchanged).

## Implementation report

*(filled by the implementing agent — do not touch before starting work)*

### Blockers
- none.

### Changes
- `Location::pathsForTeam()` + `clearPathsCache()` — single trashed-inclusive
  query per team, PHP-side ancestor walk (visited set + depth cap 20,
  partial paths on corrupt chains), static per-request memoization.
- `boardRow(Item $item, array $locationPaths = [])` gained additive
  `location_path`; `index()` + `delta()` compute the map once and pass it;
  location-board column titles use it when `$type === 'location'`.
- `activity()`/`getRecentHistory()` resolve location history rows through
  the path map; `resolveValueName()`'s location case became an array
  lookup; `resolveValueToName()` gained an optional `$teamId` and resolves
  location_id via the map for the details modal.
- Blade: chip renders `location_path ?? location_name`; both location
  selects use path labels on id values; Manage Locations shows a path hint
  when the path differs from the name; `window.KANBAN_LOCATION_PATHS`
  injected before the kanban.js include.
- kanban.js: `locationDisplay()` helper; delta chip prefers
  `row.location_path`; modal/search/filter render via the helper.
- Public share: controller passes the map; box header + content rows render
  the path.

### Files touched
- `app/Models/Location.php`
- `app/Http/Controllers/KanbanController.php`
- `app/Http/Controllers/PublicShareController.php`
- `resources/views/kanban.blade.php`
- `resources/views/public/box.blade.php`
- `public/js/kanban.js`
- `tests/Feature/KanbanTest.php`, `tests/Feature/ItemShareLinkTest.php`,
  `tests/Feature/LocationApiTest.php`
- `server.md`, this ticket

### Tests run
```
vendor/bin/phpunit --filter "KanbanTest|ItemShareLinkTest|LocationApiTest"
  → OK (73 tests, 420 assertions)
vendor/bin/phpunit  → (full suite run recorded in the commit report)
```

### Notes for reviewer
- The mobile contract is untouched by construction (no `$appends`, no
  Resource, no controller change under `routes/api.php`) AND by a pinned
  regression test on the location index.
- The injected `KANBAN_LOCATION_PATHS` map is a page-load snapshot (renames
  need a reload to reach the modal); board cards and delta rows are always
  fresh via the server-computed `location_path`.
- The JS map approach was preferred over adding fields to
  `getItemDetails()`/`search()` payloads so the session-flavor JSON keeps
  its exact shape (3 consumers, 1 map).
- Follow-up (2026-09-22, same working tree): delta rows now also refresh
  the injected `KANBAN_LOCATION_PATHS` map (`row.location_path` is
  authoritative), and an OPEN details modal re-fetches itself when its
  item (or one of its children) is among the delta's changed rows —
  closing instead when the item was removed. The "renames need a reload"
  caveat above still holds for location RENAMES (they put no item in the
  delta); moves are covered. `public/js/kanban.js` only: `fetchItemDetails()`
  extracted from `openItemDetails()`, `refreshOpenItemDetailsIfAffected()`
  added, `applyDelta()` wired.
