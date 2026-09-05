<?php

namespace Tests\Feature;

use App\Http\Controllers\DeviceTokenController;
use App\Models\EnrollmentCode;
use App\Models\Location;
use App\Models\Status;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Concerns\InteractsWithApi;
use Tests\TestCase;

/**
 * API-017: one-time, short-lived enrollment codes — a new PDA is enrolled by
 * scanning a code minted by an already-authenticated device/user; the owner's
 * password never touches the new device.
 */
class EnrollmentCodeTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithApi;

    /**
     * Mint an enrollment code through the API as $user.
     */
    private function mintCode(\App\Models\User $user): array
    {
        return $this->actingAsApi($user, ['*'])
            ->postJson('/api/enrollment-codes')
            ->assertStatus(201)
            ->json();
    }

    /**
     * Drop the memoized guard + leftover headers so the next request is truly
     * unauthenticated (RequestGuard caches both the user and the request).
     */
    private function asAnonymous(): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
    }

    public function test_mint_enroll_round_trip_yields_a_working_device_token(): void
    {
        [$user, $team] = $this->newUserWithTeam();

        $minted = $this->mintCode($user);

        /* 8 chars from the unambiguous alphabet (no 0/O/1/I/L) */
        $this->assertMatchesRegularExpression('/^[A-HJ-KM-NP-Z2-9]{8}$/', $minted['code']);

        /* expires_at ≈ now + 15 minutes, as documented */
        $expiresAt = Carbon::parse($minted['expires_at']);
        $this->assertLessThan(10, now()->addMinutes(15)->diffInSeconds($expiresAt));

        $this->asAnonymous();
        $enrolled = $this->postJson('/api/enroll', [
            'code' => $minted['code'],
            'name' => 'Bluebird #7',
        ])->assertStatus(201)->json();

        $this->assertSame('Bluebird #7', $enrolled['name']);
        $this->assertNotNull($enrolled['id']);
        $this->assertNotNull($enrolled['token']);

        /* The enrolled token belongs to the ISSUER and carries exactly the
           device ability set (echoed by /api/user for capability rendering) */
        $this->asAnonymous();
        $me = $this->withToken($enrolled['token'])->getJson('/api/user')->assertOk()->json();
        $this->assertSame(DeviceTokenController::DEVICE_ABILITIES, $me['tokenPermissions']);
        $this->assertSame($user->id, $me['user']['id']);

        /* The token drives item routes alone: write then read */
        $location = Location::factory()->onTeam($team)->create();
        $status = Status::factory()->onTeam($team)->create();

        $this->app['auth']->forgetGuards();
        $this->withToken($enrolled['token'])->postJson('/api/item', [
            'name' => 'Box',
            'team_id' => $team->id,
            'location_id' => $location->id,
            'status_id' => $status->id,
        ])->assertStatus(201);

        $this->app['auth']->forgetGuards();
        $this->withToken($enrolled['token'])->getJson('/api/item')->assertOk();

        /* Listed in the issuer's fleet with the chosen name */
        $fleet = collect($this->actingAsApi($user, ['*'])
            ->getJson('/api/device-tokens')
            ->assertOk()
            ->json());
        $row = $fleet->firstWhere('id', $enrolled['id']);
        $this->assertNotNull($row);
        $this->assertSame('Bluebird #7', $row['name']);

        /* The code row is claimed: used_at + used_by_token_id */
        $code = EnrollmentCode::where('code', $minted['code'])->first();
        $this->assertNotNull($code->used_at);
        $this->assertSame($enrolled['id'], $code->used_by_token_id);
    }

    public function test_code_is_single_use_and_the_first_token_survives(): void
    {
        [$user] = $this->newUserWithTeam();
        $minted = $this->mintCode($user);

        $this->asAnonymous();
        $first = $this->postJson('/api/enroll', [
            'code' => $minted['code'],
            'name' => 'PDA 1',
        ])->assertStatus(201)->json();

        /* A second redeemer loses: 410 Gone (pinned) */
        $this->asAnonymous();
        $this->postJson('/api/enroll', [
            'code' => $minted['code'],
            'name' => 'PDA 2',
        ])->assertStatus(410);

        /* Exactly one token exists, owned by the issuer */
        $this->assertSame(1, PersonalAccessToken::where('name', 'PDA 1')->count());
        $this->assertSame(0, PersonalAccessToken::where('name', 'PDA 2')->count());
        $this->assertSame($user->id, PersonalAccessToken::find($first['id'])->tokenable_id);

        /* The first token keeps working */
        $this->app['auth']->forgetGuards();
        $this->withToken($first['token'])->getJson('/api/user')->assertOk();
    }

    public function test_expired_code_is_rejected_422(): void
    {
        [$user] = $this->newUserWithTeam();
        $minted = $this->mintCode($user);
        $expiresAt = Carbon::parse($minted['expires_at']);

        /* One second past the expiry parsed from the MINT RESPONSE, the code
           is dead — the client's clock/`expires_at` value is authoritative */
        $this->travelTo($expiresAt->copy()->addSecond());

        $this->asAnonymous();
        $this->postJson('/api/enroll', [
            'code' => $minted['code'],
            'name' => 'Late PDA',
        ])->assertStatus(422)->assertJsonValidationErrors(['code']);

        Carbon::setTestNow();
    }

    public function test_minting_prunes_only_the_issuers_stale_codes(): void
    {
        [$user] = $this->newUserWithTeam();
        [$other] = $this->newUserWithTeam();

        $expired = EnrollmentCode::create([
            'code' => 'EXPIRED1',
            'user_id' => $user->id,
            'expires_at' => now()->subHour(),
        ]);
        $used = EnrollmentCode::create([
            'code' => 'USEDONE1',
            'user_id' => $user->id,
            'expires_at' => now()->addHour(),
            'used_at' => now()->subMinute(),
        ]);
        $fresh = EnrollmentCode::create([
            'code' => 'FRESH123',
            'user_id' => $user->id,
            'expires_at' => now()->addHour(),
        ]);
        $otherStale = EnrollmentCode::create([
            'code' => 'OTHER001',
            'user_id' => $other->id,
            'expires_at' => now()->subHour(),
        ]);

        /* Mint time is cleanup time — for the issuer only */
        $this->mintCode($user);

        $this->assertDatabaseMissing('enrollment_codes', ['id' => $expired->id]);
        $this->assertDatabaseMissing('enrollment_codes', ['id' => $used->id]);
        $this->assertDatabaseHas('enrollment_codes', ['id' => $fresh->id]);
        $this->assertDatabaseHas('enrollment_codes', ['id' => $otherStale->id]);
    }

    public function test_enroll_validation_errors(): void
    {
        $this->postJson('/api/enroll', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code', 'name']);

        /* Unknown code */
        $this->postJson('/api/enroll', ['code' => 'ZZZZ9999', 'name' => 'X'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code']);

        /* Malformed code (never issued by the alphabet) */
        $this->postJson('/api/enroll', ['code' => '!!', 'name' => 'X'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code']);

        /* Blank name */
        $this->postJson('/api/enroll', ['code' => 'AAAAAAAA', 'name' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_enrollment_mint_requires_authentication(): void
    {
        $this->postJson('/api/enrollment-codes')->assertStatus(401);
    }
}
