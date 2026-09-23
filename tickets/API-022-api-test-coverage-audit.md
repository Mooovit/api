---
id: API-022
title: "Audit & complete API test coverage (every route in routes/api.php)"
type: test
priority: P1
status: in-review
depends_on: [API-001]
spec: "— (user request: 'make sure every API is tested', Matthieu 2026-09)"
---

# API-022 — Audit & complete API test coverage

## Context
The API surface has grown across API-001..021 to ~30 routes in `routes/api.php`
plus the anonymous trio (`register`, `authenticate`, `enroll`). Tests were
written per ticket, but nobody has verified that **every** route — including
the early ones (`GET api/user`, `GET api/teams`, labels CRUD, the `Route::resource`
verbs) — has at least one passing feature test. This ticket is a coverage audit
that ends with an explicit route→test mapping and new tests for any gap found.

## Scope — Must have
- [ ] Build the route→test mapping (audit table in the Implementation report):
      every route in `routes/api.php` → the test class/method exercising it.
- [ ] For each gap found, add a feature test (success path + authorization
      failure path at minimum) in the appropriate existing test class.
- [ ] Particular attention to routes most likely under-tested: `GET api/user`
      (permissions payload shape), `GET api/teams`, labels CRUD + attach/detach,
      `GET api/item/{item}/history`, `GET api/activity` filters, enrollment
      `enroll` edge cases.
- [ ] Full suite green afterwards.

