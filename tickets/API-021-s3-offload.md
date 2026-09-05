---
id: API-021
title: "S3 offload for backups + bucket listing and rules display"
type: feature
priority: P2
status: in-review
depends_on: [API-018]
spec: "user request (Matthieu, 2026-09): setup S3 credentials on the app, backups copied to the bucket, bucket listing + bucket rules shown; without S3 keep last 7 per team"
---

# API-021 — S3 offload for backups + bucket listing and rules display

## Context
Last slice of the savepoint feature: Matthieu wants to *"setup on the app some
s3 credentials and have these backup copied over a s3 bucket. If there's no s3
bucket, only save and remember the last 7 backups for a team maximum, if
there's a s3 bucket, attempt to list them from that bucket, and show the
bucket rules"*. So: per-team S3 credentials, backup copy-off on create, an
endpoint listing what landed in the bucket, and a display of the applicable
retention rules (with S3: bucket rules/local last-7 still noted; without:
last-7 only).

## Scope — Must have
- [ ] Migration: `team_s3_configs` table — uuid PK, `team_id` (unique),
      `bucket`, `region`, `access_key`, `secret_key` (encrypted at rest via
      Crypt), optional `endpoint` (for S3-compatible stores), `prefix`
      (default `backups/{team_id}`), `timestamps`.
- [ ] Credential management API: `PUT`-free —
  - `POST api/team/s3` `{bucket, region, access_key, secret_key, endpoint?}` —
    upsert (validated by an actual `HeadBucket`-style connectivity check when
    possible; on failure 422 with the driver message — never persist dead
    credentials);
  - `GET api/team/s3` — `{bucket, region, endpoint, prefix, configured: true}`
    — **secret never returned**;
  - `DELETE api/team/s3` — removes the config; `item:write` for all three.
- [ ] Copy-off: `BackupService` (API-018) — when the team has S3 configured,
      after storing locally, copy the zip to the S3 disk (one attempt; a
      failed copy fails the request 502-style without creating the row — the
      local temp file is cleaned); backups row records `remote: true` (new
      nullable bool column via this ticket's migration or added to
      API-018's — one migration per ticket, so add a small follow-up
      migration here).
- [ ] `GET api/backups/bucket` — lists the team's backup objects from the
      bucket (keys + size + last_modified, newest first); 422 with a clear
      error when no S3 is configured; upstream S3 errors surface as 502 with
      the message (network/bucket problems are not validation errors).
- [ ] `GET api/team/s3/rules` (or folded into `GET api/team/s3` as `rules`) —
      the applicable retention rules, computed not hardcoded prose:
      `{local_retention: 7, s3_configured: bool, s3_retention: null|"per bucket
      lifecycle", note}` — "bucket rules" = the S3 lifecycle configuration
      fetched via the client when available (`getLifecycle`), else `null`
      (many S3-compatible stores / restricted IAM return nothing → `null`,
      not an error).
- [ ] Retention semantics: **without** S3 the last-7 rule applies (API-018).
      **With** S3 the local copies still rotate at 7; the bucket copies are
      NOT deleted by rotation (bucket lifecycle is the user's domain — that's
      what the rules display is for). Pinned by tests.
- [ ] Feature tests (S3 faked with `Storage::fake('s3')`; lifecycle/rules via a
      client seam that can be faked — no live network in tests).

## Out of scope
- Restore-from-S3 (backups keep their local row; the bucket is disaster
  recovery); multi-bucket per team; bucket creation; IAM policy generation;
  migrating old API-018 backups into a newly configured bucket (only new
  backups copy off).

## Acceptance criteria
- [ ] Config round-trip: upsert (connectivity check mocked/faked ok + failure
      422), read-back without secret, delete.
- [ ] With S3: `POST api/backups` stores locally AND in the fake bucket;
      row marked remote; `GET api/backups/bucket` lists the object; local
      rotation at 7 does NOT delete bucket copies.
- [ ] Without S3: backup create unchanged (last-7 rotation); bucket list →
      422; rules show `s3_configured: false` with local retention 7.
- [ ] Secret at rest is encrypted in the DB row (pinned by asserting the raw
      column != plaintext) and never serialized (pinned on both config
      endpoints).
- [ ] Foreign-team config/backup access → 403; read-only token: reads allowed,
      writes 403.

