---
id: API-040
title: "Audit reports carry missing_ids — the dashboard lists what wasn't found"
type: feature
priority: P2
status: in-review
depends_on: [API-010]
spec: "server.md §7 (audit acceptance); client side MV-167 (Android repo tickets); web page shipped 2026-10-18"
---

# API-040 — missing_ids in the audit payload

## Context

API-010 stores the MV-050 audit report as
`{found_ids, extra, unknown_codes, missing_count}` — `missing_count` is a
**declared number** because the expected set lives only in the device's
local cache, so the server can never itemize what wasn't found. The new
web audit page (`GET /teams/{team}/audits`, 2026-10-18) shows "Missing
(N) — count declared by the device — the expected item list is not
stored". The client side (Android ticket MV-167) will start sending the
missing list; this ticket makes the server accept, store, validate and
display it.

## Scope — Must have

- [ ] `POST api/location/{location}/audits` accepts an optional
      `missing_ids: string[]` in the report body (additive — old clients
      that omit it keep working; `missing_count` stays accepted and
      unchanged).
- [ ] Same snapshot validation as found/extra: every `missing_ids` entry
      must be a team item (`withTrashed`), mismatch → ValidationException
      (422) — one combined check for found+extra+missing.
- [ ] Stored in `payload.missing_ids` (exact order as posted); counts
      stay as today (`found_count`, `missing_count` from the declared
      input — the server does NOT recompute it from the list; divergent
      declared counts are stored as declared).
- [ ] `GET /teams/{team}/audits` (web, `AuditsController`) resolves
      `missing_ids` to `{id, name, deleted}` rows like found/extra and
      exposes `missing` on each audit shape.
- [ ] `Audits/Index.vue` renders the missing item list in the detail
      drawer (replacing the count-only note; the note stays when an old
      audit has no `missing_ids`), with the deleted-item treatment.
- [ ] The API-009 activity-feed audit summary is unchanged (counts only).

## Out of scope

- Recomputing/repairing `missing_count` from `missing_ids` server-side.
- Migration of existing audits (their `missing_ids` simply stay absent).
- Any change to the audit checklist UI, tokens/abilities, or the
  location history endpoint (`GET api/location/{location}/audits` needs
  no change — clients read the raw payload).

## Acceptance criteria

- [ ] Store with `missing_ids` → 201, `payload.missing_ids` persisted
      verbatim; without it → 201, `payload.missing_ids` absent/[].
- [ ] `missing_ids` containing an item of another team → 422; trashed
      team items validate (snapshot semantics).
- [ ] Old payloads (no `missing_ids`) still pass every existing test;
      the web page renders the count-only note for them.
- [ ] The web page shows resolved missing item names for new audits
      (incl. the deleted flag for rows trashed after the audit).
- [ ] `vendor/bin/phpunit` green; `npm run dev` builds.

## Technical notes

- `AuditController::store` (`app/Http/Controllers/AuditController.php`):
  extend the validation rules and the `referencedIds` merge; payload key
  order mirrors the report (`found_ids, extra, unknown_codes,
  missing_ids`).
- `AuditsController::shape` (`app/Http/Controllers/AuditsController.php`):
  `missing` resolves like found/extra; the page-wide item id collector
  must include `missing_ids`; add `missing_count`/`missing` rendering in
  `resources/js/Pages/Audits/Index.vue`.
- Backward compatibility: `missing_ids` is additive on both the request
  body and the stored payload; nothing renamed.

## Tests

- `AuditApiTest` (or wherever API-010's store tests live): accepts +
  persists `missing_ids`; 422 cross-team; trashed id validates; omitted
  key unchanged.
- `ManagementUiTest`: audits page exposes `audits.data.0.missing`
  resolved names; legacy audit without `missing_ids` renders the note.
- `vendor/bin/phpunit` full suite green.

## Documentation requirements

- PHPDoc on `AuditController::store` and `AuditsController` updated.
- `server.md`: API-010 section notes the additive `missing_ids` key.
- Mapping row in `tickets/README.md`.

## Implementation report
*(filled by the implementing agent — do not touch before starting work)*

### Blockers
- none

### Changes
- `AuditController::store`: `missing_ids` (nullable `string[]`) accepted in
  the report body; merged into the combined team-membership snapshot check
  (found + missing + extra ids, `withTrashed`); stored verbatim in
  `payload.missing_ids` (key after `unknown_codes`); `missing_count` still
  stored as declared — never recomputed. PHPDoc updated (API-040 / MV-167).
- `AuditsController`: the shape exposes `missing` (resolved `{id, name,
  deleted}` rows, same treatment as found/extra); the page-wide item-id
  collector includes `payload.missing_ids`.
- `Audits/Index.vue`: the detail drawer's Missing section renders the
  resolved item list (deleted items struck through + amber "(deleted)");
  falls back to the count-only note ("this audit predates the
  missing-item list") for legacy payloads, and "none" when the count is 0.
- Docs: `server.md` §7 implemented note; mapping row in `tickets/README.md`.

### Files touched
- modified: `app/Http/Controllers/AuditController.php`,
  `app/Http/Controllers/AuditsController.php`,
  `resources/js/Pages/Audits/Index.vue`,
  `tests/Feature/LocationAuditTest.php`, `tests/Feature/ManagementUiTest.php`,
  `server.md`, `tickets/README.md`, `public/js/app.js` + `public/css/app.css`
  (mix build), this ticket
- (page scaffolding itself — route, controller, page, nav — landed earlier
  in this branch as the unticketed 2026-10-18 web-audit-page slice)

### Tests run
```
php artisan test --filter "LocationAuditTest|ManagementUiTest"  → PASS, 34 tests
php artisan test                                                → PASS, 486 tests (4 pre-existing Jetstream skips)
NODE_OPTIONS=--openssl-legacy-provider npm run dev              → Compiled Successfully
```

### Commits
- `ee5ae3f` API-040(feat): audit reports carry missing_ids — dashboard lists
  them (branch `api-040-audit-missing-ids`, pushed)

### Notes for reviewer
- The commit also carries the unticketed 2026-10-18 web-audit-page slice
  (route, page, nav, dashboard link) — same files, inseparable once the
  missing list landed in the same page; flagged here rather than split.
- Contract notes: `missing_ids` is purely additive on the request body and
  the stored payload; `GET api/location/:id/audits` returns the raw payload
  so clients get the list with no endpoint change; the activity-feed
  summary stays count-based (unchanged, pinned by existing tests).
