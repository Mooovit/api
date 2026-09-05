---
id: API-020
title: "Auto-backup schedules per team (daily/weekly/monthly/yearly)"
type: feature
priority: P2
status: in-review
depends_on: [API-018]
spec: "user request (Matthieu, 2026-09): program from the server interface, per team, an 'auto-backup' (every day, every week, every month, every year)"
---

# API-020 — Auto-backup schedules per team (daily/weekly/monthly/yearly)

## Context
Third slice of the savepoint feature: Matthieu wants to *"program from the
server interface for each time an 'auto-backup' feature (every day, every
week, every month, every year)"* — a per-team schedule that creates a savepoint
(API-018 machinery) without anyone pressing a button. Configuration lives in
the **server UI** (web, Inertia like the rest of Jetstream); execution rides
Laravel's scheduler.

## Scope — Must have
- [x] Migration: `backup_schedules` table — uuid PK, `team_id` (unique — one
      schedule per team), `user_id` (configurator, provenance), `frequency`
      (enum: `daily`, `weekly`, `monthly`, `yearly`), `enabled` (bool, default
      true), `last_run_at` (nullable), `next_run_at` (indexed), `timestamps`.
- [x] Web UI (Inertia, session auth, verified): a `Backups/Schedules` page per
      team context — create/update the schedule (frequency picker, enabled
      toggle, shows last/next run), delete it. Routes under the existing
      Jetstream team-context middleware; POST verbs only for writes (host
      constraint) with DELETE where natural — follow the existing web
      controller conventions.
- [x] Command `moovit:auto-backups` — finds `enabled` schedules with
      `next_run_at <= now`, creates one backup per due schedule **as the
      schedule's `user_id`** (provenance), advances `last_run_at` and computes
      `next_run_at` from the frequency (daily +1 day, weekly +1 week, monthly
      +1 month, yearly +1 year — `addXxx` on the run time). One transaction
      per schedule; a failing team must not block the others.
- [x] Register the command in `app/Console/Kernel.php`'s `schedule()` —
      every 5 minutes (`withoutOverlapping()`); retention (API-018, last 7)
      applies to scheduled backups identically.
- [x] Feature tests (command + UI; the schedule itself is not API-facing).

## Out of scope
- API endpoints for schedules (the server UI is the requested surface — the
  API can come later, additively); per-schedule retention overrides; cron
  customization beyond the four frequencies; notifications on failure;
  queueing (synchronous creation inside the command is fine at this scale).

## Acceptance criteria
- [x] UI round-trip: create a daily schedule as a team owner → row persisted
      with computed `next_run_at`; update frequency/enabled; delete removes it.
- [x] Command: due schedule produces a backup (row + zip) attributed to the
      schedule's `user_id`, advances `last_run_at`/`next_run_at` correctly for
      each of the four frequencies (pinned with fixed times); not-due and
      disabled schedules untouched; second run before `next_run_at` is a no-op
      (idempotent).
- [x] Retention: scheduled backups count toward the last-7 rule (8th scheduled
      backup rotates the oldest).
- [x] Failure isolation: an exception for one team leaves other due schedules
      processed and the failed one unadvanced (retried next tick).
- [x] Non-owners (read-only members) cannot save the schedule form (policy or
      explicit check, matching Jetstream team permission conventions).

## Technical notes
- `next_run_at` computed from **now at run time**, not from a fixed anchor —
  document in the ticket; test with `Carbon::setTestNow()`.
- Frequency math: use `->addDay()`, `->addWeek()`, `->addMonth()`,
  `->addYear()` — month/year edge days (31st → Feb) follow Carbon semantics;
  pin one edge case (e.g. Jan 31 monthly → Feb 28/29) as accepted behavior.
- The command creates backups through the same service/method API-018's
  controller uses — extract a small `BackupService::createForTeam(Team, User)`
  if the controller logic is inline; do not duplicate the dump code.
- Scheduler only runs where cron exists (deploy note, same as `schedule:run`);
  tests invoke the command directly (`Artisan::call`).

## Tests
- New `tests/Feature/BackupScheduleTest.php`: UI CRUD + authorization;
  command due/not-due/disabled; four frequency advancements with
  `setTestNow`; retention interaction; failure isolation (force a failure via
  `Storage::fake` misconfiguration or a throwing spy); idempotent re-run.

