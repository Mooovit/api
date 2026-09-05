---
id: API-010
title: "Audit/stocktake acceptance endpoint"
type: feature
priority: P2
status: ready
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
- [ ] Migration: `audits` table — uuid PK, `team_id`, `location_id`, `user_id` (FKs,
      cascade on team/location), `found_count`, `missing_count`, `extra_count` (int),
      `payload` (json: `{found_ids: [], extra: [{id, code?}], unknown_codes: []}` — the
      exact MV-050 report structure), `timestamps`.
- [ ] `POST api/location/{location}/audits` — body:
      `{found_ids: string[], extra: [{id: string}], unknown_codes: string[]}` (extra and
      unknown optional, default empty). Server validates: location + all referenced ids
      belong to the team; computes the three counts server-side (do not trust client
      counters); stores the snapshot.
- [ ] Emits one `History` row so it lands in the feed: `item_id` is the problem —
      history rows are item-scoped. Decision: store the audit row as a **team-scoped
      activity event** in the `audits` table, and have `GET api/activity` (API-009)
      union in one synthetic row per audit: `{type: 'audit', location_id, user_name,
      summary: "42/45 found", changed_at}` — no fake `item_id` rows. (Alternative
      rejected: hijacking `histories` with a null item breaks its FK/shape.)
- [ ] `GET api/location/{location}/audits` — audit history for that location, newest
      first, paginated (client can diff two audits; web can show the same).
- [ ] Authorization: `item:write` to submit, `item:read` to list + resource-team checks
      against `$location->team`.
- [ ] Feature tests.

## Out of scope
- Accepting/declining audit results server-side (no auto-moves of "extra" items —
  MV-058's move-extra stays a client action); audit comparison endpoints (client diffs
  two `GET` results); the Android "submit" button (MV-050 follow-up client ticket);
  PDF/report rendering (client-side, MV-059).

## Acceptance criteria
- [ ] Submitting a 45-found audit (3 missing, 2 extra, 1 unknown code) persists counts
      42/3/2 + full payload and returns the created audit resource.
- [ ] `GET api/activity` shows the audit event with the computed summary.
- [ ] `GET api/location/:id/audits` returns prior audits newest-first.
- [ ] Foreign-team location or found-ids → 422/404; read-only token → 403.
- [ ] Items referenced in `found_ids` that were moved/deleted after the scan still
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
  body if provided (`expected_count`/`missing_count` optional input, validated ≥ 0);
  document that the server trusts these two counters as declared inputs. Adjust the
  summary accordingly ("42 found, 3 missing declared").
- `unknown_codes` are raw scan strings (no validation beyond array-of-strings).

## Tests
`vendor/bin/phpunit --filter LocationAuditTest`:
- happy path counts + payload + activity event;
- list ordering/pagination; foreign-team rejections; token-permission matrix;
- audit with empty `extra`/`unknown` defaults.

## Documentation requirements
- PHPDoc on the controller/model; `server.md` §7 mark implemented + payload example;
  plan.md §2 contract notes; note the MV-050-follow-up client ticket need in the ticket
  report.
