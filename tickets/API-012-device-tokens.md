---
id: API-012
title: "Named, revocable device tokens for the PDA fleet"
type: feature
priority: P2
status: ready
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
- [ ] `POST api/device-tokens` — body `{name}` (required, max 191) → creates a Sanctum
      token named after the device with a **restricted default ability set**:
      `["item:read", "item:write", "status:read", "status:write", "location:read",
      "location:write", "label:read", "label:write"]` — i.e. items/team read-write, no
      account/settings abilities. Returns `{id, name, token}` once (plain text only at
      creation).
- [ ] `GET api/device-tokens` — the current user's tokens: `{id, name, last_used_at,
      created_at}` (never the token itself) — powers a profile-screen device list
      ("Bluebird #3, last seen 2 h ago").
- [ ] `DELETE api/device-tokens/{id}` — revokes (row delete); revoked token → 401 on
      next use (Sanctum behavior).
- [ ] Route protection: regular `auth:sanctum` — any valid token (including a device
      token) may list/revoke the user's tokens; document that a device token can
      mint/revoke sibling device tokens (acceptable for a single-warehouse team; note
      it in server.md).
- [ ] `GET api/user` already returns `tokenPermissions` — assert it reflects the
      restricted device-token abilities so the Android session can render capabilities.
- [ ] Feature tests.

## Out of scope
- One-time enrollment codes / QR change so the scanner-login carries an enrollment code
  instead of the password (client + web change, server.md §10 optional later); token
  expiry/TTL; per-device IP pinning; 2FA interplay (`authenticate()` rejects 2FA users
  today — unchanged).

## Acceptance criteria
- [ ] Device token can do everything `item/status/location/label` routes require and
      fails `tokenCan` checks for anything else (pin with a 403 test on a route guarded
      by an ability outside the set, e.g. Jetstream API-token management).
- [ ] List shows `name` + `last_used_at`; using a token updates `last_used_at`
      (Sanctum does this natively — pin it).
- [ ] Revoked device token gets 401 everywhere; the login password keeps working.
- [ ] Old clients unaffected: `api/authenticate` keeps issuing its token exactly as
      today.

## Technical notes
- `$user->createToken($name, $abilities)` — Sanctum 2.x (`composer.json`), tokens live
  in `personal_access_tokens` (migration already present).
- Routes: `routes/api.php`, `auth:sanctum` group; controller
  `App\Http\Controllers\DeviceTokenController` (or closure-based like `/user` —
  controller preferred, it's ~3 methods).
- `currentAccessToken()` id for self-revocation convenience:
  `DELETE api/device-tokens/current`? — skip; keep id-based, the list provides ids.
- Never return the `id|token` prefix split trick from `UserController@authenticate` —
  return `plainTextToken` whole; note the existing endpoint's split quirk stays as-is
  (compat).

## Tests
`vendor/bin/phpunit --filter DeviceTokenTest`:
- create (abilities restricted) / list (no secret leak) / revoke (401 after);
- `last_used_at` updates; ability matrix vs item routes (works) and a non-granted
  ability (403);
- old `api/authenticate` behavior unchanged (regression from API-001 suite still green).

## Documentation requirements
- PHPDoc on the controller; `server.md` §10 mark implemented + ability-set table;
  plan.md §2 contract notes; flag the scanner-QR follow-up for the client repo.
