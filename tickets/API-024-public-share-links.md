---
id: API-024
title: "Public share links for boxes (magic-link token, share/deactivate API, read-only public page)"
type: feature
priority: P1
status: in-review
depends_on: [API-001, API-005, API-011]
spec: "— (user request: public page token / magic link for box details, Matthieu 2026-09)"
---

# API-024 — Public share links for boxes

## Context
Users want to let someone with **no account** see what's inside a box — e.g. a
QR sticker on the box that anyone can scan. This ticket adds per-item "public
share links": a random capability token (**not** the item uuid — the uuid must
not leak), an API to activate/deactivate the link, and an unauthenticated,
read-only public page rendering the box's contents. The link is revocable at
any time via the API and from the kanban UI (UI part = API-025).

## Scope — Must have
- [ ] Migration: `item_share_links` — uuid PK, `item_id` FK cascade,
      `team_id` indexed, `token` string(64) **unique index**, `activated_at`
      / `deactivated_at` timestamps (nullable), `timestamps`. One active link
      per item enforced in code (activating re-issues and deactivates the
      previous token, or returns the existing active link — pick one, pin it).
- [ ] Model `ItemShareLink` (+ `Item::shareLink()` helper returning the active
      link or null).
- [ ] API (auth:sanctum, `item:write` + resource-team authorization, POST per
      the no-PATCH rule):
      - `POST api/item/{item}/share` → activates (or re-issues) the link,
        returns `{share_url, token, activated_at}` (201 on first activation).
      - `DELETE api/item/{item}/share` → deactivates (sets `deactivated_at`,
        token becomes unusable). Response `{success}`.
      - `GET api/item/{item}/share` → current link state
        `{share_url, token, activated_at, deactivated_at}` or `{share_url: null}`.
- [ ] Public page: `GET /share/{token}` — **no auth**, blade view (standalone,
      like kanban), read-only: box name, its status/location names, and the
      contained items (name + status/location). Unknown, deactivated, or
      trashed-box token → 404 page. No owner/team/email/user data leaks.
- [ ] Team revision bump on activate/deactivate (so kanban live view notices).
- [ ] Feature tests + public-page tests.

## Out of scope
- Kanban UI for the share link + QR rendering (API-025).
- Password-protected or expiry-dated links; per-user analytics.
- Sharing non-box items is allowed by the same endpoint (any item works — the
  UI just calls it "box"); no separate route.
- Public *write* access of any kind — the page is strictly read-only.

## Acceptance criteria
- [ ] Token is random (≥32 chars, `Str::random`), NOT derivable from the item
      uuid; uuid never appears on the public page.
- [ ] Logged-out `GET /share/{token}` → 200 page with box name + contained
      items; deactivated link → 404; re-activating the box's link invalidates
      the previous token (old URL 404s).
- [ ] Soft-deleted box → public page 404s (contents of trashed boxes are not
      served), without leaking *that* the token existed.
- [ ] API: activate without `item:write` (read-only token or `Read Only`
      member) → 403; foreign-team item → 403/404; deactivate works; GET shows
      state; activate/deactivate each bump the team revision exactly once.
- [ ] Items listing/delta payloads unchanged (additive rule: no existing
      response shape touched).

## Technical notes
- `share_url` = `url('/share/'.$token)` — build server-side so the public path
  stays a single source of truth.
- Deactivate = soft state change (`deactivated_at` set, row kept) so a later
  activate can issue a FRESH token (never resurrect the old one — a scanned
  sticker that was revoked must stay dead).
- Public controller team-scoping: load the item through the link's relation;
  eager-load `childrens.status,childrens.location` (see kanban `boardRow` for
  the naming) and render name-only. Escape everything (blade `{{ }}`).
- Routes: the API pair goes in the existing `auth:sanctum` group next to the
  barcode registry routes; the public route goes in `routes/web.php` OUTSIDE
  any auth middleware, BEFORE the kanban `{type}` group (fixed path).
- Mirror the barcode-registry authorization style
  (`ItemBarcodeController::attach`) for consistency.

