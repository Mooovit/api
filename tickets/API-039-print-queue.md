---
id: API-039
title: "Server-side DYMO print queue — stations declare printers, kanban + app push jobs"
type: feature
priority: P1
status: in-review
depends_on: [API-038]
spec: "user request 2026-09-24 (server-side queue; frontend declares a printer; API push for kanban + app); precedent: API-038 (client-side DYMO), API-037 (sync_batches table style); client side: app to be ticketed (MV)"
---

# API-039 — Server-side DYMO print queue

## Context
API-038 shipped printing as a purely client-side affair: only the browser session
sitting in front of a DYMO could print, the only "queue" was a per-page-load promise
chain in `public/js/dymo.js`, and there was no way for the kanban page, the app, or
another device to hand a label to a printer that lives on a different workstation.
The user (2026-09-24) wants the server to own the queue: the frontend declares a
printer (a "print station"), and APIs let kanban **or the app** push a label job.
The app can list available (live) printers; it may target one — if that printer is
not live anymore, the push fails naming the reason; untargeted jobs are claimed by
the first live station. Jobs persist until a frontend station releases (prints) them.

Decisions taken with the user:
- **Everything** goes through the queue (kanban buttons and the station's
  touch-to-print included) — no direct local printing path remains in the UI.
- Item labels only in v1 (location labels are a follow-up).
- New `print:write` token ability gates the app's queue endpoints.

## Scope — Must have
- [x] Migration `print_stations` (uuid PK, team FK, `client_id` browser identity,
      `printer_name`, optional `label` + `template`, `last_seen_at` heartbeat,
      unique `[team_id, client_id]`) and `print_jobs` (uuid PK, team FK, nullable
      user FK, item FK cascade, `template`, nullable `target_printer`,
      `status` pending|claimed|printed|failed, `claimed_by`/`claimed_at`,
      `completed_at`, `error`, `attempts`, index `[team_id, status, created_at]`).
- [x] `App\Models\PrintStation` (`isLive()` = heartbeat within 90s) and
      `App\Models\PrintJob`.
- [x] `App\Support\PrintQueue` — enqueue / heartbeat (upsert) / liveStations /
      claim / complete. Claim is atomic (single-row `UPDATE … WHERE status='pending'`
      affected-rows check), FIFO by `created_at`, targeted-to-me jobs first, stale
      claims (claimed > 300s, never completed) reclaimable; complete() retries a
      failed print back to `pending` until 3 attempts then terminal `failed`
      (error stored; jobs are never deleted).
- [x] `App\Exceptions\PrintTargetOfflineException` → controllers map to
      **409** `{"error": "Printer \"X\" is not available"}`.
- [x] Token API (`auth:sanctum`, effective team + `item:write` team permission
      AND `print:write` ability): `POST api/print/jobs` (enqueue; `item` uuid,
      `template` in 30334/30252/30336, optional `target_printer`) and
      `GET api/print/stations` (live stations only).
- [x] Session API for the web UI (`routes/web.php`, session group):
      `POST /print/jobs` (push), `GET /print/claim` (claim + heartbeat),
      `POST /print/jobs/{job}/complete`, `POST /print/station` (declare).
      Claim response carries the print-ready payload (job id, item uuid,
      item name, template) so the station never re-fetches.
- [x] `public/js/dymo.js`: station identity (localStorage `dymo.station`),
      `declareStation()`, `startQueueWorker()` — 2s claim loop, paused while the
      tab is hidden or a print is in flight, prints claimed jobs via the existing
      `printItem`, posts the result, dispatches `dymo:job-printed` /
      `dymo:job-failed` events.
- [x] `public/js/label-print.js`: touch-to-print pushes a queue job (row state
      follows the job lifecycle); station declares itself + starts the worker.
- [x] `public/js/kanban.js`: `printItemLabel` becomes a queue push (works with no
      local printer); kanban tabs with a ready DYMO also declare + drain the queue.
- [x] `.dymo-print` CSS gate removed — buttons always visible (queue push needs
      no local DYMO), blade + `buildCardHtml` parity.

## Out of scope
- Location label jobs (API-035 stickers) — follow-up ticket.
- WebSocket/SSE push to stations — polling only (~2s), like the revision pattern.
- Job TTL / purge — jobs persist until printed/failed (user decision).
- App-side UI to pick a printer and push (Android ticket MV — to be written);
  this ticket ships the API contract only.
- Any change to the label templates/geometry or the DYMO framework vendoring.