## Technical notes
- Laravel 8 needs `league/flysystem-aws-s3-v3` (^1.x) — `composer require
  league/flysystem-aws-s3-v3:^1.0`. Build a runtime disk config from the row:
  `Storage::createS3Driver([...])` (or a `FilesystemAdapter` via the
  `Aws\S3\S3Client` directly for listing/lifecycle) — credentials never go
  into config files or `.env`.
- Keep the S3 client behind one small seam (e.g. `S3BackupClient` wrapper with
  `headBucket/listObjects/getLifecycle/put`) so tests fake that seam instead
  of network; `Storage::fake('s3')` covers the copy path.
- `Crypt::encryptString` for the secret; decrypt only at client-build time.
- Connectivity check on upsert: `HeadBucket` — cheap and definitive; catch
  SDK exceptions → 422 `{"bucket": ["<sdk message>"]}`.

## Tests
- New `tests/Feature/TeamS3Test.php`: credential CRUD + encryption-at-rest +
  secret-never-serialized; backup create copies to fake bucket + `remote`
  flag; bucket listing (order/shape); rules payload with/without S3; rotation
  interaction (local pruned, bucket kept); 422 no-config / 403 foreign /
  401 unauthenticated paths.

## Implementation Report (2026-09)
Status: done. 10 new tests in `tests/Feature/TeamS3Test.php`, full suite
green (280 passed / 4 pre-existing Jetstream skips).

- **Seam instead of `Storage::fake('s3')`** — per-team credentials make a
  global `s3` disk meaningless (each team has its own bucket/keys, and the
  SDK needs a live endpoint for lifecycle). Implemented as the ticket's
  alternative suggestion: `S3BackupClient` (wraps `Aws\S3\S3Client`:
  `headBucket`/`put`/`listObjects`/`getLifecycle`) + `S3BackupClientFactory`
  (`forConfig(TeamS3Config)` builds the client from the DB row, secret
  decrypted at build time). Tests bind a mocked factory via
  `$this->instance()` — no network. `league/flysystem-aws-s3-v3` was
  installed per the note but only the raw SDK is actually used (flysystem
  has no lifecycle API).
- **Permission split** (ticket said "item:write for all three" but the
  acceptance wants "read-only token: reads allowed"): config management
  (`POST`/`GET`/`DELETE api/team/s3`) = `item:write` — a read-only member
  must not see whether credentials exist; the reads that matter
  (`GET api/backups/bucket`, `GET api/team/s3/rules`) = `item:read`. The
  show endpoint stays `item:write` deliberately.
- **Probe before persist**: `store` builds an unsaved `TeamS3Config`
  (`forceFill` applies the `encrypted` cast so the factory reads the secret
  back exactly as it would be saved) and runs `HeadBucket` first; an
  `AwsException` maps to 422 `{"bucket": ["<sdk message>"]}` and nothing is
  written. Upsert via `firstOrNew` + `forceFill` (POST only — no PATCH on
  this host).
- **Rules endpoint**: `lifecycle` passes through `getLifecycle()` which
  returns `null` for "no rules / store doesn't answer" (catches
  `AwsException` inside the seam — pinned by a partial mock whose real
  `getLifecycle` runs against a throwing SDK client). `s3_retention` is the
  literal `"per bucket lifecycle"` when configured.
- **`remote` flag** is row-level only: `backups.remote` (nullable bool) is
  recorded by `BackupService` but deliberately NOT added to
  `Backup::metadata()` — the API-018 payload contract stays additive-only.
- **Rotation interaction pinned**: with S3, 8 creates → 7 local rows/files,
  8 bucket keys (the bucket is never pruned locally).
- **Test findings** (recurring traps, now with three data points):
  - `withToken()` sets a *sticky* `Authorization` default header on the
    test client — an "unauthenticated" request later in the same test still
    sends the previous token (got 403, expected 401). Fix:
    `$this->flushHeaders()` + `forgetGuards()`.
  - Full `Mockery::mock(S3BackupClient::class)` bypasses the real methods —
    to pin the newest-first listing sort, the test uses a partial mock
    (`Mockery::mock(S3BackupClient::class, [$sdkMock, 'bucket'])->makePartial()`)
    whose real `listObjects` runs against a canned paginator
    (`new Result([...])` pages, `DateTimeImmutable` for `LastModified`).
