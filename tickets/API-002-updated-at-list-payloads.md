---
id: API-002
title: "Verify & pin updated_at on every list payload"
type: chore
priority: P0
status: ready
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
- [ ] Verify against a deployed instance (`GET api/item` response actually contains
      `updated_at`) — if the deployment runs older code, note it in the implementation
      report; the fix is "deploy", not code.
- [ ] Feature tests (extend API-001 suites) asserting every row of every list payload
      carries `created_at` and `updated_at` in ISO-8601 parseable format:
      `GET api/item`, `GET api/status`, `GET api/location`, `GET api/labels`.
- [ ] Assert `GET api/item/:id` and `GET api/item/:id/history` timestamps too
      (single-item + audit trail are what MV-014 compares verbatim).
- [ ] Update `server.md` §1: mark verified/done, record the conclusion (field was already
      present; tests now pin it) so the Android side can close MV-047/MV-044 follow-ups.

## Out of scope
- Any serializer/filter change (fields are already sent; adding an API Resource layer is a
  larger refactor nobody asked for).
- Pagination or partial responses on lists (server.md §2 territory, API-006).

## Acceptance criteria
- [ ] All four list endpoints have a green test asserting `updated_at` presence + format.
- [ ] If a payload is ever missing the field, the suite fails loudly.
- [ ] `server.md` §1 updated with the finding.

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
