<?php

namespace Tests\Feature;

use App\Models\Status;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithApi;
use Tests\TestCase;

class StatusApiTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithApi;

    public function test_index_returns_team_scoped_statuses_with_timestamps(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        [$stranger] = $this->newUserWithTeam();

        Status::factory()->onTeam($team)->count(2)->create();
        Status::factory()->onTeam($stranger->ownedTeams()->first())->create();

        $this->actingAsApi($user, ['status:read'])
            ->getJson('/api/status')
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonStructure([['id', 'name', 'team_id', 'created_at', 'updated_at']]);
    }

    public function test_store_creates_status(): void
    {
        [$user, $team] = $this->newUserWithTeam();

        $this->actingAsApi($user, ['status:write'])
            ->postJson('/api/status', ['name' => 'In transit', 'team_id' => $team->id])
            ->assertStatus(201)
            ->assertJsonPath('name', 'In transit');

        $this->assertDatabaseHas('statuses', ['name' => 'In transit', 'team_id' => $team->id]);
    }

    public function test_store_validates_but_drops_position(): void
    {
        [$user, $team] = $this->newUserWithTeam();

        /* KNOWN QUIRK (pinned): `position` is validated but never persisted by
           StatusController::store. If you fix this, update this test consciously. */
        $this->actingAsApi($user, ['status:write'])
            ->postJson('/api/status', ['name' => 'Stacked', 'team_id' => $team->id, 'position' => 5])
            ->assertStatus(201);

        $this->assertDatabaseHas('statuses', ['name' => 'Stacked', 'position' => null]);
    }

    public function test_store_requires_status_write_ability(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $viewer = $this->addTeamMember(User::factory()->create(), $team, 'Read Only');

        $this->actingAsApi($viewer, ['status:read', 'status:write'])
            ->postJson('/api/status', ['name' => 'X', 'team_id' => $team->id])
            ->assertStatus(403);
    }

    public function test_show_rejects_statuses_from_another_team(): void
    {
        [$user] = $this->newUserWithTeam();
        [$stranger] = $this->newUserWithTeam();
        $foreign = Status::factory()->onTeam($stranger->ownedTeams()->first())->create();

        $this->actingAsApi($user, ['status:read'])
            ->getJson("/api/status/{$foreign->id}")
            ->assertStatus(403);
    }

    public function test_update_requires_name(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $status = Status::factory()->onTeam($team)->create(['name' => 'Old']);

        $this->actingAsApi($user, ['status:write'])
            ->patchJson("/api/status/{$status->id}", [])
            ->assertStatus(422);

        $this->actingAsApi($user, ['status:write'])
            ->patchJson("/api/status/{$status->id}", ['name' => 'New'])
            ->assertOk()
            ->assertJsonPath('name', 'New');
    }

    public function test_destroy_returns_success(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $status = Status::factory()->onTeam($team)->create();

        $this->actingAsApi($user, ['status:write'])
            ->deleteJson("/api/status/{$status->id}")
            ->assertOk()
            ->assertJsonPath('success', 'success');

        $this->assertDatabaseMissing('statuses', ['id' => $status->id]);
    }
}
