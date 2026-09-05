---
id: API-018
title: "Savepoint backups — CSV dumps of a team's data"
type: feature
priority: P1
status: ready
depends_on: [API-001]
spec: "user request (Matthieu, 2026-09): savepoints dump items/locations/statuses/labels into CSVs, stored on the server, owned by a user, downloadable; last 7 per team kept"
---

# API-018 — Savepoint backups — CSV dumps of a team's data

## Context
First slice of the savepoint feature (Matthieu, verbatim): *"a 'savepoint'
would dump all the objects we need (items, locations, statuses, labels, ...)
into different CSVs. And store the backup of this on the server. It would
create this also in a table so we know that this backup belongs to that user.
... choose to download the backup. If there's no s3 bucket, only save and
remember the last 7 backups for a team maximum"*.

One endpoint family: create a snapshot (CSV per entity type, zipped on
download), list/inspect/delete snapshots, retention = last 7 per team.

## Scope — Must have
- [ ] Migration: `backups` table — uuid PK, `team_id` (indexed), `user_id`
      (creator — "belongs to that user"), `disk` (default `local`),
      `path` (unique), `size` (unsigned int), `item_count`/`location_count`/
      `status_count`/`label_count` (unsigned ints), `timestamps`.
- [ ] `POST api/backups` — dumps the calling team's data as one CSV per entity:
      `items.csv`, `locations.csv`, `statuses.csv`, `labels.csv` (all rows,
      all columns, header line, soft-deleted items included with their
      `deleted_at` — a savepoint is a full snapshot). Stored under
      `backups/{team_id}/{backup_id}.zip` on the `local` disk; creates the row;
      enforces retention (see below); returns the backup metadata 201.
      Authorization: `item:write` + resource-team style (the team from the
      request context — `currentTeam`, as this is team-level, not
      item-addressed).
- [ ] Retention: after each create, keep only the **last 7** backups of the
      team — older rows are deleted together with their stored files.
- [ ] `GET api/backups` — metadata list for the team, newest first:
      `{id, user_id, size, item_count, location_count, status_count,
      label_count, url, created_at}` (`url` = authenticated download route;
      filesystem paths never serialized).
- [ ] `GET api/backup/{backup}` — streams the zip
      (`Storage::response`, `application/zip`, filename
      `backup-{team}-{timestamp}.zip`); `item:read` + same-team only.
- [ ] `DELETE api/backup/{backup}` — removes row + file; `{"success": "success"}`
      (house style); `item:write`.
- [ ] Writes bump the team revision (API-003 helper — backups are not
      observer-registered).
- [ ] Feature tests.

## Out of scope
- Comparing two backups (API-019); scheduled auto-backups (API-020); S3
  offload/bucket listing (API-021); per-user visibility filtering (any member
  sees the team's backups — the `user_id` is provenance, not an ACL); CSV of
  histories/audits (feed contracts stay field-based); restore/import from a
  backup (explicitly not requested).

## Acceptance criteria
- [ ] Round-trip: create on a populated team → metadata counts match the
      tables; download returns a valid zip whose CSVs parse back to exactly
      the dumped rows (items incl. a soft-deleted one); delete removes row +
      file.
- [ ] Retention: creating the 8th backup deletes the oldest (row + file gone,
      count stays 7); manual `DELETE` frees a slot (retention counts rows).
- [ ] Validation/auth: read-only token → 403 on create/delete, allowed on
      list/download; foreign-team backup → 403 on all verbs; unknown id → 404.
- [ ] Metadata never exposes `path`/`disk` (pinned like API-013).
- [ ] Revision bump per create/delete (baseline-relative assertions).

## Technical notes
- Build CSVs with `fopen('php://temp')` + `fputcsv` and stream them into a
  `ZipArchive` written to a temp file, then `Storage::putFileAs`/`put` — no
  full in-memory row arrays beyond what the queries stream.
- `league/csv` is NOT required — native `fputcsv` keeps dependencies at zero.
- Zip reading in tests: `ZipArchive::extractTo(storage_path('framework/...'))`
  or open in-memory and `getFromName('items.csv')` — pick one helper.
- SQLite `Storage::fake('local')` in setUp, same as AttachmentTest.

## Tests
- New `tests/Feature/BackupTest.php`: round-trip (zip contents == table
  contents, counts, download via `streamedContent()`), retention (8th push +
  delete-frees-slot), list shape/order (no `path`/`disk`), permission matrix,
  404s, revision bumps.
