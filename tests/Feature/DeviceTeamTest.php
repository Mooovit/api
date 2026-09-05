<?php

namespace Tests\Feature;

use App\Http\Controllers\DeviceTokenController;
use App\Models\History;
use App\Models\Item;
use App\Models\Label;
use App\Models\Location;
use App\Models\Status;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithApi;
use Tests\TestCase;

/**
 * API-015: per-device (per-token) current team — the user's current team is
 * the default, a token may be switched to another team the user belongs to,
 * and every team-scoped read endpoint follows the token's effective team.
 */
class DeviceTeamTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithApi;

    /**
     * Add a team membership WITHOUT re-pointing the user's current_team_id
     * (unlike addTeamMember) — the second team must not become the default.
     */
    private function addMembership(User $user, Team $team, string $role = 'admin'): void
    {
        $team->users()->attach($user->id, ['role' => $role]);
    }

    /**
     * Mint a plain token string (secret part only). Forgets guards first —
     * the sanctum guard memoizes the resolved user, whose cached relations
     * (teams pivot) must never leak across requests.
     */
    private function mintToken(User $user, array $abilities = ['item:read']): string
    {
        $this->app['auth']->forgetGuards();

        return explode('|', $user->createToken('device-team-test', $abilities)->plainTextToken)[1];
    }

    /**
     * Use an existing token for the next request — forgets guards so each
     * request re-resolves the user (and the token) fresh.
     */
    private function asToken(string $token): self
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    public function test_default_is_the_user_current_team_and_teams_are_listed(): void
    {
        [$user, $teamA] = $this->newUserWithTeam();
        [$other, $teamB] = $this->newUserWithTeam();
        $this->addMembership($user, $teamB);

        $itemA = Item::factory()->onTeam($teamA)->create();
        Item::factory()->onTeam($teamB)->create();

        $token = $this->mintToken($user);

        $response = $this->asToken($token)
            ->getJson('/api/device-team')
            ->assertOk()
            ->assertJsonPath('current.id', $teamA->id)
            ->assertJsonPath('current.name', $teamA->name);

        $teamIds = array_column($response->json('teams'), 'id');
        $this->assertEqualsCanonicalizing([$teamA->id, $teamB->id], $teamIds);

        /* Scoped reads serve the user's default team */
        $list = $this->asToken($token)->getJson('/api/item')->assertOk();
        $this->assertSame([$itemA->id], array_column($list->json(), 'id'));
        $this->assertSame((string) $teamA->fresh()->revision, $list->headers->get('X-Revision'));
    }

    public function test_switch_moves_all_scoped_endpoints_to_the_new_team(): void
    {
        [$user, $teamA] = $this->newUserWithTeam();
        [$other, $teamB] = $this->newUserWithTeam();
        $this->addMembership($user, $teamB);

        $itemA = Item::factory()->onTeam($teamA)->create();
        $itemB = Item::factory()->onTeam($teamB)->create();
        History::factory()->forItem($itemA, $user)->create();
        History::factory()->forItem($itemB, $other)->create();
        Status::factory()->onTeam($teamA)->create();
        $statusB = Status::factory()->onTeam($teamB)->create();
        Location::factory()->onTeam($teamA)->create();
        $locationB = Location::factory()->onTeam($teamB)->create();
        Label::factory()->onTeam($teamA)->create();
        $labelB = Label::factory()->onTeam($teamB)->create();

        /* Full ability set — the test exercises status/location/label endpoints too */
        $token = $this->mintToken($user, ['item:read', 'status:read', 'location:read', 'item:write']);

        $this->asToken($token)
            ->postJson('/api/device-team', ['team_id' => $teamB->id])
            ->assertOk()
            ->assertJsonPath('current.id', $teamB->id);

        /* Item list + delta + revision header */
        $list = $this->asToken($token)->getJson('/api/item')->assertOk();
        $this->assertSame([$itemB->id], array_column($list->json(), 'id'));
        $this->assertSame((string) $teamB->fresh()->revision, $list->headers->get('X-Revision'));

        $since = urlencode(now()->subHour()->toIso8601String());
        $delta = $this->asToken($token)->getJson("/api/item?since={$since}")
            ->assertOk()
            ->json();
        $this->assertSame([$itemB->id], array_column($delta['changed'], 'id'));

        $this->asToken($token)->getJson('/api/revision')
            ->assertOk()
            ->assertJsonPath('revision', (int) $teamB->fresh()->revision);

        /* Activity feed — team B's history only */
        $activity = $this->asToken($token)->getJson('/api/activity')
            ->assertOk()
            ->json('data');
        $this->assertCount(1, $activity);
        $this->assertSame($itemB->id, $activity[0]['item_id']);

        /* Reference collections — team B's rows only */
        $this->asToken($token)->getJson('/api/status')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $statusB->id);
        $this->asToken($token)->getJson('/api/location')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $locationB->id);
        $this->asToken($token)->getJson('/api/labels')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $labelB->id);
    }

    public function test_reset_to_null_returns_to_user_default(): void
    {
        [$user, $teamA] = $this->newUserWithTeam();
        [$other, $teamB] = $this->newUserWithTeam();
        $this->addMembership($user, $teamB);

        $itemA = Item::factory()->onTeam($teamA)->create();
        $token = $this->mintToken($user);

        $this->asToken($token)
            ->postJson('/api/device-team', ['team_id' => $teamB->id])
            ->assertOk()
            ->assertJsonPath('current.id', $teamB->id);

        $this->asToken($token)
            ->postJson('/api/device-team', ['team_id' => null])
            ->assertOk()
            ->assertJsonPath('current.id', $teamA->id);

        $this->asToken($token)->getJson('/api/item')->assertOk()
            ->assertJsonPath('0.id', $itemA->id);
    }

    public function test_switch_to_non_member_team_422_and_leaves_token_unchanged(): void
    {
        [$user, $teamA] = $this->newUserWithTeam();
        [$stranger, $teamC] = $this->newUserWithTeam();

        $token = $this->mintToken($user);

        $this->asToken($token)
            ->postJson('/api/device-team', ['team_id' => $teamC->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('team_id');

        $this->asToken($token)->getJson('/api/device-team')
            ->assertOk()
            ->assertJsonPath('current.id', $teamA->id);
    }

    public function test_unknown_team_422(): void
    {
        [$user] = $this->newUserWithTeam();

        $this->asToken($this->mintToken($user))
            ->postJson('/api/device-team', ['team_id' => '00000000-0000-0000-0000-000000000000'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('team_id');
    }

    public function test_switching_neither_widens_nor_narrows_resource_authorization(): void
    {
        [$user, $teamA] = $this->newUserWithTeam();
        [$other, $teamB] = $this->newUserWithTeam();
        $this->addMembership($user, $teamB);
        [$stranger, $teamC] = $this->newUserWithTeam();

        $itemA = Item::factory()->onTeam($teamA)->create();
        $itemC = Item::factory()->onTeam($teamC)->create();

        $token = $this->mintToken($user);

        $this->asToken($token)
            ->postJson('/api/device-team', ['team_id' => $teamB->id])
            ->assertOk();

        /* The user is a member of both A and B — resource-team auth (API-001
           pattern) still allows addressing team A's item directly */
        $this->asToken($token)->getJson("/api/item/{$itemA->id}")->assertOk();

        /* A team the user does not belong to stays 403 — switching never
           widens authorization */
        $this->asToken($token)->getJson("/api/item/{$itemC->id}")->assertStatus(403);
    }

    public function test_setting_is_per_token(): void
    {
        [$user, $teamA] = $this->newUserWithTeam();
        [$other, $teamB] = $this->newUserWithTeam();
        $this->addMembership($user, $teamB);

        $itemA = Item::factory()->onTeam($teamA)->create();
        $itemB = Item::factory()->onTeam($teamB)->create();

        /* Token 1 switches to team B */
        $token1 = $this->mintToken($user);
        $this->asToken($token1)
            ->postJson('/api/device-team', ['team_id' => $teamB->id])
            ->assertOk();

        /* A second, fresh token of the same user keeps the default */
        $token2 = $this->mintToken($user);
        $this->asToken($token2)->getJson('/api/device-team')
            ->assertOk()
            ->assertJsonPath('current.id', $teamA->id);
        $this->asToken($token2)->getJson('/api/item')
            ->assertOk()
            ->assertJsonPath('0.id', $itemA->id);

        /* ...while the switched token still serves team B */
        $this->asToken($token1)->getJson('/api/item')
            ->assertOk()
            ->assertJsonPath('0.id', $itemB->id);
    }

    public function test_membership_loss_falls_back_to_user_default(): void
    {
        [$user, $teamA] = $this->newUserWithTeam();
        [$other, $teamB] = $this->newUserWithTeam();
        $this->addMembership($user, $teamB);
        $itemA = Item::factory()->onTeam($teamA)->create();

        $token = $this->mintToken($user);

        $this->asToken($token)
            ->postJson('/api/device-team', ['team_id' => $teamB->id])
            ->assertOk();

        /* The user loses team B membership — the stored override must no
           longer serve team B's data */
        $teamB->users()->detach($user->id);

        $this->asToken($token)->getJson('/api/item')
            ->assertOk()
            ->assertJsonPath('0.id', $itemA->id);
        $this->asToken($token)->getJson('/api/device-team')
            ->assertOk()
            ->assertJsonPath('current.id', $teamA->id);
    }

    public function test_device_token_can_fetch_and_switch(): void
    {
        [$user, $teamA] = $this->newUserWithTeam();
        [$other, $teamB] = $this->newUserWithTeam();
        $this->addMembership($user, $teamB);
        $itemB = Item::factory()->onTeam($teamB)->create();

        /* A restricted API-012 device token may list teams and switch itself */
        $deviceToken = $this->mintToken($user, DeviceTokenController::DEVICE_ABILITIES);

        $this->asToken($deviceToken)->getJson('/api/device-team')
            ->assertOk()
            ->assertJsonPath('current.id', $teamA->id);

        $this->asToken($deviceToken)
            ->postJson('/api/device-team', ['team_id' => $teamB->id])
            ->assertOk()
            ->assertJsonPath('current.id', $teamB->id);

        $this->asToken($deviceToken)->getJson('/api/item')
            ->assertOk()
            ->assertJsonPath('0.id', $itemB->id);
    }

    public function test_switch_does_not_bump_team_revisions(): void
    {
        [$user, $teamA] = $this->newUserWithTeam();
        [$other, $teamB] = $this->newUserWithTeam();
        $this->addMembership($user, $teamB);

        $revisionA = $teamA->fresh()->revision;
        $revisionB = $teamB->fresh()->revision;

        $token = $this->mintToken($user);

        $this->asToken($token)
            ->postJson('/api/device-team', ['team_id' => $teamB->id])
            ->assertOk();
        $this->asToken($token)
            ->postJson('/api/device-team', ['team_id' => null])
            ->assertOk();

        /* The switch changes no team data */
        $this->assertSame($revisionA, $teamA->fresh()->revision);
        $this->assertSame($revisionB, $teamB->fresh()->revision);
    }

    public function test_unauthenticated_401(): void
    {
        $this->getJson('/api/device-team')->assertStatus(401);
        $this->postJson('/api/device-team', ['team_id' => null])->assertStatus(401);
    }
}
