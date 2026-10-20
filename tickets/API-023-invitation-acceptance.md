---
id: API-023
title: "Team invitation acceptance — fix access after join + pin with tests"
type: fix
priority: P1
status: in-review
depends_on: [API-001, API-014]
spec: "— (user request: 'issues inviting users to a team and them accessing it on their own', Matthieu 2026-09)"
---

# API-023 — Team invitation acceptance: fix access after join + pin with tests

## Context
Jetstream's invitation flow (invite via `POST /teams/{team}/members`, accept via
the signed `GET /team-invitations/{invitation}` link) is wired by the framework,
but the project never tested the **accept** side, and users report that invited
members "can't access the team on their own". Investigation (2026-09-05) found
concrete defects, all around acceptance:

1. **`current_team_id` is never set on accept** (`vendor/.../TeamInvitationController::accept`
   → `app/Actions/Jetstream/AddTeamMember.php` only attaches the pivot). A
   member who just joined keeps their previous `current_team_id` (or NULL for a
   fresh account) → `effectiveTeam()` (`app/Models/User.php`) falls back to a
   team they can't see or to none → dashboard/API reads fail. This is almost
   certainly the reported "can't access it on their own" bug.
2. **Stale invitations are not cleaned** when the member is added by another
   path; accepting afterwards fails with a confusing "already belongs" error.
3. **Email case is never normalized** (`required|email` only) — `Foo@x.com`
   invited, `foo@x.com` registered → accept 404s (`findUserByEmailOrFail`).
4. The signed accept link is authenticated but not **tied to the invitee**:
   any logged-in user clicking it attaches the *invited email's* account
   (Jetstream default) — at minimum this must be pinned/documented, with a
   guard that the wrong logged-in user gets a clear error, not a silent
   cross-account attach.

## Scope — Must have
- [x] Fix (1): on accept, if the invitee's `current_team_id` is NULL or points
      at a team they don't belong to, set it to the team they just joined —
      implemented as a `TeamMemberAdded` listener (`SetCurrentTeamOnJoin`), so
      it covers both the accept and any direct-add path. Existing members
      switching from another team they still belong to keep their current team.
- [x] Fix (2): when a member is added (accept OR direct add via the
      `AddTeamMember` action), delete any pending `team_invitations` rows for
      that email + team (case-insensitive).
- [x] Fix (3): normalize invitation emails to lowercase on create and match
      case-insensitively on accept-lookup (additive: existing lowercase rows
      unaffected). Includes a case-insensitive `hasUserWithEmail` override on
      `App\Models\Team` and a case-insensitive account-existence rule in
      `AddTeamMember::validate`.
- [x] Guard (4): the signed accept link is only consumable by the addressed
      account (case-insensitive); a different logged-in user gets a redirect
      + danger banner and the invitation stays pending.
- [x] Feature tests (see Tests) covering the full invite → accept → access
      flow for both roles (`admin`, `Read Only`).

## Out of scope
- Replacing the email flow with in-app invitations; email deliverability.
- Changing the signed-URL scheme or the route shapes (Jetstream contract).
- Invitations via `api/*` (no such endpoint exists; none added here).
- Deleting unused vendor behavior — fixes live in `app/Actions/Jetstream/*`.

## Acceptance criteria
- [ ] Fresh user A invites existing user B (role `Read Only`); B accepts while
      logged in as B → pivot row with role, B's `current_team_id` = the team,
      B's dashboard shows the team, B's API token reads work and writes 403.
- [ ] Same with role `admin` → API writes succeed.
- [ ] Accepting while logged in as user C (not the invitee) does NOT add B's
      account silently (clear error / blocked), and B's membership is untouched.
- [ ] After B is added (either path), B's pending invitation for that team is
      gone; a second accept attempt fails cleanly.
- [ ] Case-insensitive: invite `Foo@X.com`, account `foo@x.com` → accept works.

## Technical notes
- Accept route: `GET /team-invitations/{invitation}` with `signed` +
  `auth` middleware (vendor `routes/inertia.php:68`). The controller calls
  `app(AddsTeamMembers::class)->add(...)` — Jetstream binds the interface to
  `AddTeamMember` via `Jetstream::setAddsTeamMembersHandler`... in this app the
  binding happens through `app/Providers/JetstreamServiceProvider.php` action
  registration (`AddTeamMember` is already the app override — edit that file).
- `app/Models/User.php::effectiveTeam()` (API-015) prefers the token's
  `current_team_id`, then `currentTeam` — the fix must land where the pivot is
  attached (`AddTeamMember::add`), NOT in `effectiveTeam`.