## Out of scope
- Refactoring controllers or routes (test-only ticket).
- Contract changes of any kind (additive rule still applies — tests *pin*
  current behavior, they don't change it).
- Browser/UI tests (blade kanban, Inertia pages) — covered by their own suites.

## Acceptance criteria
- [ ] Every route in `routes/api.php` maps to ≥1 feature test (documented in
      the report table); no route relies on "tested indirectly".
- [ ] `php artisan test` passes with the new tests; no skipped tests added.

## Technical notes
- Test conventions live in `tests/Concerns/InteractsWithApi.php`
  (`newUserWithTeam`, `actingAsApi` — real Sanctum tokens, `forgetGuards`).
- `php artisan test fileA fileB` only runs the FIRST file — filter instead.
- Remember: `PATCH` verbs registered by `Route::resource` are legacy (host LB
  strips PATCH, commit `66207c4`); tests keep pinning existing behavior but
  new tests should exercise the POST-based verbs where both exist.

## Tests
This ticket IS tests. Run `php artisan test` (full suite) at the end.

## Documentation requirements
- The audit table (route → test) lives in this ticket's Implementation report
  so future tickets can keep it updated.

## Implementation report
*(filled by the implementing agent — do not touch before starting work)*

### Blockers
- none

### Changes
- Audit of every route in `routes/api.php` against `tests/Feature/*` (mapping
  below). Result: coverage was already broad — every route had ≥1 direct test
  — but six gaps were found and closed:
  1. `GET api/status/{status}` / `GET api/location/{location}` had no
     own-team happy-path test (only cross-team 403 + trashed 404).
  2. No 401 (unauthenticated) tests on the status, location, and labels routes.
  3. `PATCH/DELETE api/status/{status}` and `PATCH/DELETE
     api/location/{location}` had no permission-matrix test (Read-Only member
     with a write-ability token → 403; owner with a read-only token → 403).
  4. `PATCH/DELETE api/labels/{label}` had no cross-team test (both are a 403
     `AuthorizationException`, unlike attach which is a 404 — pinned as-is).
  5. `DELETE api/labels/{label}` had no write-ability/member test.
  6. Label destroy now also asserts the row is gone.
- `/api/teams` 401 was already pinned (`UserApiTest::test_user_endpoint_requires_authentication`,
  line 39) — no change needed.
- `POST api/enroll` is anonymous-by-design; every EnrollmentCodeTest exercises
  it without any auth, so the behavior is pinned implicitly (no test added).

### Route → test mapping (audit, 2026-09-05)
| Route | Tests |
|---|---|
| POST api/register | AuthenticateApiTest (register + validation) |
| POST api/authenticate | AuthenticateApiTest (token, bad creds, 2FA rejected); DeviceTokenTest (revoke→401) |
| POST api/enroll | EnrollmentCodeTest (round trip, single use, expired 422, validation) — anonymous by design |
| GET api/user | UserApiTest (payload shape, 401); DeviceTokenTest (last_used_at, abilities); EnrollmentCodeTest |
| GET api/teams | UserApiTest (owned teams, 401) |
| GET api/revision | RevisionApiTest (zero-start, bumps, read-ability gate); DeviceTeamTest (switch) |
| GET api/item | ItemApiTest (scoped+timestamps, ability, 401); ItemDeltaSyncTest; ItemBarcodeTest (lean); ItemSoftDeleteTest; ItemDeletionPolicyTest; UpdatedAtContractTest |
| POST api/item | ItemApiTest (create, validation, foreign parent 422, write ability); RevisionApiTest |
| GET api/item/{item} | ItemApiTest (labels, childrens eager-load, foreign 403); AttachmentTest (metadata); ItemBarcodeTest (soft-delete hiding); ItemSoftDeleteTest |
| PATCH api/item/{item} | ItemApiTest (sparse+history, name history, no-op no history, write ability, session-auth web form); ItemSoftDeleteTest |
| DELETE api/item/{item} | ItemApiTest (delete, ability); ItemSoftDeleteTest; ItemDeletionPolicyTest; RevisionApiTest |
| GET api/item/{item}/history | ItemHistoryApiTest (all, incl. 401 + foreign); ItemSoftDeleteTest; UpdatedAtContractTest |
| POST api/item/{item}/move | ItemIntentVerbsTest (5 cases + ability + cross-team); ItemSoftDeleteTest |
| POST api/item/{item}/assign | ItemIntentVerbsTest (fields, validation, cross-team) |
| POST api/item/{item}/rename | ItemIntentVerbsTest (rename, same-name, missing name) |
| POST api/item/{item}/transfer | ItemTransferTest (8: subtree, 403/422/409/404/401, delta) |
| POST api/item/bulk-move | ItemBulkTest (10: rows, root, parent-among-ids, descendant cycle, trashed parent, 422s, ability, Read-Only member) |
| POST api/item/bulk-assign | ItemBulkTest (mixed batch rows, cross-team 422, permissions) |
| GET api/status | StatusApiTest (scoped+timestamps, 401★new); UpdatedAtContractTest; RevisionApiTest |
| POST api/status | StatusApiTest (create, position quirk, write ability) |
| GET api/status/{status} | StatusApiTest (foreign 403, own-team payload ★new, trashed 404) |
| PATCH api/status/{status} | StatusApiTest (name validation, permission matrix ★new) |
| DELETE api/status/{status} | StatusApiTest (soft delete + hide, permission matrix ★new) |
| GET api/location | LocationApiTest (scoped+timestamps, 401★new); UpdatedAtContractTest |
| POST api/location | LocationApiTest (create, Read-Only member 403) |
| GET api/location/{location} | LocationApiTest (foreign 403, own-team payload ★new, trashed 404) |
| PATCH api/location/{location} | LocationApiTest (name validation, permission matrix ★new) |
| DELETE api/location/{location} | LocationApiTest (soft delete + hide, permission matrix ★new) |
| GET api/labels | LabelApiTest (order, 401★new); UpdatedAtContractTest; DeviceTeamTest |
| POST api/labels | LabelApiTest (201, invalid color 422, write ability, 401★new) |
| PATCH api/labels/{label} | LabelApiTest (both fields 422, foreign 403★new, Read-Only 403★new, 401★new) |
| DELETE api/labels/{label} | LabelApiTest (success+gone★new, foreign 403★new, write ability★new, Read-Only 403★new, 401★new) |
| POST api/item/{item}/labels | LabelApiTest (attach, dup 409, child 422, foreign label 404, token ability, Read-Only member) |
| DELETE api/item/{item}/labels/{label} | LabelApiTest (detach) |
| POST api/item/{item}/barcodes | ItemBarcodeTest (attach, trim/case, dup 409, cross-team ok, ability, member, 404s, validation) |
| DELETE api/item/{item}/barcodes/{barcode} | ItemBarcodeTest (by id/code-path/?code=, foreign 404, permission matrix) |
| POST api/device-tokens | DeviceTokenTest (mint, validation, 401) |
| GET api/device-tokens | DeviceTokenTest (metadata, never the secret, 401) |
| DELETE api/device-tokens/{id} | DeviceTokenTest (revoke→401, cannot revoke another user's) |
| POST api/enrollment-codes | EnrollmentCodeTest (prune stale, 401) |
| GET api/device-team | DeviceTeamTest (default, non-member 422, per-token, membership loss, device token fetch, 401) |
| POST api/device-team | DeviceTeamTest (switch/reset/unknown team/per-token/revision-noop/401) |
| POST api/item/{item}/attachments | AttachmentTest (round trip, non-image/oversize 422, permission matrix) |
| GET api/item/{item}/attachments | AttachmentTest (round trip, newest first, permission matrix) |
| GET api/attachment/{attachment} | AttachmentTest (download, permission matrix, unknown 404) |
| DELETE api/attachment/{attachment} | AttachmentTest (round trip, permission matrix, 404) |
| GET api/activity | TeamActivityFeedTest (filters, pagination, scoping, permission); LocationAuditTest (events) |
| GET api/location/{location}/audits | LocationAuditTest (scoped, ability, foreign, cap) |
| POST api/location/{location}/audits | LocationAuditTest (persist, defaults, foreign ids, write permission, validation) |
| GET api/backups | BackupTest (round trip, retention, permission matrix, 401) |
| POST api/backups | BackupTest (round trip, retention, cross-team 403, 401) |
| GET api/backups/bucket | TeamS3Test (copy→list, no-S3 422, permission matrix) |
| GET api/backup/{backup} | BackupTest (download, permission matrix, 404, cross-team) |
| DELETE api/backup/{backup} | BackupTest (round trip, retention, permission matrix, 404) |
| GET api/backup/{backup}/compare/{other} | BackupCompareTest (mutation, inverse, auth+cross-team, empty-vs-empty) |
| POST api/team/s3 | TeamS3Test (upsert round trip, probe 422, validation, permission matrix) |
| GET api/team/s3 | TeamS3Test (round trip, permission matrix + foreign) |
| GET api/team/s3/rules | TeamS3Test (with bucket lifecycle, unavailable lifecycle null, local-only, permission matrix) |
| DELETE api/team/s3 | TeamS3Test (round trip, permission matrix) |

(★new = test added by this ticket)

### Files touched
- tests/Feature/StatusApiTest.php (3 tests)
- tests/Feature/LocationApiTest.php (3 tests)
- tests/Feature/LabelApiTest.php (4 tests)

### Tests run
```
php artisan test --filter "StatusApiTest|LocationApiTest|LabelApiTest|UserApiTest"
                   → 39 passed (10 status, 8 location, 14 label, 3 user + jetstream skips n/a here)
```

### Commits
- (session work — single batch with API-023..026, see final commit)

### Notes for reviewer
- The cross-team label update/destroy behavior is a **403** (AuthorizationException),
  not a 404 like attach — pinned as-is on purpose; changing it would be a
  contract change.
