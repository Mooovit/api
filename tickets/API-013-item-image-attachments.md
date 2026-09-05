---
id: API-013
title: "Image attachments on items (upload/list/download/delete)"
type: feature
priority: P1
status: ready
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
- [ ] Migration: `attachments` table — uuid PK, `item_id` (FK cascade), `team_id`
      (indexed), `user_id` (uploader, FK), `disk` (default `local`), `path` (string,
      unique), `original_name` (nullable), `mime_type` (string), `size` (unsigned int),
      `caption` (nullable string), `timestamps`.
- [ ] `POST api/item/{item}/attachments` — multipart: `file` (required image:
      jpeg/png/webp/heic, max 10 MB) + optional `caption`. Stores under
      `attachments/{team_id}/{item_id}/{uuid}.{ext}` on the `local` disk; returns the
      attachment resource (metadata, no binary). `item:write` + resource-team auth.
- [ ] `GET api/item/{item}/attachments` — metadata list, newest first:
      `[{id, original_name, mime_type, size, caption, url?, created_at, user_id}]`
      (`url` = the download route, absolute, requires auth — document that it is not a
      public link).
- [ ] `GET api/attachment/{attachment}` — streams the binary with the stored
      `mime_type` (`Storage::response`); `item:read` + resource-team auth (authorizes
      via `$attachment->item->team`).
- [ ] `DELETE api/attachment/{attachment}` — removes the file and the row in one
      transaction; returns `{"success": "success"}` (house style). Uploader or anyone
      with `item:write` on the team may delete.
- [ ] Additive item payload field: `GET api/item/:id` includes
      `attachments: [ ...metadata... ]` (possibly `[]`) — same metadata shape as the
      list endpoint. Old app versions ignore it (additive rule); field names never
      change afterwards. `GET api/item` (list) stays lean: no attachment bodies —
      optional `attachment_count` integer is allowed if trivial (also additive).
- [ ] Writes bump the team revision (API-003 helper) so attachment-only changes still
      reach delta-syncing clients.
- [ ] Feature tests.

## Out of scope
- Thumbnails/resize server-side (client renders; revisit if payloads get heavy);
  non-image attachments (PDF etc. — whitelist keeps it images-only for now); public/
  signed URLs; S3/cloud disk (abstract via `Storage` so the disk is a config swap);
  the Android capture/upload UI (client follow-up ticket in the MV repo); audit/history
  rows for uploads (decide later whether `field_name=attachment` history rows are
  wanted in API-009's feed).

## Acceptance criteria
- [ ] Upload → list → download → delete round-trip works; download bytes match the
      uploaded file; delete removes both row and file on disk.
- [ ] `attachments` appears on the item detail payload (empty array when none) and old
      clients are unaffected (API-001 contract tests stay green).
- [ ] Validation: non-image mime → 422; > 10 MB → 422/413; foreign-team item → 403;
      read-only token → 403 on write routes, allowed on reads.
- [ ] Metadata never exposes filesystem paths (only `url` + names).
- [ ] No PATCH route involved; all four routes are POST/GET/DELETE (README constraint).

## Technical notes
- Laravel 8: `UploadedFile::fake()->image()` in tests; store via
  `$request->file('file')->storeAs(...)` or `Storage::putFileAs` — keep `path` unique
  (uuid filename guarantees it).
- PHP config: `upload_max_filesize`/`post_max_size` on the host must be ≥ 10 MB —
  verify and note in the report; if the host caps lower, lower the validation to match
  and document.
- Mime sniffing: rely on `$file->getMimeType()` (guarded) + extension whitelist; do not
  trust the client-provided mime string alone.
- `heic` may serialize as `application/octet-stream` on some PHP builds — include it in
  the allowed mimes explicitly after verifying what the PDA cameras emit; fallback rule:
  validate by extension whitelist + getMimeType.
- Authorization pattern: resolve the attachment → load its item → check
  `$item->team` permission (the API-001-corrected pattern), never `current_team`.

## Tests
`vendor/bin/phpunit --filter AttachmentTest`:
- upload/list/download/delete round-trip (assert file on disk before/after);
- item payload contains `attachments` metadata;
- mime/size validation; permission matrix; cross-team 403;
- revision counter bumps on upload/delete (pairs with API-003 when present — write the
  test to skip gracefully if API-003 hasn't landed).

## Documentation requirements
- PHPDoc on model + controller; `server.md`: new "Attachments" section (or note under
  §8) with the route + payload contract; plan.md §2 contract notes (additive field);
  storage layout documented in the controller class docblock.