## Implementation Report

**Status**: done — 9 new tests in `tests/Feature/BackupScheduleTest.php`,
full suite 270 passed / 4 skips (pre-existing Jetstream skips).

**Production changes**
- `BackupService::createForTeam(Team, User)` — the API-018 dump/zip/row/
  retention/revision-bump pipeline extracted verbatim from
  `BackupController::store()` (controller keeps authorization + metadata;
  `RETENTION = 7` moved into the service). One dump implementation, two
  entry points (manual API + scheduler).
- `backup_schedules` migration (uuid PK, unique `team_id`, indexed
  `next_run_at`), `BackupSchedule` model (`FREQUENCIES` const,
  `nextRunFor(frequency, ?from)` + `advance(?now)`), `Team::backupSchedule()`
  hasOne.
- `moovit:auto-backups` command: due = `enabled` + `next_run_at <= now`,
  ordered by `next_run_at` (deterministic for tests and fairness); per
  schedule one `DB::transaction` (backup create + `advance()` — a failing
  create rolls the advance back, retried next tick), `report($e)` + error
  line, never aborts the loop; exit code always 0.
- Kernel `schedule()`: `everyFiveMinutes()->withoutOverlapping()`.
- `BackupScheduleController` (web): `GET/POST/DELETE
  /teams/{team}/backups/schedule` under `auth:sanctum + verified`; POST is
  an **upsert** (`firstOrNew()->forceFill()->save()` — one row per team, no
  PATCH per host constraint); saving with `enabled` computes `next_run_at`
  from now at save time, disabling clears it; authorization = membership
  (`belongsToTeam`) + `hasTeamPermission` + `tokenCan` (`item:read` for the
  page, `item:write` for save/delete). Inertia page
  `resources/js/Pages/Backups/Schedules.vue` (Jetstream FormSection kit:
  frequency select, enabled checkbox, last/next run display, save + delete).

**Pinned semantics / deviations from spec**
- **Monthly edge: the ticket's example is wrong about Carbon.** Carbon's
  default `addMonth()` **overflows** (Jan 31 + 1 month → **Mar 3**, because
  Feb 31 does not exist) — it does not clamp to Feb 28 (that would be
  `addMonthNoOverflow`). Pinned as accepted behavior: 2026-01-31 monthly →
  2026-03-03 10:00:00; documented in model docblock + server.md §13.
- Enabling/disabling via upsert: `next_run_at` is cleared while disabled
  (a disabled schedule is never due); re-enabling recomputes from the new
  save time — "computed from now at configuration/run time, never from a
  fixed anchor".
- Scheduled backups are ordinary API-018 rows attributed to the schedule's
  `user_id` and count toward the same last-7 retention (pinned: 8 scheduled
  runs rotate the oldest).

**Test findings worth keeping**
- **Session-auth tests must flush guards when switching users.** Sanctum's
  RequestGuard memoizes its resolved user (`RequestGuard::user()` caches
  `$this->user`), and the `auth:sanctum` middleware leaves `sanctum` as the
  default driver after the first request — so the next `actingAs($other)`
  `setUser`s a **raw** user directly onto that memoizing guard. The closure
  that wraps session users with `TransientToken` never runs again →
  `tokenCan()` returns false and every explicit tokenCan check 403s (and
  Jetstream's `hasTeamPermission` silently skips its token check while
  `currentAccessToken() === null`). Fix used in this suite: a small
  `actingAsWeb()` helper — `forgetGuards()` + `shouldUse('web')` before
  `actingAs` — same lesson as the API trait's `forgetGuards` note.
- `Carbon::setTestNow()` returns void — do not chain (`->addDays()`) on it.
- Partial-mock failure isolation: `Mockery::mock(BackupService::class)
  ->makePartial()` with **ordered** expectations — first `createForTeam`
  `andThrow`, second `passthru` — bound via `$this->instance()`, exercises
  the real service for the healthy team while pinning the isolation.

**Deviations from spec**: monthly edge semantics corrected to Carbon's
overflow behavior (documented above + server.md §13). Otherwise as
specified.
