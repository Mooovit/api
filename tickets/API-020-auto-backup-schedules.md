---
id: API-020
title: "Auto-backup schedules per team (daily/weekly/monthly/yearly)"
type: feature
priority: P2
status: ready
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
- [ ] Migration: `backup_schedules` table — uuid PK, `team_id` (unique — one
      schedule per team), `user_id` (configurator, provenance), `frequency`
      (enum: `daily`, `weekly`, `monthly`, `yearly`), `enabled` (bool, default
      true), `last_run_at` (nullable), `next_run_at` (indexed), `timestamps`.
- [ ] Web UI (Inertia, session auth, verified): a `Backups/Schedules` page per
      team context — create/update the schedule (frequency picker, enabled
      toggle, shows last/next run), delete it. Routes under the existing
      Jetstream team-context middleware; POST verbs only for writes (host
      constraint) with DELETE where natural — follow the existing web
      controller conventions.
- [ ] Command `moovit:auto-backups` — finds `enabled` schedules with
      `next_run_at <= now`, creates one backup per due schedule **as the
      schedule's `user_id`** (provenance), advances `last_run_at` and computes
      `next_run_at` from the frequency (daily +1 day, weekly +1 week, monthly
      +1 month, yearly +1 year — `addXxx` on the run time). One transaction
      per schedule; a failing team must not block the others.
- [ ] Register the command in `app/Console/Kernel.php`'s `schedule()` —
      every 5 minutes (`withoutOverlapping()`); retention (API-018, last 7)
      applies to scheduled backups identically.
- [ ] Feature tests (command + UI; the schedule itself is not API-facing).

## Out of scope
- API endpoints for schedules (the server UI is the requested surface — the
  API can come later, additively); per-schedule retention overrides; cron
  customization beyond the four frequencies; notifications on failure;
  queueing (synchronous creation inside the command is fine at this scale).

## Acceptance criteria
- [ ] UI round-trip: create a daily schedule as a team owner → row persisted
      with computed `next_run_at`; update frequency/enabled; delete removes it.
- [ ] Command: due schedule produces a backup (row + zip) attributed to the
      schedule's `user_id`, advances `last_run_at`/`next_run_at` correctly for
      each of the four frequencies (pinned with fixed times); not-due and
      disabled schedules untouched; second run before `next_run_at` is a no-op
      (idempotent).
- [ ] Retention: scheduled backups count toward the last-7 rule (8th scheduled
      backup rotates the oldest).
- [ ] Failure isolation: an exception for one team leaves other due schedules
      processed and the failed one unadvanced (retried next tick).
- [ ] Non-owners (read-only members) cannot save the schedule form (policy or
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
