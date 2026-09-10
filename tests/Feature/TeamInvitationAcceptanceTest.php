<?php

namespace Tests\Feature;

use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Laravel\Jetstream\Mail\TeamInvitation as TeamInvitationMail;
use Tests\Concerns\InteractsWithApi;
use Tests\TestCase;

/**
 * API-023 — the invite → accept → access flow, end to end.
 *
 * Jetstream's accept side was never tested and had real defects: the new
 * member kept a NULL/stale current team (they could not "access the team on
 * their own"), any logged-in user could consume the signed link, stale
 * invitations survived the member joining, and email case broke matching.
 */
class TeamInvitationAcceptanceTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithApi;

    /**
     * Session authentication for the web routes, safe across user switches
     * (same pattern as ManagementUiTest).
     */
    private function actingAsWeb($user): self
    {
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');

        return $this->actingAs($user);
    }

    private function invite($team, string $email, string $role = 'admin'): TeamInvitation
    {
        return $team->teamInvitations()->create([
            'email' => strtolower($email),
            'role' => $role,
        ]);
    }

    private function acceptUrl(TeamInvitation $invitation): string
    {
        return URL::signedRoute('team-invitations.accept', ['invitation' => $invitation->id]);
    }

    public function test_invite_is_stored_lowercase_and_mailed(): void
    {
        Mail::fake();

        [$owner, $team] = $this->newUserWithTeam();

        $this->actingAsWeb($owner)
            ->post("/teams/{$team->id}/members", [
                'email' => 'Mixed@Example.COM',
                'role' => 'admin',
            ]);

        $invitation = TeamInvitation::first();
        $this->assertNotNull($invitation);
        $this->assertSame('mixed@example.com', $invitation->email);
        $this->assertSame('admin', $invitation->role);
        Mail::assertSent(TeamInvitationMail::class);
    }

    public function test_duplicate_invite_is_rejected_case_insensitively(): void
    {
        Mail::fake();

        [$owner, $team] = $this->newUserWithTeam();
        $this->invite($team, 'Foo@X.com');

        /* No account exists with this address → the controller takes the
           invite path → the (now lowercased) unique rule fires */
        $this->actingAsWeb($owner)
            ->post("/teams/{$team->id}/members", [
                'email' => 'FOO@x.com',
                'role' => 'admin',
            ]);

        $this->assertCount(1, $team->fresh()->teamInvitations);
    }

    public function test_inviting_an_existing_member_is_rejected_case_insensitively(): void
    {
        Mail::fake();

        [$owner, $team] = $this->newUserWithTeam();
        $member = User::factory()->create(['email' => 'Member@X.com']);
        $this->addTeamMember($member, $team, 'admin');

        /* Case variant of a member's address must not slip into a pending
           invitation (the exact-match default would let it) */
        $this->actingAsWeb($owner)
            ->post("/teams/{$team->id}/members", [
                'email' => 'member@x.com',
                'role' => 'admin',
            ]);

        $this->assertCount(0, $team->fresh()->teamInvitations);
        $this->assertSame(1, $team->fresh()->users()->count());
    }

    public function test_accept_attaches_membership_role_and_current_team(): void
    {
        [$owner, $team] = $this->newUserWithTeam();
        $invitee = User::factory()->create();
        $this->assertNull($invitee->current_team_id);

        $invitation = $this->invite($team, $invitee->email, 'Read Only');

        $this->actingAsWeb($invitee)
            ->get($this->acceptUrl($invitation))
            ->assertRedirect('/dashboard')
            ->assertSessionHas('flash.banner');

        $this->assertDatabaseHas('team_user', [
            'team_id' => $team->id,
            'user_id' => $invitee->id,
            'role' => 'Read Only',
        ]);
        $this->assertNull(TeamInvitation::find($invitation->id));

        /* The invited member lands on the team (was NULL) */
        $this->assertSame($team->id, $invitee->fresh()->current_team_id);
    }

    public function test_accepted_viewer_reads_but_cannot_write_via_api(): void
    {
        [$owner, $team] = $this->newUserWithTeam();
        $invitee = User::factory()->create();
        $invitation = $this->invite($team, $invitee->email, 'Read Only');

        $this->actingAsWeb($invitee)->get($this->acceptUrl($invitation));

        $this->actingAsApi($invitee, ['status:read'])
            ->getJson('/api/status')
            ->assertOk();

        $this->actingAsApi($invitee, ['status:write'])
            ->postJson('/api/status', ['name' => 'X', 'team_id' => $team->id])
            ->assertStatus(403);
    }

    public function test_accepted_admin_can_write_via_api(): void
    {
        [$owner, $team] = $this->newUserWithTeam();
        $invitee = User::factory()->create();
        $invitation = $this->invite($team, $invitee->email, 'admin');

        $this->actingAsWeb($invitee)->get($this->acceptUrl($invitation));

        $this->actingAsApi($invitee, ['status:read', 'status:write'])
            ->postJson('/api/status', ['name' => 'Accepted', 'team_id' => $team->id])
            ->assertStatus(201);
    }

    public function test_accept_by_another_logged_in_user_is_blocked(): void
    {
        [$owner, $team] = $this->newUserWithTeam();
        $invitee = User::factory()->create();
        $stranger = User::factory()->create();
        $invitation = $this->invite($team, $invitee->email);

        $this->actingAsWeb($stranger)
            ->get($this->acceptUrl($invitation))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('flash.bannerStyle', 'danger');

        /* No membership, no invitation consumed, no team switch */
        $this->assertDatabaseMissing('team_user', ['team_id' => $team->id]);
        $this->assertNotNull(TeamInvitation::find($invitation->id));
        $this->assertNull($stranger->fresh()->current_team_id);
    }

    public function test_accept_matches_the_account_case_insensitively(): void
    {
        [$owner, $team] = $this->newUserWithTeam();
        $invitee = User::factory()->create(['email' => 'Foo@X.com']);
        $invitation = $this->invite($team, 'foo@x.com', 'admin');

        $this->actingAsWeb($invitee)
            ->get($this->acceptUrl($invitation))
            ->assertRedirect('/dashboard');

        $this->assertDatabaseHas('team_user', [
            'team_id' => $team->id,
            'user_id' => $invitee->id,
        ]);
    }

    public function test_direct_add_prunes_the_stale_invitation_and_repairs_team(): void
    {
        [$owner, $team] = $this->newUserWithTeam();
        $invitee = User::factory()->create();
        $invitation = $this->invite($team, $invitee->email);

        /* The AddTeamMember action is Jetstream's other entry point (the
           accept controller uses it; the manager form invites when
           invitations are enabled). It must prune the stale invitation and
           land the member on the team. */
        app(\Laravel\Jetstream\Contracts\AddsTeamMembers::class)->add(
            $owner, $team, $invitee->email, 'admin'
        );

        $this->assertDatabaseHas('team_user', [
            'team_id' => $team->id,
            'user_id' => $invitee->id,
            'role' => 'admin',
        ]);
        $this->assertNull(TeamInvitation::find($invitation->id));
        $this->assertSame($team->id, $invitee->fresh()->current_team_id);
    }

    public function test_second_accept_attempt_is_a_clean_404(): void
    {
        [$owner, $team] = $this->newUserWithTeam();
        $invitee = User::factory()->create();
        $invitation = $this->invite($team, $invitee->email);

        $url = $this->acceptUrl($invitation);
        $this->actingAsWeb($invitee)->get($url)->assertRedirect('/dashboard');

        /* The consumed link is simply gone — no confusing "already belongs" */
        $this->actingAsWeb($invitee)->get($url)->assertNotFound();
    }

    public function test_member_keeping_another_team_is_not_repointed(): void
    {
        [$owner, $team] = $this->newUserWithTeam();
        $invitee = User::factory()->withPersonalTeam()->create();
        $ownTeam = $invitee->fresh()->currentTeam;
        $invitation = $this->invite($team, $invitee->email);

        $this->actingAsWeb($invitee)->get($this->acceptUrl($invitation));

        /* They still belong to their own team — switching stays their move */
        $this->assertSame($ownTeam->id, $invitee->fresh()->current_team_id);
    }

    public function test_dangling_current_team_is_repaired_on_join(): void
    {
        [$owner, $team] = $this->newUserWithTeam();
        [$stranger, $staleTeam] = $this->newUserWithTeam();

        $invitee = User::factory()->create();
        /* Point them at a team they never belonged to (left-team artifact) */
        $invitee->forceFill(['current_team_id' => $staleTeam->id])->save();

        $invitation = $this->invite($team, $invitee->email);
        $this->actingAsWeb($invitee)->get($this->acceptUrl($invitation));

        $this->assertSame($team->id, $invitee->fresh()->current_team_id);
    }
}
