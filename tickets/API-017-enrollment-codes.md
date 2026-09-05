---
id: API-017
title: "Device enrollment codes (scan to enroll a new PDA)"
type: feature
priority: P1
status: in-review
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
- [x] Migration: `enrollment_codes` table — uuid PK, `code` (string, unique,
      indexed), `user_id` (issuer), `expires_at`, `used_at` (nullable),
      `used_by_token_id` (nullable), `timestamps`.
- [x] `POST api/enrollment-codes` (authenticated, any valid token) — mints a
      code for the calling user: random unguessable code (e.g. 8+ chars
      unambiguous alphabet), `expires_at` = now + 15 minutes. Returns
      `{code, expires_at}` 201.
- [x] `POST api/enroll` — **unauthenticated** `{code, name}`:
  - code must exist, be unused and unexpired (422 with `code` error otherwise;
    410 Gone for a used code is acceptable — pick one, pin it);
  - mints a Sanctum token named after `name` with exactly
    `DeviceTokenController::DEVICE_ABILITIES` (same restriction as API-012);
  - marks the code used (`used_at` + `used_by_token_id`) atomically — two
    concurrent redeemers must not both succeed (single-use enforced at the DB
    level or via `whereNull('used_at')` update guard);
  - returns the same shape as `POST api/device-tokens`
    (`{id, name, token}` 201).
- [x] Cleanup hook: expired/used codes are pruned when a new one is minted
      (cheap `delete()` for the issuer's stale rows — no scheduler needed).
- [x] Feature tests.

## Out of scope
- Team-binding of codes (the code inherits the issuer user — whoever holds it
  gets that user's permissions, same trust level as API-012's sibling rule);
  multi-use or long-lived codes; revocation list UI (codes expire in minutes);
  changing the legacy `api/authenticate` or the web QR format (client
  follow-up in the MV repo).

## Acceptance criteria
- [x] Round-trip: authenticated mint → unauthenticated enroll → the new token
      works on item routes (read + write) and is listed by
      `GET api/device-tokens` with the chosen name.
- [x] Single-use: second enroll with the same code fails (410/422 — pinned),
      and the first token still works.
- [x] Expiry: an expired code fails; `expires_at` is respected from the mint
      response.
- [x] Enrolled token abilities are exactly DEVICE_ABILITIES (pinned via
      `ApiTokenPermissions`-style assertion or `/api/user` echo).
- [x] Validation: unknown code, blank/short `name`, malformed code → 422;
      unauthenticated `POST api/enrollment-codes` → 401.
- [x] Additive-only: whole suite green.

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

## Implementation Report

**Status**: done — 6 new tests in `tests/Feature/EnrollmentCodeTest.php`, full
suite 257 tests / 1118 assertions / 4 skips (pre-existing Jetstream skips).

**Production changes**
- `EnrollmentCode` model + `2026_09_05_000009_create_enrollment_codes_table`
  (uuid PK via `Uuids`, unique indexed `code`, `user_id` FK cascadeOnDelete,
  `expires_at`/`used_at` datetime casts, nullable `used_by_token_id`
  unsignedBigInteger — matching Sanctum's `bigIncrements` token ids).
- `EnrollmentCodeController`:
  - `store()` (POST api/enrollment-codes, authed): prunes the ISSUER's stale
    rows (expired **or** used) at mint time — no scheduler — then mints an
    8-char code via `random_int` over the unambiguous alphabet
    `ABCDEFGHJKMNPQRSTUVWXYZ23456789` (no 0/O/1/I/L; hand-typeable fallback)
    with a uniqueness do-while, `expires_at` = now + 15 min → `{code,
    expires_at}` 201.
  - `enroll()` (POST api/enroll, unauthenticated — outside `auth:sanctum`
    next to register/authenticate): `trim` lookup; unknown or expired →
    **422** with a `code` error; already-used → **410 Gone** (pinned). Mints
    the token on the code's ISSUER user with exactly
    `DeviceTokenController::DEVICE_ABILITIES`, then claims the row with a
    guarded conditional update
    (`where('id',…)->whereNull('used_at')->where('expires_at','>',now())->update([used_at, used_by_token_id])`)
    — 0 affected rows means a concurrent redeemer won: the freshly minted
    token is deleted and 410 returned (no orphan token).

**Pinned decisions**
- Used code = 410 Gone; unknown/expired code = 422 (410 is only for a code
  that verifiably existed and was consumed).
- Codes are bound to the issuer USER, not a team — the enrolled token moves
  with the issuer's own team switching (API-015's `effectiveTeam` applies on
  use, exactly like an API-012 sibling token).
- Race pin: the sequential second-redeem test hits the early `used_at` check;
  the conditional-update guard covers true concurrency by construction (a
  genuine parallel-redeemer test is not cheap and was skipped, per the
  ticket's "if cheap" wording).

**Test findings worth keeping**
- The expiry test uses `$this->travelTo($expiresAt->copy()->addSecond())` — one
  second
  past the expiry parsed from the MINT response — pinning that the returned
  `expires_at` is the authoritative deadline; `Carbon::setTestNow()` is reset
  explicitly at the end (the static test-now persists across tests in one
  process otherwise).
- Unauthenticated calls after authenticated ones in the same test need the
  now-standard `$this->app['auth']->forgetGuards()` + `$this->flushHeaders()`
  (RequestGuard memoization — see API-016 report).

**Deviations from spec**: none.