## Acceptance criteria
- [ ] `POST api/print/jobs` with only `item:write` (no `print:write` ability) → 403.
- [ ] Enqueue for another team's item → 404; bad template → 422.
- [ ] Enqueue targeting a printer whose station hasn't heart-beated within 90s →
      409 with the printer name in `error`; untargeted enqueue succeeds even with
      zero live stations (job just waits).
- [ ] `GET api/print/stations` returns only stations with a fresh heartbeat.
- [ ] Claim is exclusive and FIFO: two stations never receive the same job;
      a targeted station gets its jobs before untargeted ones are drained.
- [ ] A claimed-but-never-completed job becomes claimable again after 300s
      (attempts incremented); a failed print returns to `pending` until 3
      attempts, then `failed` with the error kept.
- [ ] `GET /print/claim` refreshes `last_seen_at` (claim doubles as heartbeat).
- [ ] Completing a job from a station that doesn't own it is a no-op (false).
- [ ] Queue writes do NOT bump `teams.revision` (printing is plumbing, not
      synced domain data — same decision as sync_batches).
- [ ] Guest on any of the four session print routes → 302 login.
- [ ] Kanban/print-station pages ship the new flow; buttons visible without
      local DYMO; `node --check` clean; LabelPrintTest still green.

## Technical notes
- Migration style per `2026_09_22_000001_create_sync_batches_table.php`: named
  class, uuid PKs, explicit `foreign()` calls, `$table->timestamps()`, docblock.
- Controllers follow the repo authz idiom: `$user->effectiveTeam()` +
  `$user->hasTeamPermission($team, …)` + `$user->tokenCan('print:write')`
  (API flavor only) else `AuthorizationException`. Inline `$request->validate()`.
  Team-scoped `findOrFail` for 404s (no existence leak).
- `GET /print/claim` takes **query params** (`client_id`, `printer_name`,
  `template`) — GET bodies are unreliable; it doubles as the heartbeat.
- `POST /print/jobs/{job}/complete` resolves the station by `client_id` + team;
  wrong owner → no-op.
- `print:write` is a free-form Sanctum ability (no central registry); documented
  in `server.md`'s ability list and available to tokens minted via /devices.
- Not revision-observed (no `BumpsTeamRevisionObserver`, no `TeamRevision::bump`)
  — printing must not move the clients' sync cursor.
- Known trade-off: the item name is snapshotted at claim time — a rename between
  enqueue and print prints the old name. Deleted items cascade their pending jobs.
- Cache busting: kanban page already uses `?v={{ time() }}`; `label-print.blade.php`
  bumped to `dymo.js?v=3` + `label-print.js?v=2` (framework stays `?v=2`).

## Tests
Automated (`vendor/bin/phpunit --filter PrintQueue`):
- `PrintQueueTest` (token API): auth 401 / ability 403 / team-permission 403 /
  201 pending / 422 template / 404 cross-team / 409 dead target (printer named) /
  201 untargeted with no stations / stations list liveness filter.
- `PrintQueueWebTest` (session): guest 302 ×4; FIFO + targeting precedence;
  claim exclusivity; stale reclaim; complete → printed; 3 failures → failed;
  wrong-owner no-op; claim refreshes heartbeat; no `teams.revision` bump;
  session user without `item:write` → 403 on push.
- `LabelPrintTest` updated where the removed `.dymo-print` gate was asserted;
  full suite green.

Real hardware printing cannot run in CI — manual checklist in the reviewer notes.

## Documentation requirements
- PHPDoc on both controllers + `PrintQueue`.
- `server.md`: implemented-entries blockquote after the API-038 one — queue
  model, endpoint shapes (token + session flavors), `print:write` in the ability
  list, constants (90s liveness / 300s stale / 3 attempts), not-revision-observed.
- This ticket's mapping row in `tickets/README.md`.

## Implementation report
*(filled by the implementing agent — do not touch before starting work)*

### Blockers
- none

### Changes
- Queue backend: `CreatePrintStationsAndPrintJobsTable` migration (sync_batches
  style: uuid PKs, explicit FKs, `down()` drops jobs then stations),
  `App\Models\PrintStation` (`isLive()` against `PrintQueue::LIVE_WINDOW_SECONDS`)
  + `App\Models\PrintJob` (status constants, `team()/item()/claimer()`),
  `App\Exceptions\PrintTargetOfflineException` (static `forPrinter()` factory),
  and `App\Support\PrintQueue` — enqueue / heartbeat / liveStations / claim /
  complete; claim is a conditional single-row UPDATE with an affected-rows===1
  ownership check (portable across SQLite and MySQL).
