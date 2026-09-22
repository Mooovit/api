---
id: API-035
title: "Location barcodes — nullable locations.barcode (bind shelf locator stickers)"
type: feature
priority: P1
status: in-review
depends_on: [API-034, API-011]
spec: "user request 2026-09-21 (physical locator stickers already on shelves); barcode precedent: item_barcodes/API-011; hierarchy: API-034; client contract: plan.md §2 (Android repo); client side: MV-153"
---

# API-035 — Location barcodes (nullable `locations.barcode`)

## Context

The user (2026-09-21): shelves/locations already carry physical barcode/QR
stickers ("locators"). The Android client ships the feature client-side in
**MV-153**: a location gets ONE optional code (single nullable column — not a
registry table), and scanning it is used for **filtering** (items list,
sub-tree aware) and **starting/switching a location audit**. The client also
prints NEW location QR labels whose payload is the raw **location id** (the
box-label convention, plan.md §4.6) — so a location is resolved by id OR by
its stored barcode, items-first (a code on both an item and a location →
item wins; cross-resource uniqueness is NOT enforced server-side, resolution
order is client-side).

Today `locations` is `{id, name, team_id, parent_id, timestamps,
deleted_at}` and `Location::$fillable = ['name', 'team_id', 'parent_id']`
(API-034). The barcode precedent is the item registry: `item_barcodes` has
`unique(team_id, code)` (API-011) and `ItemBarcodeController::attach`
validates `code required|string|max:191`, trims, and answers **409
`{'error': …}`** on a team-scoped duplicate including soft-deleted rows'
codes (ItemBarcodeController.php:53-62). This ticket adds the equivalent,
simpler single-column semantics to locations.

## Scope — Must have

