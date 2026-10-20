---
id: API-034
title: "Sub-locations — nullable parent_id on locations (Garage > Black shelf)"
type: feature
priority: P1
status: ready
depends_on: [API-008, API-027]
spec: "no server.md section — new idea (Matthieu 2026-09-20); hierarchy precedent: server.md §5; client contract: plan.md §2, §4.14 (Android repo); client side: MV-152"
---

# API-034 — Sub-locations (nullable `parent_id` on locations)

## Context

The user (2026-09-20): locations are flat, but a real location can be big —
"Garage" — and needs sub-locations — "Garage > Black shelf". The Android
client ships the same feature client-side in **MV-152**; this ticket makes
the server accept, store and serve the hierarchy.

Today `locations` is `{id, name, team_id, timestamps, deleted_at}` (created
in `2021_06_26_192311_create_locations_table.php`, soft deletes added
2026-09-05); `Location::$fillable = ['name', 'team_id']` and the resource
routes (`Route::resource('location', …)`, routes/api.php:96) validate
`name` only. The hierarchy precedent is items: `items.parent_id` is a
nullable self-referencing uuid (`2021_07_07_062013_create_item_relations.php:18-25`)
guarded by `ItemController::wouldCycle()/ensureNoCycle()` → 422
(ItemController.php:92-128), and deleting a box re-parents direct children
to root (server.md §5, API-008). The Android client contract (plan.md §2)
stays intact: `GET api/location` only **gains** a `parent_id` field; the
full-pull settings refresh (plan.md §4.14) picks it up with no delta feed
(server.md §2 keeps settings out of the delta).

## Scope — Must have

- [ ] **Migration**: add a nullable `parent_id` to `locations` — mirror the
      `create_item_relations.php` pattern verbatim (`uuid('parent_id')
      ->nullable()->default(null)` + self-FK `references('id')->on('locations')`
      + index; that pattern already runs under the SQLite test suite).
      `php artisan migrate:fresh` and a plain `migrate` on an existing DB
      must both succeed.
- [ ] **Model** (`app/Models/Location.php`): `parent_id` added to
      `$fillable`; `parent()` BelongsTo self-relation and `children()`
      HasMany self-relation (PHPDoc'd).
- [ ] **Store** (`LocationController::store`, line 49): validate
      `parent_id => nullable` + team-scoped, live-row exists rule —
      `Rule::exists('locations','id')->whereNull('deleted_at')` restricted
      to the request's team (same idiom the item endpoints use for
      `location_id`, with the `checkParents`-style team scoping of
      ItemController.php:30-37). A foreign-team or trashed parent is a
      validation error (422).
- [ ] **Update** (`LocationController::update`, line 106): same
      validation for `parent_id`, plus an imperative cycle guard copied
      from `wouldCycle()/ensureNoCycle()`: walking the candidate parent's
      ancestor chain must never reach the location being moved → otherwise
      422 "Cannot move a location into one of its own descendants." Re-parenting
      to a root (`parent_id: null`) is always allowed. Re-parenting rides
      the existing resource update route (legacy PATCH — clients already
      use it; no new route, honoring the no-new-PATCH rule).
- [ ] **Delete semantics** (`destroy`, line 132, mirror API-008/server.md
      §5): in one transaction — soft delete the location, re-parent its
      **direct children** to root (`parent_id → null`); keep returning
      `{success: 'success'}` and additively report `detached_ids`
      (children re-parented). Team revision bumps come for free via
      `BumpsTeamRevisionObserver` (AppServiceProvider.php:34) — verify
      create/update/delete-with-reparent each bump the counter.
- [ ] **Contract check**: `index` (withTrashed) and `show` serialize the
      model directly — `parent_id` rides automatically; **additive only**,
      no field renamed/removed, old clients unaffected. No API Resource
      introduced.

## Out of scope

- Savepoint backup CSVs / comparison gaining `parent_id` (API-018..020
  follow-up — backups restore flat until then).
- A locations delta feed (`?since=` / `since_revision` stays items-only,
  server.md §2 deferral; clients full-pull locations).