## Tests
- `tests/Feature/ItemShareLinkTest.php`: activate → payload shape, token
  length/randomness ≠ uuid, idempotent-or-reissue pinned; deactivate → 404
  public page, token unusable; re-activate → new token ≠ old; 403 matrix
  (read-only token, Read-Only member, foreign item); 404 unknown item;
  revision bumps exactly once per mutation; trashed box → public 404;
  uuid not in public page HTML; public page lists contained items' names.
- Public page: `tests/Feature/PublicSharePageTest.php` (or merged into the
  same class) — guest request (no actingAs), contents + no-leak assertions.

Run: `php artisan test --filter ItemShareLinkTest`, then full suite.

## Documentation requirements
- PHPDoc on the new controller + model; `server.md` idea entry marked
  implemented (public share links); request/response examples in server.md.

## Implementation report
*(filled by the implementing agent — do not touch before starting work)*

### Blockers
- none

### Changes
- Migration `item_share_links`: uuid PK, `item_id` uuid UNIQUE + FK cascade
  (one row per item — pinned semantics: activate while active = idempotent 200
  same token; re-activating a REVOKED link issues a FRESH token, old URL stays
  dead forever), `team_id` indexed, `token` string(64) unique, nullable
  `activated_at`/`deactivated_at`, timestamps.
- Model `ItemShareLink`: Uuids, datetime casts, `isActive()`, `scopeActive`
  (whereNull deactivated_at), `freshToken()` = `Str::random(64)`.
- `Item::shareLink()` hasOne (returns the row regardless of state; controller
  decides what to expose).
- API `ItemShareController` (auth:sanctum):
  - POST `api/item/{item}/share` — item:write + resource-team; 201 first
    activation, 200 idempotent/re-issue; bumps revision on create/re-issue.
  - GET `api/item/{item}/share` — item:read; no row → all-null shape.
  - DELETE `api/item/{item}/share` — item:write; stamps `deactivated_at`;
    idempotent; bumps revision only when a link was actually revoked.
  - Payload never exposes the token/url of a revoked link (both null).
- Public `PublicShareController` + `resources/views/public/box.blade.php`
  (standalone Tailwind CDN page, no app assets): looks up ONLY active links;
  resolves the item through `$link->item` so the soft-delete global scope
  (API-005) 404s trashed boxes; renders name + status/location names of the
  box and its `childrens` — no ids, no team/owner data; everything escaped.
- Routes: api.php trio inside the auth:sanctum group next to the barcode
  registry; web.php `GET /share/{token}` unauthenticated, before the authed
  groups.
- Revision semantics pinned by test: activate +1, idempotent re-activate +0,
  revoke +1, second revoke +0 (never touches `items.updated_at` — not asserted
  against item body since pivot rule already documented; the write goes through
  the link row only).

### Files touched
- database/migrations/2026_09_05_000015_create_item_share_links_table.php (new)
- app/Models/ItemShareLink.php (new)
- app/Models/Item.php (shareLink() relation)
- app/Http/Controllers/ItemShareController.php (new)
- app/Http/Controllers/PublicShareController.php (new)
- resources/views/public/box.blade.php (new)
- routes/api.php, routes/web.php
- tests/Feature/ItemShareLinkTest.php (new, 12 tests)

### Tests run
```
php artisan test --filter ItemShareLinkTest → 12 passed (0.46s)
```
Covers: token ≥32 chars ≠ uuid; idempotent activate; empty/show state shapes;
revoke → public 404 + nulls + idempotent; re-activate → fresh token, old URL
dead; public page renders box/child/status/location names and NOT uuids,
team id, owner name/email; unknown token 404; trashed box 404; 401s; 403
matrix (read-ability token, Read-Only member, foreign item); revision bumps.
Dev `php artisan migrate` blocked by local MySQL being down (Connection
refused) — migration is exercised by RefreshDatabase on SQLite; run
`php artisan migrate` on deploy.

### Commits
- (pending, batch commit at the end of the feature set)

### Notes for reviewer
- Pinned decision: ONE row per item (unique item_id). "Re-activate" of a
  revoked link mints a NEW token rather than resurrecting the old one — a
  revoked sticker URL must stay dead.
- Foreign-item POST is 403 (AuthorizationException) via the barcode-style
  matrix — consistent with the barcode registry (not 404).
- The public page deliberately renders only names: even child ids are omitted,
  so a shared link leaks the least possible content.
