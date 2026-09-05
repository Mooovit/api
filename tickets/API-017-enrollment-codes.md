---
id: API-017
title: "Device enrollment codes (scan to enroll a new PDA)"
type: feature
priority: P1
status: ready
depends_on: [API-012]
spec: "server.md §10 follow-up + user request (Matthieu, 2026-09): scanner-login QR carries a one-time enrollment code instead of the password"
---

# API-017 — Device enrollment codes (scan to enroll a new PDA)

## Context
API-012 removed nothing from the login path: a new PDA still needs the owner's
real email/password once to mint its device token, and the web's scanner-login
QR is base64 `email:password` (plan.md §1). This ticket closes that hole: an
already-authenticated device/user mints a **short-lived, single-use enrollment
code**; the new PDA scans it and exchanges it for a device token — the
password never touches the new device.

## Scope — Must have
- [ ] Migration: `enrollment_codes` table — uuid PK, `code` (string, unique,
      indexed), `user_id` (issuer), `expires_at`, `used_at` (nullable),
      `used_by_token_id` (nullable), `timestamps`.
- [ ] `POST api/enrollment-codes` (authenticated, any valid token) — mints a
      code for the calling user: random unguessable code (e.g. 8+ chars
      unambiguous alphabet), `expires_at` = now + 15 minutes. Returns
      `{code, expires_at}` 201.
- [ ] `POST api/enroll` — **unauthenticated** `{code, name}`:
  - code must exist, be unused and unexpired (422 with `code` error otherwise;
    410 Gone for a used code is acceptable — pick one, pin it);
  - mints a Sanctum token named after `name` with exactly
    `DeviceTokenController::DEVICE_ABILITIES` (same restriction as API-012);
  - marks the code used (`used_at` + `used_by_token_id`) atomically — two
    concurrent redeemers must not both succeed (single-use enforced at the DB
    level or via `whereNull('used_at')` update guard);
  - returns the same shape as `POST api/device-tokens`
    (`{id, name, token}` 201).
- [ ] Cleanup hook: expired/used codes are pruned when a new one is minted
      (cheap `delete()` for the issuer's stale rows — no scheduler needed).
- [ ] Feature tests.

## Out of scope
- Team-binding of codes (the code inherits the issuer user — whoever holds it
  gets that user's permissions, same trust level as API-012's sibling rule);
  multi-use or long-lived codes; revocation list UI (codes expire in minutes);
  changing the legacy `api/authenticate` or the web QR format (client
  follow-up in the MV repo).

## Acceptance criteria
- [ ] Round-trip: authenticated mint → unauthenticated enroll → the new token
      works on item routes (read + write) and is listed by
      `GET api/device-tokens` with the chosen name.
- [ ] Single-use: second enroll with the same code fails (410/422 — pinned),
      and the first token still works.
- [ ] Expiry: an expired code fails; `expires_at` is respected from the mint
      response.
- [ ] Enrolled token abilities are exactly DEVICE_ABILITIES (pinned via
      `ApiTokenPermissions`-style assertion or `/api/user` echo).
- [ ] Validation: unknown code, blank/short `name`, malformed code → 422;
      unauthenticated `POST api/enrollment-codes` → 401.
- [ ] Additive-only: whole suite green.

## Technical notes
- Randomness: `Str::random()` over an unambiguous alphabet (no `0/O/1/I`) —
  the code is typed by hand when scanning fails; keep it short but ≥ 8 chars.
- Race safety: redeem via
  `where('code', $code)->whereNull('used_at')->where('expires_at', '>', now())->update(...)`
  and check affected rows, or a unique partial index — pick one and pin the
  concurrency behavior in a test if cheap.
- The enroll route must be **outside** `auth:sanctum` in `routes/api.php`
  (like `register`/`authenticate`).

## Tests
- New `tests/Feature/EnrollmentCodeTest.php`: mint+enroll round-trip
  (abilities, name, listed as device), single-use rejection, expiry, name
  validation, unauthenticated mint 401, token usable on item routes, stale-code
  pruning on mint.