- Cascading (recursive) deletes; moving a location subtree to another
  team; ordering/position column.
- Bulk endpoints and audit endpoints touching location hierarchy
  (they are items-scoped).
- Kanban / web SPA UI changes (client-side concern).

## Acceptance criteria

- [ ] `POST api/location` with `parent_id` of a same-team live location
      persists it; `GET api/location` rows carry `parent_id` (null for
      roots).
- [ ] `PATCH api/location/:id` with `{parent_id: X}` re-parents; with a
      descendant as target → 422; with `null` → becomes a root.
- [ ] A foreign-team or trashed `parent_id` → 422 validation error (and
      cross-team probing leaks nothing).
- [ ] `DELETE api/location/:id` on a parent soft-deletes it, returns
      `detached_ids` with the direct children, and those children are live
      roots afterwards; the tombstone still appears in the trashed-inclusive
      index (API-027 contract intact).
- [ ] Team revision moves on create/update/delete of a sub-location.
- [ ] Existing `LocationApiTest` pins still pass unchanged (additive
      fields only); `php artisan migrate:fresh` succeeds.

## Technical notes

- Files: new migration `database/migrations/2026_09_XX_XXXXXX_add_parent_id_to_locations_table.php`
  (copy `2021_07_07_062013_create_item_relations.php:18-25` — proven under
  the SQLite suite), `app/Models/Location.php`, `app/Http/Controllers/LocationController.php`.
- Cycle guard implementation reference: `ItemController::wouldCycle()`
  (ItemController.php:92-111 — subtree/ancestor walk with a visited set)
  and `ensureNoCycle()` (121-128); `Item::rootAncestor()` (Item.php:132-158)
  shows the trashed-parent/corrupt-chain hardened walk if needed.
- Don't put the cycle rule in Validator rules alone — it needs the
  request's target id, do it imperatively like items do (validator only
  checks exists/team/trashed).
- The `location/{location}/audits` routes (API-010) need no change —
  audits keep their `location_id` FK whatever the hierarchy does.
- SQLite/ALTER quirk: if the FK-on-alter ever trips the test DB, drop the
  FK and keep column + index (integrity is enforced by validation + the
  app-side guards) — but try the verbatim pattern first.

## Tests

Feature tests extending `tests/Feature/LocationApiTest.php`:

- create with parent → stored; index shows `parent_id`; root rows have
  `parent_id: null`.
- update re-parents; update to `null` un-parents; update to own
  descendant → 422 (build the chain a → b → c, then try `a.parent_id = c`).
- foreign-team parent → 422; trashed parent → 422.
- delete a parent: children re-parented to root, `detached_ids` in the
  response, tombstone in the withTrashed index, children untouched.
- revision counter moves on create-with-parent and on
  delete-with-reparent (`RevisionApiTest` pattern).
- `parent_id` accepted on the legacy PATCH (resource update) route exactly
  as the Android client calls it (plan.md §2).

Run: `vendor/bin/phpunit --filter "LocationApiTest|RevisionApiTest"` then
the full suite; `php artisan migrate:fresh` once.

## Documentation requirements

