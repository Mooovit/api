---
id: API-013
title: "Image attachments on items (upload/list/download/delete)"
type: feature
priority: P1
status: in-review
depends_on: [API-001]
spec: "new idea (Matthieu, 2026-09); client contract: plan.md §2"
---

# API-013 — Image attachments on items (upload/list/download/delete)

## Context
Matthieu wants the Android app to be able to capture images for a box and send them to
the server — "attachments". Key requirement: **clients must be ABLE to use attachments
but old versions must not HAVE to** — the whole feature is additive: new routes nothing
calls today, plus one additive `attachments` field on the item detail payload that old
clients ignore. Typical use: photo of a box's contents/seal/state at transport time.

## Scope — Must have
- [x] Migration: `attachments` table — uuid PK, `item_id` (FK cascade), `team_id`
      (indexed), `user_id` (uploader), `disk` (default `local`), `path` (string,
      unique), `original_name` (nullable), `mime_type`, `size` (unsigned int),
      `caption` (nullable), `timestamps`.
- [x] `POST api/item/{item}/attachments` — multipart: `file` (required image:
      jpeg/png/webp/heic, max 10 MB) + optional `caption`. Stored under
      `attachments/{team_id}/{item_id}/{uuid}.{ext}` on the `local` disk; returns the
      attachment metadata (no binary). `item:write` + resource-team auth.
- [x] `GET api/item/{item}/attachments` — metadata list, newest first, each row
      `{id, original_name, mime_type, size, caption, user_id, url, created_at}`
      (`url` = authenticated download route — documented, not a public link).
- [x] `GET api/attachment/{attachment}` — streams the binary with the stored
      `mime_type` (`Storage::response`); `item:read` + resource-team auth (via
      `$attachment->item->team`).
- [x] `DELETE api/attachment/{attachment}` — removes the file and the row; returns
      `{"success": "success"}` (house style). Anyone with `item:write` on the team.
- [x] Additive item payload field: `GET api/item/:id` includes
      `attachments: [ ...metadata... ]` (possibly `[]`). `GET api/item` (list) stays
      lean: no attachment fields at all.
- [x] Writes bump the team revision (API-003 helper) without touching the item row.
- [x] Feature tests.

## Out of scope
- Thumbnails/resize server-side; non-image attachments (PDF etc. — whitelist keeps it
  images-only for now); public/signed URLs; S3/cloud disk (abstracted via `Storage` —
  the disk is a config swap, and API-021 will add team-supplied S3); the Android
  capture/upload UI (client follow-up ticket in the MV repo); audit/history rows for
  uploads (not needed so far — the feed contract stays field-based).

## Acceptance criteria
- [x] Upload → list → download → delete round-trip works; download bytes match the
      uploaded file; delete removes both row and file on disk.
- [x] `attachments` appears on the item detail payload (empty array when none) and old
      clients are unaffected (whole suite green — additive only).
- [x] Validation: non-image mime → 422; > 10 MB → 422; foreign-team item → 403;
      read-only token → 403 on write routes, allowed on reads.
- [x] Metadata never exposes filesystem paths (only `url` + names — raw rows never
      serialized; `metadata()` is the single serialization path).
- [x] No PATCH route involved; all four routes are POST/GET/DELETE.

## Technical notes
- Storage: `UploadedFile::storeAs("attachments/{team}/{item}/{uuid}.{ext}", 'local')`
  — uuid filename guarantees unique `path`; mime sniffed server-side via
  `getMimeType()` (finfo), never the client string alone; extension guessed from mime.
- Host PHP limits: `upload_max_filesize`/`post_max_size` must be ≥ 10 MB — note for
  deploy (validation enforces 10240 kb; if the host caps lower, tune both).
- `heic` kept in the whitelist; some PHP builds report it as
  `application/octet-stream` — re-verify with real PDA captures before tightening.
- Authorization: resolve attachment → its item → `$item->team` permission
  (API-001-corrected pattern), never `current_team`.

## Report (implementation)
- **Storage**: `2026_09_05_000007_create_attachments_table`; `App\Models\Attachment`
  (`Uuids`, `metadata()` — the single safe serialization shape, absolute `url` via
  `url()` helper); `Item::attachments()` hasMany; `AttachmentController`
  (store/index/show/destroy); routes POST+GET `item/{item}/attachments`, GET+DELETE
  `attachment/{attachment}`.
- **store**: `item:write` on the item's team + token → 403; rules
  `file required|file|mimes:jpeg,jpg,png,webp,heic|max:10240`, `caption
  nullable|string|max:255`; writes the file, creates the row, bumps the revision once
  (`TeamRevision::bump` — attachments are not observer-registered), returns
  `metadata()` at 201.
- **index**: newest first (`created_at desc, id desc`), metadata rows.
- **show**: `Storage::disk()->response(path, original_name, [Content-Type])` —
  streamed, authenticated.
- **destroy**: row + file removal; `{"success": "success"}`; one revision bump.
- **Item detail**: `show()` loads `attachments` then `setRelation`s the mapped
  `metadata()` list — raw rows (with `path`) are never serialized. Pinned by test:
  `path`/`disk` absent from list + item payloads.
- **Tests**: `tests/Feature/AttachmentTest.php` — 6 tests: full round-trip (disk
  bytes == uploaded bytes; download bytes == uploaded bytes via
  `TestResponse::streamedContent()` — `getContent()` is `false` on StreamedResponses;
  delete removes file+row; +1/+1 revision bumps); item payload `attachments` (empty
  array default, metadata rows); newest-first list (backdated); validation (text file
  422, 11 MB 422); permission matrix (token, Read-Only member read-yes/write-no,
  foreign team all four verbs 403); unknown id 404s.
- **Deviations**: `attachment_count` on the list not added (list stays completely
  lean — cleaner than the optional variant); `{"success": "success"}` instead of a
  plain bool (house style mandated by the ticket).
- **Verification**: `--filter AttachmentTest` 6/6; full suite 216 tests / 819
  assertions / 4 pre-existing Jetstream skips, green.
