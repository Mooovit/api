<?php

namespace Tests\Feature;

use App\Http\Controllers\DeviceTokenController;
use App\Models\Item;
use App\Models\Location;
use App\Models\Status;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Concerns\InteractsWithApi;
use Tests\TestCase;

/**
 * API-012: named, restricted, revocable device tokens for the PDA fleet.
 */
class DeviceTokenTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithApi;

    /**
     * Mint a device token through the API and return the creation payload.
     */
    private function mintDeviceToken(User $user, string $name): array
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAsApi($user, ['*'])
            ->postJson('/api/device-tokens', ['name' => $name])
            ->assertStatus(201)
            ->json();
    }

    public function test_create_mints_named_token_with_restricted_abilities(): void
    {
        [$user] = $this->newUserWithTeam();

        $created = $this->mintDeviceToken($user, 'Bluebird #3');

        $this->assertSame('Bluebird #3', $created['name']);
        $this->assertNotNull($created['id']);
        $this->assertNotNull($created['token']);

        /* Exactly the warehouse set — nothing account-level */
        $this->assertSame(
            DeviceTokenController::DEVICE_ABILITIES,
            PersonalAccessToken::find($created['id'])->abilities
        );
    }

    public function test_list_shows_metadata_never_the_secret(): void
    {
        [$user] = $this->newUserWithTeam();
        $created = $this->mintDeviceToken($user, 'Scanner A');
        $user->createToken('Web session', ['*']);

        $response = $this->actingAsApi($user, ['*'])
            ->getJson('/api/device-tokens')
            ->assertOk();

        $rows = $response->json();
        /* Scanner A + Web session + the two transport 'test-token's */
        $this->assertCount(4, $rows);

        $row = collect($rows)->firstWhere('name', 'Scanner A');
        $this->assertSame($created['id'], $row['id']);
        $this->assertArrayHasKey('last_used_at', $row);
        $this->assertArrayHasKey('created_at', $row);

        foreach ($rows as $row) {
            $this->assertArrayNotHasKey('token', $row);
            $this->assertArrayNotHasKey('abilities', $row);
        }
        $this->assertStringNotContainsString($created['token'], $response->getContent());
    }

    public function test_using_a_token_updates_last_used_at(): void
    {
        [$user] = $this->newUserWithTeam();
        $created = $this->mintDeviceToken($user, 'Scanner B');
        $this->assertNull(PersonalAccessToken::find($created['id'])->last_used_at);

        /* Native Sanctum behavior: each authenticated use stamps the row */
        $this->app['auth']->forgetGuards();
        $this->withToken($created['token'])->getJson('/api/user')->assertOk();

        $row = collect($this->actingAsApi($user, ['*'])
            ->getJson('/api/device-tokens')
            ->assertOk()
            ->json())->firstWhere('id', $created['id']);

        $this->assertNotNull($row['last_used_at']);
    }

    public function test_device_token_drives_item_routes_and_echoes_abilities(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $device = $this->mintDeviceToken($user, 'Bluebird #4')['token'];

        /* GET api/user reflects the restricted set for capability rendering */
        $this->app['auth']->forgetGuards();
        $me = $this->withToken($device)->getJson('/api/user')->assertOk()->json();
        $this->assertSame(DeviceTokenController::DEVICE_ABILITIES, $me['tokenPermissions']);

        /* Full item workflow with the device token alone */
        $location = Location::factory()->onTeam($team)->create();
        $status = Status::factory()->onTeam($team)->create();

        $this->app['auth']->forgetGuards();
        $this->withToken($device)->postJson('/api/item', [
            'name' => 'Box',
            'team_id' => $team->id,
            'location_id' => $location->id,
            'status_id' => $status->id,
        ])->assertStatus(201);

        $this->app['auth']->forgetGuards();
        $this->withToken($device)->getJson('/api/item')->assertOk();
    }

    public function test_revoke_then_401_while_the_password_keeps_working(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $user->forceFill(['password' => bcrypt('super-secret')])->save();
        $created = $this->mintDeviceToken($user, 'Lost PDA');
        $device = $created['token'];

        $this->app['auth']->forgetGuards();
        $this->withToken($device)->getJson('/api/user')->assertOk();

        /* Revoke from the owner's main token */
        $this->actingAsApi($user, ['*'])
            ->deleteJson('/api/device-tokens/' . $created['id'])
            ->assertOk()
            ->assertJsonPath('success', true);

        /* The device token is dead everywhere */
        $this->app['auth']->forgetGuards();
        $this->withToken($device)->getJson('/api/user')->assertStatus(401);

        /* The human's password still mints a fresh token (legacy flow
           unchanged: whole `token` string, split quirk and all).
           shouldUse('web') first: earlier sanctum requests in this test
           process re-pointed the default guard (framework auth middleware
           calls Auth::shouldUse — production requests boot fresh managers,
           so the legacy endpoint is unaffected there). */
        $this->app['auth']->shouldUse('web');
        $auth = $this->postJson('/api/authenticate', [
            'email' => $user->email,
            'password' => 'super-secret',
        ])->assertOk();
        $this->assertNotNull($auth->json('token'));
        $this->assertSame($user->id, $auth->json('user.id'));
    }

    public function test_cannot_revoke_another_users_token(): void
    {
        [$user] = $this->newUserWithTeam();
        [$stranger] = $this->newUserWithTeam();
        $foreign = $stranger->createToken('foreign', ['*'])->accessToken;

        $this->actingAsApi($user, ['*'])
            ->deleteJson('/api/device-tokens/' . $foreign->id)
            ->assertStatus(404);

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $foreign->id]);
    }

    public function test_validation(): void
    {
        [$user] = $this->newUserWithTeam();

        $this->actingAsApi($user, ['*'])
            ->postJson('/api/device-tokens', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);

        $this->actingAsApi($user, ['*'])
            ->postJson('/api/device-tokens', ['name' => str_repeat('x', 192)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/device-tokens')->assertStatus(401);
        $this->postJson('/api/device-tokens', ['name' => 'X'])->assertStatus(401);
    }
}