- PHPDoc on `Location::parent()/children()` and on the cycle-guard
  helper; document the delete re-parenting semantics in the `destroy`
  docblock (mirror API-008's).
- `server.md`: add a line to the "Implemented 2026-09" section
  (locations hierarchy, API-034) — the idea has no § of its own.
- Request/response examples for create + move in the ticket's
  implementation report (field names are contract).

## Implementation report

*(filled by the implementing agent — do not touch before starting work)*

### Blockers
- none (env note: the dev MySQL at 127.0.0.1:3309 was unreachable —
  "Connection refused", the homebrew `mysql` service is stopped — so the
  MySQL-path `migrate:fresh` could not be executed here; the full chain
  was verified on a scratch SQLite DB instead, the engine the suite uses).

### Changes
- Migration `2026_09_20_000001_add_parent_id_to_locations_table` —
  nullable indexed `parent_id` + self-FK, copied verbatim from the
  `create_item_relations` pattern (Laravel skips FK constraints on SQLite
  ALTERs — integrity is enforced app-side, exactly the quirk the ticket
  anticipated; nothing tripped).
- `Location::$fillable` gains `parent_id`; `parent()` BelongsTo /
  `children()` HasMany self-relations (PHPDoc'd). `index`/`show` serialize
  the model directly → `parent_id` rides additively, no API Resource.
- `store`: `parent_id` nullable + `Rule::exists('locations','id')
  ->whereNull('deleted_at')->where('team_id', $request->input('team_id'))`
  — foreign-team/trashed parent = generic 422.
- `update` (legacy resource PATCH): same rule scoped to
  `$location->team_id`; key semantics — absent key leaves the hierarchy
  untouched, present `null` un-parents to root. Cycle guard is imperative
  (`wouldCycle()/ensureNoCycle()`, the ItemController twins with visited
  set): self, direct-child and any-descendant targets → 422 "Cannot move
  a location into one of its own descendants.".
- `destroy`: one transaction — soft delete + mass re-parent of DIRECT
  children to root (grandchildren keep their parents; trashed children
  skipped by the soft-delete scope); response keeps `{success: 'success'}`
  and additively reports `detached_ids`. Revision: create/update/delete
  bump via `BumpsTeamRevisionObserver`; the children mass-detach fires no
  events (parity with the API-008 item behavior).

Contract examples (field names are the contract):

```
POST api/location
{"name": "Black shelf", "team_id": "<team uuid>", "parent_id": "<garage uuid>"}
→ 201 {"id": "<uuid>", "name": "Black shelf", "team_id": "<team uuid>",
       "parent_id": "<garage uuid>", "created_at": …, "updated_at": …}

PATCH api/location/{id}          # re-parent / un-parent (Android: plan.md §2)
{"name": "Black shelf", "parent_id": null}
→ 200 {"id": …, "name": "Black shelf", "parent_id": null, …}
{"name": "Garage", "parent_id": "<own descendant uuid>"}
→ 422 {"errors": {"parent_id": ["Cannot move a location into one of its own descendants."]}}

DELETE api/location/{id}
→ 200 {"success": "success", "detached_ids": ["<child uuid>", …]}
```

### Files touched
- `database/migrations/2026_09_20_000001_add_parent_id_to_locations_table.php` (new)
- `app/Models/Location.php`
- `app/Http/Controllers/LocationController.php`
- `tests/Feature/LocationApiTest.php` (6 new tests)
- `server.md` ("Implemented 2026-09" — API-034 note)
- `tickets/API-034-sub-locations.md` (this report)

### Tests run
```
vendor/bin/phpunit --filter "LocationApiTest|RevisionApiTest"  → OK (27 tests, 139 assertions)
vendor/bin/phpunit                                             → OK (417 tests, 2345 assertions; 4 pre-existing skips)
DB_CONNECTION=sqlite DB_DATABASE=/tmp/… php artisan migrate:fresh → OK — full chain incl. the new migration
DB_CONNECTION=sqlite DB_DATABASE=/tmp/… php artisan migrate       → "Nothing to migrate." (success)
php artisan migrate:fresh (dev MySQL 127.0.0.1:3309)           → connection refused (MySQL stopped — see Blockers)
```

### Commits
- `74751e0 API-034(feature): sub-locations — nullable parent_id on locations (Garage > Black shelf)`

### Notes for reviewer
- PATCH `parent_id` semantics: the validator's `nullable` rule keeps a
  present-with-null key in the validated data → `update()` writes it
  (un-parent); an absent key is absent from validated data → hierarchy
  untouched. A no-op PATCH (same name, parent already null) is not dirty →
  no event → no revision bump (consistent with the item noop rule).
- The cycle guard lives OUTSIDE the validator (needs the moved row's id),
  exactly like the item endpoints.
- First test run caught a test-side bug only: moving `a` under its direct
  child `b` was mislabeled "plain re-parent" — the guard correctly 422s
  it; the test now moves `a` under an unrelated root.
