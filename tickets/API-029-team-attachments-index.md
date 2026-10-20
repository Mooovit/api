---
id: API-029
title: "Team-wide attachments index — GET api/attachments (delta-aware, tombstones)"
type: feature
priority: P1
status: ready
depends_on: [API-013, API-003]
spec: "server.md §11 (attachments) — extend with the team index"
---

# API-029 — Team-wide attachments index (`GET api/attachments`)

## Context

Since API-013 the only way to learn which attachments exist is per-item: the
`GET api/item/{id}` show payload (and the per-item `GET api/item/{id}/attachments`
index, currently unused by the Android client). The Android client (MV-129
follow-up, MV-133) is adding a background **attachment download queue** that
pre-fetches photos so devices can display them offline — it needs the team's
full attachment inventory **at once**, not one request per box. This ticket adds
a delta-aware team-wide index mirroring the `GET api/item?since=` shape (API-005/
API-006) so the client can piggyback on the existing revision-gated pull
(API-003) instead of scanning items.

## Scope — Must have

- [ ] `GET api/attachments` — every attachment row in the effective team,
      ordered `created_at desc, id desc`, each row as today's
      `Attachment::metadata()` shape (`id`, `original_name`, `mime_type`,
      `size`, `caption`, `user_id`, `url`, `created_at`).
- [ ] **`updated_at` added to `metadata()`** (ISO-8601, the column already
      exists) — the delta cursor needs it. The per-item index and show payload
      inherit the extra field (additive).
- [ ] Delta form: `GET api/attachments?since=<ISO-8601>` →
      `{ changed: [metadata…], deleted_ids: [int…] }` + the **`X-Revision`**
      response header (API-003), semantics identical to the items delta
      (`updated_at > since`; a garbage cursor → 422).
- [ ] Auth: `item:read` team permission + token ability, same as the existing
      attachment endpoints; rows scoped to the token's effective team
      (cross-team rows never leave).
- [ ] `POST/DELETE` attachment writes keep bumping the team revision (already
      the case) so the index participates in the pull gate.
- [ ]tickets/README.md mapping row + server.md §11 subsection documenting the
      endpoint and the client contract line in the Android repo's plan.md §2.

## Out of scope

- Pagination (v1 matches the per-item index's unbounded `->get()`; if teams grow
  into tens of thousands of photos, add cursor pagination in a follow-up — the
  delta shape already bounds repeat pulls); binary content in the index (the
  `url`/`GET api/attachment/{id}` stream stays the bytes path); captions search
  or filters.

## Acceptance criteria

- [ ] Full call returns every team attachment with `updated_at` present.
- [ ] `?since=` returns only rows with `updated_at > since` plus tombstoned ids;
      response carries `X-Revision`.
- [ ] An upload and a delete each move the revision and appear in the next delta
      (upload in `changed`, delete in `deleted_ids`).
- [ ] 401/403 semantics match the other attachment endpoints.

## Technical notes

- Tombstones: attachments are hard-deleted today (file + row) — `deleted_ids`
  therefore needs either the existing activity log, a cheap `deleted_at` column
  on `attachments`, or reuse of the soft-delete trait; pick the lightest that
  keeps the items-delta recipe (whichever API-006 used for items).
- Keep `metadata()`'s field order/shape additive — existing web/clients ignore
  unknown fields.

## Tests

- Feature tests: full index, delta window, tombstones, `X-Revision` header,
  team scoping, permission matrix (read-only member 403 on nothing — read needs
  `item:read`), 422 garbage cursor.
