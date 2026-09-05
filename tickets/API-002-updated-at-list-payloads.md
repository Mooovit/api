---
id: API-002
title: "Verify & pin updated_at on every list payload"
type: chore
priority: P0
status: in-review
depends_on: [API-001]
spec: "server.md §1; client contract: plan.md §2"
---

# API-002 — Verify & pin updated_at on every list payload

## Context
server.md §1 asks for `updated_at` on `GET api/item` because the Android cache cannot
order by recency (MV-047 dashboard, MV-044 CSV export) without it. **Exploration shows the
idea is stale against this codebase**: `ItemController::index()` (ItemController.php:91)
returns full Eloquent models, so `updated_at` (Laravel `timestamps()`, ISO-8601
serialization) is already present — same for `api/status`, `api/location`, `api/labels`.
What is missing is a *guarantee*: nothing pins those fields, and the Android team wrote
server.md because their client observed them missing. This ticket verifies the claim
against the deployed server, and pins the contract with tests so it can never regress.

## Scope — Must have
- [x] Verify against a deployed instance — **not possible from this environment** (no
      deployed URL/credentials available locally); verification was done against HEAD
      via the code path (`index()` returns full Eloquent models) and is now enforced by
      tests. **Flag for the reviewer:** confirm on the production host after deploying
      this commit — if the deployed instance omits the field, it is running older code
      and the fix is "deploy", not code.
- [x] Feature tests (`tests/Feature/UpdatedAtContractTest.php`) asserting every row of
      every list payload carries `created_at` and `updated_at` in ISO-8601 UTC format
      (`Y-m-d\TH:i:s.u\Z`, matching Laravel 8's default serialization that the client
      compares verbatim): `GET api/item`, `GET api/status`, `GET api/location`,
      `GET api/labels`.
- [x] `GET api/item/:id` timestamps asserted; `GET api/item/:id/history` asserts
      `changed_at` (the audit-trail timestamp MV-014 orders by) plus `created_at`/
      `updated_at` on every row.
- [x] `server.md` §1 updated with the finding (verified stale idea; tests now pin it;
      client follow-ups MV-047/MV-044 can rely on it once deployed).

## Out of scope
- Any serializer/filter change (fields are already sent; adding an API Resource layer is a
  larger refactor nobody asked for).
- Pagination or partial responses on lists (server.md §2 territory, API-006).

## Acceptance criteria
- [x] All four list endpoints have a green test asserting `updated_at` presence + format.
- [x] If a payload is ever missing the field, the suite fails loudly
      (`assertIsoTimestamp` fails on null/malformed values with a message naming the field).
- [x] `server.md` §1 updated with the finding.

## Technical notes
- Laravel serializes `updated_at` as `2025-08-20T12:34:56.000000Z` (ISO-8601 UTC) — the
  Android client compares these strings verbatim (MV-014), so the format must not change.
- Do not touch model `$casts`/serialization as part of this ticket.
- If verification on the deployed server shows the field missing there, that is a
  deployment-version mismatch: flag it, do not code around it.

## Tests
`vendor/bin/phpunit --filter UpdatedAt` (new assertions live in the API-001 suites or a
small dedicated `UpdatedAtContractTest`).

## Documentation requirements
- `server.md` §1 status note.
- PHPDoc not needed beyond what API-001 established.

## Implementation report (2026-09-05)

**Result: `vendor/bin/phpunit --filter UpdatedAtContractTest` → PASS (6 tests, 53
assertions); full suite PASS (106 tests, 270 assertions, 4 skipped).**

Files added:
- `tests/Feature/UpdatedAtContractTest.php` — six tests, one per pinned endpoint:
  item/status/location/labels indexes (per-row `created_at` + `updated_at`), item show,
  and item history (`changed_at` + row timestamps). All values are checked against the
  strict regex `^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$` so any change to
  serialization (dropped microseconds, timezone suffix, missing field) fails loudly.

Files changed:
- `server.md` §1 — "Verified (2026-09, API-002)" banner recording that the idea was
  stale, the fields were always present, and the contract is now pinned.

No production code was touched (the ticket's premise: nothing to fix, only pin).

## Reviewer notes
- Deployed-instance verification is the one open item — see the scope note above.
  Everything else is enforced by the suite.
