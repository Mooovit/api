<?php

namespace Tests\Feature;

use App\Http\Controllers\DeviceTokenController;
use App\Models\EnrollmentCode;
use App\Models\Item;
use App\Models\Location;
use App\Models\Status;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Concerns\InteractsWithApi;
use Tests\TestCase;

/**
 * The management UI, user-level half (session-auth web routes): the
 * dashboard overview (team stats, latest items, savepoint stack state)
 * and the devices page (token mint with the plain text shown exactly
 * once, fleet list, revoke, enrollment codes).
 */
class ManagementUiTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithApi;

    /**
     * Session authentication for the web routes, safe across user switches
     * (same lesson as BackupScheduleTest: reset the default driver to `web`
     * so every actingAs lands on the session guard with its TransientToken).
     */
    private function actingAsWeb($user): self
    {
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');

        return $this->actingAs($user);
    }

    public function test_dashboard_shows_team_overview(): void
    {
        [$user, $team] = $this->newUserWithTeam();

        $status = Status::factory()->onTeam($team)->create(['name' => 'In stock']);
        $location = Location::factory()->onTeam($team)->create(['name' => 'Shelf A']);
        $item = Item::factory()->onTeam($team)->create([
            'name' => 'Pallet jack',
            'status_id' => $status->id,
            'location_id' => $location->id,
        ]);
        \App\Models\History::factory()->forItem($item, $user)->create([
            'changed_at' => now()->subHour(),
        ]);

        /* Foreign team data must not leak into the overview */
        [$foreign, $foreignTeam] = $this->newUserWithTeam();
        Item::factory()->onTeam($foreignTeam)->create(['name' => 'Foreign item']);

        $this->actingAsWeb($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(function ($page) use ($team, $item) {
                $page->component('Dashboard')
                    ->where('team.id', $team->id)
                    ->where('revision', $team->fresh()->revision)
                    ->where('counts.items', 1)
                    ->where('counts.locations', 1)
                    ->where('counts.statuses', 1)
                    ->where('counts.labels', 0)
                    ->where('items.0.name', 'Pallet jack')
                    ->where('items.0.status', 'In stock')
                    ->where('items.0.location', 'Shelf A')
                    ->has('last_activity_at')
                    ->missing('last_backup.id')
                    ->where('schedule', null)
                    ->where('s3_configured', false);
            });

        $content = $this->get('/dashboard')->getContent();
        $this->assertStringNotContainsString('Foreign item', $content);
    }

    public function test_dashboard_requires_authentication(): void
    {
        $this->get('/dashboard')->assertRedirect(route('login'));
    }

    public function test_devices_page_lists_tokens_and_active_codes(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $user->createToken('Bluebird #3', ['item:read']);
        $user->createToken('Scanner 1', ['item:read']);

        EnrollmentCode::create([
            'code' => 'ACTIVE123',
            'user_id' => $user->id,
            'expires_at' => now()->addMinutes(5),
        ]);
        EnrollmentCode::create([
            'code' => 'OLDCODE1',
            'user_id' => $user->id,
            'expires_at' => now()->subMinutes(5),
        ]);

        $this->actingAsWeb($user)
            ->get('/devices')
            ->assertOk()
            ->assertInertia(function ($page) {
                $page->component('Devices/Index')
                    ->where('tokens.0.name', 'Scanner 1')
                    ->where('tokens.1.name', 'Bluebird #3')
                    ->where('tokens.0.team', null)
                    ->has('tokens.0.last_used_at')
                    ->where('codes.0.code', 'ACTIVE123')
                    ->has('codes', 1);
            });

        /* The token list never carries secrets */
        $this->assertStringNotContainsString(
            'plainText',
            $this->get('/devices')->getContent()
        );
    }

    public function test_mint_device_token_flashes_plain_text_once(): void
    {
        [$user, $team] = $this->newUserWithTeam();

        $this->actingAsWeb($user)
            ->post('/devices/tokens', ['name' => 'Scanner 1'])
            ->assertRedirect('/devices');

        /* The flash carries the plain text secret — read it before any
           further request ages it away */
        $plain = session('device_token');
        $this->assertIsString($plain);
        $this->assertStringContainsString('|', $plain);

        /* Validations still apply */
        $this->actingAsWeb($user)
            ->post('/devices/tokens', [])
            ->assertSessionHasErrors('name');

        $token = PersonalAccessToken::where('name', 'Scanner 1')->first();
        $this->assertNotNull($token);
        $this->assertSame(
            DeviceTokenController::DEVICE_ABILITIES,
            $token->abilities
        );

        /* Rendering the page consumed the flash: shown exactly once */
        $this->actingAsWeb($user)->get('/devices')->assertOk();
        $this->assertNull(session('device_token'));
    }

    public function test_revoke_removes_only_own_token(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        [$other] = $this->newUserWithTeam();

        $mine = $user->createToken('mine', ['item:read'])->accessToken->id;
        $theirs = $other->createToken('theirs', ['item:read'])->accessToken->id;

        /* Another user's token id is simply not found */
        $this->actingAsWeb($user)
            ->delete("/devices/tokens/{$theirs}")
            ->assertNotFound();
        $this->assertNotNull(PersonalAccessToken::find($theirs));

        $this->actingAsWeb($user)
            ->delete("/devices/tokens/{$mine}")
            ->assertRedirect('/devices');
        $this->assertNull(PersonalAccessToken::find($mine));
    }

    public function test_revoke_all_clears_the_fleet_but_only_mine(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        [$other] = $this->newUserWithTeam();

        $user->createToken('Scanner 1', ['item:read']);
        $user->createToken('Scanner 2', ['item:read']);
        $other->createToken('theirs', ['item:read']);

        $this->actingAsWeb($user)
            ->delete('/devices/tokens')
            ->assertRedirect('/devices');

        $this->assertSame(0, $user->tokens()->count());
        $this->assertSame(1, $other->tokens()->count());
    }

    public function test_token_team_can_be_switched_and_reset(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $tokenId = $user->createToken('Scanner 1', ['item:read'])->accessToken->id;

        /* A second team the user joins */
        [$mate, $second] = $this->newUserWithTeam();
        $this->addTeamMember($user, $second, 'admin');

        /* Re-point the token at the second team */
        $this->actingAsWeb($user)
            ->post("/devices/tokens/{$tokenId}/team", ['team_id' => $second->id])
            ->assertRedirect('/devices');
        $this->assertSame($second->id, PersonalAccessToken::find($tokenId)->current_team_id);

        /* Reset → follow the user's default (null) */
        $this->actingAsWeb($user)
            ->post("/devices/tokens/{$tokenId}/team", [])
            ->assertRedirect('/devices');
        $this->assertNull(PersonalAccessToken::find($tokenId)->current_team_id);

        /* A team the user does NOT belong to is rejected */
        [$stranger, $foreignTeam] = $this->newUserWithTeam();
        $this->actingAsWeb($user)
            ->post("/devices/tokens/{$tokenId}/team", ['team_id' => $foreignTeam->id])
            ->assertSessionHasErrors('team_id');
        $this->assertNull(PersonalAccessToken::find($tokenId)->current_team_id);

        /* Another user's token is simply not found */
        $theirsId = $stranger->createToken('theirs', ['item:read'])->accessToken->id;
        $this->actingAsWeb($user)
            ->post("/devices/tokens/{$theirsId}/team", ['team_id' => $team->id])
            ->assertNotFound();
    }

    public function test_mint_enrollment_code_prunes_stale_and_flashes_new(): void
    {
        Carbon::setTestNow('2026-09-05 10:00:00');
        [$user, $team] = $this->newUserWithTeam();

        $expired = EnrollmentCode::create([
            'code' => 'EXPIRED1',
            'user_id' => $user->id,
            'expires_at' => now()->subMinute(),
        ]);
        $used = EnrollmentCode::create([
            'code' => 'USEDCODE',
            'user_id' => $user->id,
            'expires_at' => now()->addMinute(),
            'used_at' => now()->subMinute(),
        ]);
        /* Another issuer's stale rows are theirs to prune */
        [$foreign] = $this->newUserWithTeam();
        $foreignCode = EnrollmentCode::create([
            'code' => 'FOREIGN1',
            'user_id' => $foreign->id,
            'expires_at' => now()->subMinute(),
        ]);

        $this->actingAsWeb($user)
            ->post('/devices/enrollment-codes')
            ->assertRedirect('/devices');

        $this->assertNull(EnrollmentCode::find($expired->id));
        $this->assertNull(EnrollmentCode::find($used->id));
        $this->assertNotNull(EnrollmentCode::find($foreignCode->id));

        $active = EnrollmentCode::where('user_id', $user->id)->first();
        $this->assertNotNull($active);
        $this->assertSame(8, strlen($active->code));
        $this->assertSame('2026-09-05 10:15:00', $active->expires_at->format('Y-m-d H:i:s'));

        $flashed = session('device_code');
        $this->assertSame($active->code, $flashed['code']);
        $this->assertSame($active->expires_at->toISOString(), $flashed['expires_at']);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/devices')->assertRedirect(route('login'));
        $this->post('/devices/tokens', ['name' => 'X'])->assertRedirect(route('login'));
    }
}