- SQLite test quirk: `RefreshDatabase` + uuid PKs — use the existing factories
  (`User`, `Team` from `InteractsWithApi::newUserWithTeam`).
- Sessions in tests: `actingAsWeb($user)` pattern (`forgetGuards` +
  `shouldUse('web')`, see `ManagementUiTest`).
- Signed URLs in tests: `$this->get($invitation->acceptUrl ?? route('team-invitations.accept', $invitation))`
  — inside the app the URL is generated signed; tests may hit it without the
  signature problem by using the same URL the mail renders.

## Tests
New `tests/Feature/TeamInvitationAcceptanceTest.php`:
- invite + mail queued/sent (pins existing behavior), duplicate invite 422,
  invite existing member 422;
- accept: pivot attach + role, invitation row deleted, `current_team_id`
  updated per the fix;
- post-accept access: dashboard page reachable; API read (200) with role
  `Read Only`, API write (403) vs `admin` (200) — via `actingAsApi` tokens;
- wrong logged-in user accept → blocked, no membership change;
- stale invitation pruned on direct add + on accept;
- email case-insensitivity end-to-end.

Run: `php artisan test --filter TeamInvitationAcceptanceTest`, then full suite.

## Documentation requirements
- PHPDoc on the overridden action methods explaining the current-team fix.
- `server.md`: note the invitation acceptance fix (idea status blockquote).

## Implementation report
*(filled by the implementing agent — do not touch before starting work)*

### Blockers
- none

### Changes
- **Root cause of "can't access the team on their own"**: `AddTeamMember`
  (called by the vendor accept controller) only attaches the pivot — the new
  member's `current_team_id` stayed NULL (fresh account) or pointed at a
  stale team, so `effectiveTeam()` resolved to nothing/another team and every
  team-scoped page or API call missed. Fixed by the `SetCurrentTeamOnJoin`
  listener on `TeamMemberAdded` (registered in `EventServiceProvider`): a
  NULL or dangling pointer is repaired to the joined team; a pointer to a
  team the member still belongs to is untouched (switching is the user's
  move). Team deletion already nulls the pointer (vendor `removeUser`).
- **Wrong-user accept**: the vendor accept controller attaches the invited
  EMAIL's account regardless of who is logged in. First attempt was an app
  route override — impossible: Laravel keys the route collection by
  method+URI, so the vendor's boot()-registered route overwrote ours (proved
  by a stack trace). The guard now lives at the top of
  `AddTeamMember::add()`: the accept path is detectable because the vendor
  controller passes the OWNER as `$user` while the session user is the
  clicker — a session user differing from `$user` whose email doesn't match
  the invitation throws an `HttpResponseException` with a redirect +
  `dangerBanner`; nothing mutates and the invitation stays pending.
- **Stale invitations**: pruned in `AddTeamMember::add()` after a successful
  attach (case-insensitive, all rows for that address on that team) and on
  accept. A consumed/re-pruned link now fails with a clean 404 instead of a
  confusing "already belongs" validation error.
- **Email case**: `InviteTeamMember` lowercases before validate/store/mail;
  `AddTeamMember` looks accounts up with `whereRaw('lower(email) = ?')`
  (replacing `exists:users`, whose exact match failed on SQLite for
  case-mismatched addresses); `App\Teams::hasUserWithEmail` overridden to be
  case-insensitive (prevents case-variant invitations for existing members).
- Discovered + documented: with `Features::sendsTeamInvitations()` on (this
  app), `POST /teams/{team}/members` ALWAYS invites — even for registered
  users; there is no direct-add route. The `AddTeamMember` action is
  exercised by the accept path (and tested directly at the action level).

### Files touched
- app/Listeners/SetCurrentTeamOnJoin.php (new)
- app/Actions/Jetstream/AddTeamMember.php (guard + case-insensitive lookup + pruning)
- app/Actions/Jetstream/InviteTeamMember.php (lowercase)
- app/Models/Team.php (case-insensitive hasUserWithEmail)
- app/Providers/EventServiceProvider.php (listener mapping)
- tests/Feature/TeamInvitationAcceptanceTest.php (new, 12 tests)

### Tests run
```
php artisan test tests/Feature/TeamInvitationAcceptanceTest.php → 12 passed
php artisan test tests/Feature/InviteTeamMemberTest.php         → 2 passed (legacy behavior intact)
```

### Commits
- (session work — single batch with API-022..026, see final commit)

### Notes for reviewer
- The accept flow runs through the VENDOR controller (route untouched — an
  app override is impossible for a same-URI route, see above); all changes
  are in the app-side action/listener/models.
- Cross-account guard message: "This invitation was sent to X. Sign in with
  that account to accept it." (flash.bannerStyle = danger).