- Controllers: `PrintApiController` (token flavor: `storeJob` gated by
  `item:write` permission AND `print:write` ability; `stations` by `item:read`
  + `print:write`) and `PrintQueueController` (session flavor: `storeJob`,
  `claim` with query params doubling as heartbeat, `complete` with wrong-owner
  no-op, `station`). Both map the offline exception to 409 naming the printer.
- Routes: `api/print/jobs` + `api/print/stations` inside the sanctum group;
  `POST /print/jobs`, `GET /print/claim`, `POST /print/jobs/{job}/complete`,
  `POST /print/station` in the web session group (after `/label-print`,
  before the generic `/kanban/{type}` catch-all).
- `print:write` added to `DeviceTokenController::DEVICE_ABILITIES` and to
  Jetstream's `defaultApiTokenPermissions` (token UI default ticks).
- Frontend: `dymo.js` gains the station identity (localStorage `dymo.station`),
  `declareStation()`, `startQueueWorker()` (2s poll, paused when the tab is
  hidden or a print is in flight, result posted back, `dymo:job-printed` /
  `dymo:job-failed` events); `label-print.js` touch-to-print and kanban
  `printItemLabel` now queue-push via `POST /print/jobs` (409 → error toast
  naming the printer); kanban tabs with a ready DYMO declare + drain the
  queue; the `.dymo-print` CSS gate is gone (buttons always visible).
- Fix during development, caught by the web tests: `PrintQueueController`
  caught `PrintTargetOfflineException` without importing it — the catch
  clause resolved to a nonexistent class and never matched, so a dead-target
  push surfaced as 500 instead of 409 (the API controller had the import,
  which is why its 409 tests passed from the start). The FIFO test was also
  corrected to declare the target station *before* the targeted push (per the
  agreed semantics such a push must fail 409 while no station is live) and a
  dedicated targeted-precedes-older-untargeted test was added.

### Files touched
- new: `database/migrations/2026_09_24_000001_create_print_stations_and_print_jobs_table.php`,
  `app/Models/PrintStation.php`, `app/Models/PrintJob.php`,
  `app/Exceptions/PrintTargetOfflineException.php`, `app/Support/PrintQueue.php`,
  `app/Http/Controllers/PrintApiController.php`,
  `app/Http/Controllers/PrintQueueController.php`,
  `tests/Feature/PrintQueueTest.php`, `tests/Feature/PrintQueueWebTest.php`,
  this ticket
- modified: `routes/api.php`, `routes/web.php`,
  `app/Http/Controllers/DeviceTokenController.php`,
  `app/Providers/JetstreamServiceProvider.php`, `public/js/dymo.js`,
  `public/js/label-print.js`, `public/js/kanban.js`,
  `resources/views/kanban.blade.php`, `resources/views/label-print.blade.php`
  (`dymo.js?v=3`, `label-print.js?v=2`), `tests/Feature/LabelPrintTest.php`,
  `server.md` (API-039 implemented entry + `print:write` ability row),
  `tickets/README.md`

### Tests run
```
vendor/bin/phpunit --filter "PrintQueueWebTest|PrintQueueTest|LabelPrintTest"  → PASS, 29 tests, 124 assertions
vendor/bin/phpunit                                                             → PASS, 482 tests (4 pre-existing Jetstream skips)
node --check public/js/{dymo,label-print,kanban}.js                            → syntax OK
```

### Commits
- `api-039-print-queue` branch tip: `API-039(feat): server-side DYMO print queue — stations, jobs, claim/release`

### Notes for reviewer
Manual hardware checklist (printing cannot be exercised in CI):
- [ ] Workstation A (printer attached): open /label-print, DYMO ready → status
      strip shows the queue worker running; the station appears in
      `GET api/print/stations` (token with `print:write`) while the tab is open,
      and drops out ~90s after the tab is hidden/closed.
- [ ] Push a job from a *different* surface (kanban button on machine B, or a
      curl POST api/print/jobs with a `print:write` token) → machine A prints it
      within ~2s; row/button state follows (queued → printed).
- [ ] Target the job at a made-up printer name → the POST fails 409 naming it.
- [ ] Target the job at the live station's printer name → prints there even when
      other stations are polling.
- [ ] Kill the tab on machine A mid-queue → the claimed job is re-claimed by
      another station (or the same one reopened) after ~5 minutes.
- [ ] Unplug the printer (DYMO Connect up, no spooler): the print fails → job
      retries (up to 3 attempts) then shows failed with the DYMO error text.
