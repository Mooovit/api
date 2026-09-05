<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithApi;
use Tests\TestCase;

class AuthenticateApiTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithApi;

    public function test_user_can_authenticate_and_receive_a_working_token(): void
    {
        [$user] = $this->newUserWithTeam(['password' => bcrypt('secret123')]);

        $response = $this->postJson('/api/authenticate', [
            'email' => $user->email,
            'password' => 'secret123',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email']]);

        /* The token is returned without its "<id>|" prefix but must authenticate */
        $this->getJson('/api/item', ['Authorization' => "Bearer {$response->json('token')}"])
            ->assertOk();
    }

    public function test_authentication_fails_with_bad_credentials(): void
    {
        [$user] = $this->newUserWithTeam(['password' => bcrypt('secret123')]);

        $this->postJson('/api/authenticate', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertStatus(401);
    }

    public function test_two_factor_users_are_rejected(): void
    {
        [$user] = $this->newUserWithTeam(['password' => bcrypt('secret123')]);
        $user->forceFill(['two_factor_secret' => 'not-empty'])->save();

        $this->postJson('/api/authenticate', [
            'email' => $user->email,
            'password' => 'secret123',
        ])->assertStatus(401);
    }

    public function test_register_creates_user_with_personal_team(): void
    {
        $this->postJson('/api/register', [
            'name' => 'New User',
            'email' => 'new@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'terms' => true,
        ])->assertOk();

        $user = User::where('email', 'new@example.com')->firstOrFail();
        $this->assertNotNull($user->ownedTeams()->first());
    }

    public function test_register_validates_input(): void
    {
        $this->postJson('/api/register', [
            'name' => 'New User',
            'email' => 'not-an-email',
            'password' => 'password',
            'password_confirmation' => 'different',
            'terms' => true,
        ])->assertStatus(422);
    }
}
