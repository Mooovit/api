---
id: API-010
title: "Audit/stocktake acceptance endpoint"
type: feature
priority: P2
status: in-review
depends_on: [API-009]
spec: "server.md §7; client context: MV-050/MV-054/MV-057"
---

# API-010 — Audit/stocktake acceptance endpoint

## Context
MV-050 (reworked by MV-054/MV-066/MV-069) produces a found/missing/extra report
*locally* on the PDA; the server never learns an audit happened. A stocktake that isn't
recorded can't be traced, compared, or repeated. This ticket gives audits a server-side
home: a snapshot + result per location audit, exposed as history — and one activity row
in the API-009 feed ("audit of Location X: 42/45 found").

## Scope — Must have
- [x] Migration: `audits` table — uuid PK, `team_id`, `location_id`, `user_id` (FKs,
      cascade on team/location), `found_count`, `missing_count`, `extra_count` (int),
      `payload` (json: `{found_ids: [], extra: [{id, code?}], unknown_codes: []}` — the
      exact MV-050 report structure), `timestamps`.
- [x] `POST api/location/{location}/audits` — body:
      `{found_ids: string[], extra: [{id: string}], unknown_codes: string[]}` (extra and
      unknown optional, default empty). Server validates: location + all referenced ids
      belong to the team; computes the three counts server-side (do not trust client
      counters); stores the snapshot.
- [x] Emits one team-scoped activity event: `GET api/activity` (API-009) unions in one
      synthetic row per audit — see Report for the shape deviation.
- [x] `GET api/location/{location}/audits` — audit history for that location, newest
      first, paginated (client can diff two audits; web can show the same).
- [x] Authorization: `item:write` to submit, `item:read` to list + resource-team checks
      against `$location->team`.
- [x] Feature tests.

## Out of scope
- Accepting/declining audit results server-side (no auto-moves of "extra" items —
  MV-058's move-extra stays a client action); audit comparison endpoints (client diffs
  two `GET` results); the Android "submit" button (MV-050 follow-up client ticket);
  PDF/report rendering (client-side, MV-059).

## Acceptance criteria
- [x] Submitting a 45-found audit (3 missing, 2 extra, 1 unknown code) persists counts
      42/3/2 + full payload and returns the created audit resource.
- [x] `GET api/activity` shows the audit event with the computed summary.
- [x] `GET api/location/:id/audits` returns prior audits newest-first.
- [x] Foreign-team location or found-ids → 422/403; read-only token → 403.
- [x] Items referenced in `found_ids` that were moved/deleted after the scan still
      validate or report cleanly (snapshot semantics — validate team membership only,
      not current location).

## Technical notes
- Route: `routes/api.php`, `auth:sanctum` group — POST + GET under
  `location/{location}/audits` (route-model binding on the Location UUID; authorize via
  `$location->team`).
- `payload` json cast on the model; keep the exact keys MV-050 already produces so the
  Android submit is a near-free serialization of its report.
- Counts: `found_count = count(found_ids)`, `missing_count` is *not* derivable server-
  side (the expected set is client knowledge from its local cache) — take it from the
  body if provided (`missing_count` optional input, validated ≥ 0, default 0);
  document that the server trusts this one counter as a declared input. Adjust the
  summary accordingly ("42 found, 3 missing declared").
- `unknown_codes` are raw scan strings (no validation beyond array-of-strings).

## Report (implementation)
- **Storage**: `2026_09_05_000005_create_audits_table` (uuid PK, team/location/user
  FKs cascade, unsigned counts, `payload` json, timestamps); `App\Models\Audit`
  (`Uuids`, `'payload' => 'json'` cast, `team()`/`location()`/`user()` relations);
  `App\Http\Controllers\AuditController` with `store()`/`index()`;
  `Database\Factories\AuditFactory` (`atLocation()`).
- **POST** (`item:write` team permission + token ability → 403 otherwise): validates
  `found_ids` required array-of-strings, `extra` optional with `extra.*.id` required,
  `unknown_codes` optional strings, `missing_count` optional `integer|min:0` (declared
  client input — the expected set lives in the client's local cache). Computes
  `found_count`/`extra_count` server-side. Team-membership check runs against
  `Item::withTrashed()` for the union of found+extra ids — **snapshot semantics**:
  trashed rows still validate, current location is never checked (acceptance matrix).
  Everything in one `DB::transaction`; since `Audit` is **not** registered with the
  API-003 revision observer, `TeamRevision::bump($audit)` is called explicitly —
  exactly one bump per audit (pinned by test). Returns the created audit resource
  (201, per the stack's POST-returning-model convention pinned since API-001).
- **GET** (`item:read` + token): `Audit::where('location_id')->with('user:id,name')`
  newest-first (`created_at desc, id desc`), paginated default 50 / max 200.
- **Activity feed union** (API-009 extended, not hijacked): `histories` keeps its FK/
  shape; instead `ActivityController` now builds cheap ordered `(id, timestamp)` slices
  of both sources, merges newest-first in PHP, and hydrates **only the page's rows**
  (eager loads `item:id,name`, `user:id:name`, `location:id,name`). A hand-built
  `LengthAwarePaginator` keeps the standard `data`/`total` JSON keys so API-009
  clients parse unchanged. Audit rows reuse the history row keys (one client parser)
  with `field_name: "audit"`, the summary in `new_value`
  ("N found[, N missing declared][, N extra]"), null `item_*`/`old_value`, plus
  `location_id`/`location_name`. `type` whitelist extended with `audit`;
  `item_id` filter excludes audits; `location_id` filter = moves INTO X for history,
  taken AT X for audits. Ties: histories first (stable sort over concat order).
- **Deviations from ticket**: (1) feed rows use the same-keys shape +
  `field_name: "audit"` instead of the sketched `{type: 'audit', summary}` shape —
  one client parser, consistent with API-009's contract; (2) id-slice merge instead of
  SQL `UNION ALL` — UNION would leak DB datetime format (`Y-m-d H:i:s`) into the
  merged rows, breaking the ISO-8601 contract; (3) foreign location → **403** (matches
  API-001 precedent for cross-team resource access), foreign found-ids → 422.
- **Tests**: `tests/Feature/LocationAuditTest.php` — 16 tests: acceptance matrix
  42/3/2 + payload round-trip; defaults; trashed-id snapshot semantics; foreign ids
  (found + extra) 422; 403 matrix (token ability, Read-Only member, foreign location
  both verbs); list scoping/ordering/pagination/shape; feed event + summary variants +
  merge order + `type=audit`/`type=name`/`item_id`/`location_id` filters + team scoping;
  single revision bump; validation matrix (missing `found_ids`, element types,
  `extra.0.id`, negative `missing_count`, `per_page` cap).
- **Verification**: `--filter 'LocationAuditTest|TeamActivityFeedTest'` 26/26; full
  suite 191 tests / 666 assertions / 4 pre-existing Jetstream skips, green.
- **Follow-up noted**: MV-050 client "submit" button (client ticket, out of scope).
