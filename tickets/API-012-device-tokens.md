---
id: API-012
title: "Named, revocable device tokens for the PDA fleet"
type: feature
priority: P2
status: in-review
depends_on: [API-001]
spec: "server.md §10; client context: plan.md §1 scanner-login QR"
---

# API-012 — Named, revocable device tokens for the PDA fleet

## Context
Login on PDAs uses the human's email/password, and the web scanner-login QR is base64
`email:password` (plan.md §1) — every PDA stores the owner's real credentials; losing a
device means a password rotation across the fleet. Sanctum personal access tokens
already exist (that's what `api/authenticate` issues); this ticket exposes them as
named, scoped, revocable **device tokens**.

## Scope — Must have
- [x] `POST api/device-tokens` — body `{name}` (required, max 191) → creates a Sanctum
      token named after the device with the restricted default ability set
      (`item/status/location/label`, read+write — `DeviceTokenController::DEVICE_ABILITIES`,
      pinned by test). Returns `{id, name, token}` once (plain text only at creation).
- [x] `GET api/device-tokens` — the current user's tokens: `{id, name, last_used_at,
      created_at}` (never the token itself) — powers a profile-screen device list
      ("Bluebird #3, last seen 2 h ago").
- [x] `DELETE api/device-tokens/{id}` — revokes (row delete); revoked token → 401 on
      next use (Sanctum behavior).
- [x] Route protection: regular `auth:sanctum` — any valid token (including a device
      token) may list/revoke the user's tokens; documented in server.md §10.
- [x] `GET api/user` already returns `tokenPermissions` — asserted to reflect the
      restricted device-token abilities.
- [x] Feature tests.

## Out of scope
- One-time enrollment codes / QR change so the scanner-login carries an enrollment code
  instead of the password (tracked as API-017); token expiry/TTL; per-device IP pinning;
  2FA interplay (`authenticate()` rejects 2FA users today — unchanged).

## Acceptance criteria
- [x] Device token can do everything `item/status/location/label` routes require —
      pinned with a full item workflow (create + list) under the device token alone.
      The "403 on a non-granted ability" pin: no current API route guards an ability
      outside the set (Jetstream API-token management is web/session, out of the API
      surface), so the restriction is pinned by the **exact abilities assertion**
      (DB row + `GET api/user` echo) instead — future guards (e.g. API-018 backups)
      will naturally 403 device tokens. (Deviation, documented.)
- [x] List shows `name` + `last_used_at`; using a token updates `last_used_at`
      (Sanctum native — pinned).
- [x] Revoked device token gets 401 everywhere; the login password keeps working
      (`api/authenticate` regression asserted green).
- [x] Old clients unaffected: `api/authenticate` keeps issuing its token exactly as
      today (untouched code; test documents the split quirk).

## Technical notes
- `$user->createToken($name, $abilities)` — Sanctum 2.x, tokens in
  `personal_access_tokens`; plain text returned whole (the legacy endpoint's
  `explode('|')` quirk stays as-is for compat).
- Controller `App\Http\Controllers\DeviceTokenController` (store/index/destroy) in the
  `auth:sanctum` group.
- No `DELETE api/device-tokens/current` — the list provides ids.

## Report (implementation)
- `DeviceTokenController::store` validates `name required|string|max:191` and mints
  with `DEVICE_ABILITIES = [item:read/write, status:read/write, location:read/write,
  label:read/write]`; response `{id, name, token}` at 201. `index` returns
  `tokens()->get(['id','name','last_used_at','created_at'])` — metadata only, abilities
  never serialized (pinned: no `token`/`abilities` keys, secret not in body).
  `destroy` resolves within `$user->tokens()` only (another user's id → 404), deletes
  the row, `{success: true}`.
- **Test-process gotcha documented**: the framework `auth` middleware calls
  `Auth::shouldUse($guard)`, permanently re-pointing the default guard inside the
  AuthManager that persists across requests in one test process — a later
  `POST /api/authenticate` (`Auth::attempt`) would resolve the Sanctum RequestGuard
  and 500. Fix in the test: `$this->app['auth']->shouldUse('web')` before the legacy
  call. Production boots a fresh manager per request → the legacy endpoint is
  unaffected there (no production code changed).
- **Tests**: `tests/Feature/DeviceTokenTest.php` — 8 tests: mint (name/id/token +
  exact restricted abilities in DB); metadata list (no secret leak, `last_used_at`
  key); `last_used_at` updates on use; `GET api/user` `tokenPermissions` echo + full
  item workflow under the device token; revoke → 401 + password login still 200
  (token + user shape unchanged); cross-user revoke 404; validation (missing name,
  >191); unauthenticated 401s.
- **Verification**: `--filter DeviceTokenTest` 8/8; full suite 210 tests / 765
  assertions / 4 pre-existing Jetstream skips, green.