- [x] **Migration**: nullable `string('barcode', 191)` on `locations`
      (the repo's barcode idiom — `item_barcodes.code`, migration
      2026_09_05_000006:25). No DB unique index (soft-deleted rows + the
      SQLite suite make `unique(team_id, barcode)` fragile; uniqueness is
      enforced controller-level like the item registry's 409). Plain ALTER —
      `migrate` on an existing DB and `migrate:fresh` both succeed.
- [x] **Model** (`app/Models/Location.php`): `barcode` added to
      `$fillable` — the model is serialized directly (no API Resource), so
      `barcode` rides index/show/mutation payloads additively.
- [x] **Store** (`LocationController::store`): validate
      `barcode => nullable|string|max:191`, trim, team-scoped duplicate
      check **withTrashed** (a trashed location keeps its code reserved,
      mirroring the item registry's duplicate rule) → 409
      `{'error': 'Barcode already assigned to a location in this team'}`.
- [x] **Update** (`LocationController::update`, legacy resource PATCH —
      no new route, honoring the no-new-PATCH rule): `barcode` key OPTIONAL
      — absent → untouched; present `null` → cleared; value → set +
      duplicate check excluding the row itself. Rides the same validation
      as store.
- [x] **Contract check**: additive only — old clients never sent `barcode`
      and still parse the responses; the full-pull settings refresh
      (plan.md §4.14) picks the field up with no delta work (locations stay
      out of the delta feed, server.md §2).

## Out of scope

- A `location_barcodes` registry table (multiple codes per location) — the
  user asked for one code per location; revisit if that changes.
- Cross-resource uniqueness (location codes vs `item_barcodes.code`) —
  intentionally not enforced; the documented resolution order (items-first)
  makes collisions benign.
- Location QR label printing endpoints — the client renders/prints locally
  from the raw location id (MV-153).
- Backups/savepoint CSVs gaining `barcode` (API-018..020 follow-up, same
  deferral as `parent_id`).
- Kanban / web SPA UI.

## Acceptance criteria

- [x] `POST api/location` with `barcode` persists the trimmed value; the
      index/show rows carry it (null for untagged locations).
- [x] Creating/updating a location with a barcode already held by another
      location of the same team → **409** with the error message.
- [x] A duplicate held by a **soft-deleted** location → 409 (code stays
      reserved).
- [x] `PATCH api/location/:id` with `{barcode: X}` sets it; with
      `{barcode: null}` clears it; **without** the key leaves it untouched.
- [x] Re-assigning a location its own barcode is not a 409.
- [x] Two locations in different teams may hold the same code.
- [x] Team revision moves on barcode writes (model observer — verify).
- [x] Existing `LocationApiTest` pins pass unchanged; full phpunit suite
      green; `migrate:fresh` succeeds.

## Technical notes

- Files: new migration
  `database/migrations/2026_09_21_000001_add_barcode_to_locations_table.php`,
  `app/Models/Location.php`, `app/Http/Controllers/LocationController.php`,
  `tests/Feature/LocationApiTest.php`.
- Duplicate-check helper on the controller (`assertBarcodeAvailable`-style)
  returns the 409 JsonResponse — copy the shape of
  ItemBarcodeController.php:60-62 (`{'error': …}`), not a 422 (duplicates
  are conflicts, not validation).
- Case sensitivity: MySQL default collation is case-insensitive, so the
  server rejects mixed-case duplicates of a held code; the client
  validates case-insensitively too (stricter, same direction). The client
  *matches* scanned codes case-sensitively — same as item barcodes
  (documented divergence, harmless).
- SQLite test quirk: none expected — plain nullable column, no FK/index.
- The trashed-inclusive index (API-027) carries `barcode` on tombstones —
  harmless, and keeps history resolvable.

## Tests

Feature tests extending `tests/Feature/LocationApiTest.php`:

- store with barcode → stored trimmed; index carries it; untagged → null.
- duplicate (same team, second location) → 409 on store AND on PATCH.
- duplicate held by a trashed location → 409.
- PATCH: set / clear (`null`) / absent key (untouched) / self-assign (no 409).
- cross-team duplicate is allowed (two teams, same code).
- revision bumps on a barcode PATCH (`/api/revision` pattern of API-034's
  revision test).

Run: `vendor/bin/phpunit --filter LocationApiTest` then the full suite;
`php artisan migrate:fresh` once.

## Documentation requirements

- PHPDoc on the duplicate-check helper + the store/update barcode blocks.
- `server.md`: "Implemented 2026-09-21, API-035" note in the locations
  section — semantics (nullable, team-scoped 409 incl. trashed, PATCH
  absent/null/value key rules, printed QR = raw location id, items-first
  client resolution).
- Request/response examples in the ticket's implementation report (field
  names are contract).

## Implementation report

*(filled by the implementing agent — do not touch before starting work)*

### Blockers
- none (env note: the dev MySQL at 127.0.0.1:3309 is still unreachable —
  "Connection refused", the homebrew `mysql` service remains stopped, same
  as API-034 — so the MySQL-path `migrate:fresh` could not be executed
  here; the full chain was verified on a scratch SQLite DB, the engine the
  suite uses).

### Changes
- Migration `2026_09_21_000001_add_barcode_to_locations_table` — plain
  nullable `string('barcode', 191)`, NO unique index (a soft-deleted
  location keeps its code reserved; uniqueness is controller-level, and
  the SQLite suite makes `unique(team_id, barcode)` fragile).
- `Location::$fillable` gains `barcode` — `index`/`show` serialize the
  model directly → the field rides every payload additively, no API
  Resource.
- `store`: `barcode => nullable|string|max:191`, trimmed (whitespace-only
  → null), then the trashed-inclusive team-scoped duplicate check before
  the row is created.
- `update` (legacy resource PATCH, no new route): key OPTIONAL — absent →
  untouched; present `null` (or a value trimming to empty) → cleared;
  a value → set + duplicate check excluding the row itself.
- New private `assertBarcodeAvailable(?string $barcode, string $teamId,
  ?string $ignoreId = null)` — `Location::withTrashed()->where('team_id',
  …)->where('barcode', …)->when($ignoreId, whereKeyNot)->exists()` → 409
  `{'error': 'Barcode already assigned to a location in this team'}` via
  `HttpResponseException` (the AddTeamMember.php:53 idiom — keeps the
  typed `store(): Location` / `update(): Location` signatures intact; the
  JSON shape is ItemBarcodeController.php:61's).
- Revision bumps need no new code — `BumpsTeamRevisionObserver` fires on
  the model events the barcode writes already produce (pinned by test).

Contract examples (field names are the contract):

```
POST api/location
{"name": "Black shelf", "team_id": "<team uuid>", "barcode": "  LOC-042  "}
→ 201 {"id": "<uuid>", "name": "Black shelf", "team_id": "<team uuid>",
       "parent_id": null, "barcode": "LOC-042", "created_at": …, "updated_at": …}

PATCH api/location/{id}
{"name": "Black shelf", "barcode": "LOC-999"} → 200 …, "barcode": "LOC-999"
{"name": "Black shelf", "barcode": null}      → 200 …, "barcode": null
{"name": "Renamed only"}                      → 200 (barcode untouched)
{"name": "X", "barcode": "LOC-042"}           # held elsewhere in the team
→ 409 {"error": "Barcode already assigned to a location in this team"}
```

### Files touched
- `database/migrations/2026_09_21_000001_add_barcode_to_locations_table.php` (new)
- `app/Models/Location.php`
- `app/Http/Controllers/LocationController.php`
- `tests/Feature/LocationApiTest.php` (6 new tests)
- `server.md` (locations section — API-035 blockquote)
- `tickets/README.md` (mapping rows — API-035 added; the pending uncommitted
  API-034 row landed with this commit)
- `tickets/API-035-location-barcodes.md` (this report)

### Tests run
```
vendor/bin/phpunit --filter LocationApiTest   → OK (23 tests, 120 assertions)
vendor/bin/phpunit                            → OK (423 tests, 2379 assertions; 4 pre-existing skips)
DB_CONNECTION=sqlite DB_DATABASE=/tmp/… php artisan migrate:fresh → OK — full chain incl. the new migration
DB_CONNECTION=sqlite DB_DATABASE=/tmp/… php artisan migrate       → "Nothing to migrate." (success)
php artisan migrate:fresh (dev MySQL 127.0.0.1:3309) → connection refused (MySQL stopped — see Blockers)
```

### Commits
- `(this commit) API-035(feat): location barcodes — nullable locations.barcode + team-scoped 409`

### Notes for reviewer
- The 409 rides `HttpResponseException` so the typed controller signatures
  stay; the body matches the item registry's duplicate shape exactly (the
  Android client keys failure mapping off the status code + `error` field).
- PATCH key semantics mirror parent_id's proven pattern: `nullable` keeps a
  present-`null` key in the validated data (clears), an absent key never
  reaches `$data` (untouched). A whitespace-only value normalizes to null
  (clear) rather than storing `''` — an empty string would collide with
  itself under the duplicate check.
- Uniqueness is deliberately controller-level, not a DB index: a
  `unique(team_id, barcode)` would (a) block the trashed-row reservation
  rule and (b) be fragile under the SQLite suite — the ticket anticipated
  both.
- Cross-resource collisions (location barcode vs `item_barcodes.code`) are
  intentionally unenforced — resolution order is client-side, items-first
  (documented in server.md; client side is MV-153).
