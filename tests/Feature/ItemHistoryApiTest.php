<?php

namespace Tests\Feature;

use App\Models\History;
use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithApi;
use Tests\TestCase;

class ItemHistoryApiTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithApi;

    public function test_history_returns_rows_newest_first_with_user(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();

        History::factory()->forItem($item, $user)->create([
            'field_name' => 'name',
            'changed_at' => now()->subHour(),
        ]);
        History::factory()->forItem($item, $user)->create([
            'field_name' => 'location_id',
            'old_value' => 'old-loc',
            'new_value' => 'new-loc',
            'changed_at' => now(),
        ]);

        $response = $this->actingAsApi($user, ['item:read'])
            ->getJson("/api/item/{$item->id}/history")
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonStructure([[
                'id', 'item_id', 'field_name', 'old_value', 'new_value', 'changed_at',
                'user' => ['id', 'name'],
            ]]);

        $fields = collect($response->json())->pluck('field_name')->all();
        $this->assertSame(['location_id', 'name'], $fields, 'Rows must be newest first');
    }

    public function test_history_requires_item_read_token(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();

        $this->actingAsApi($user, ['status:read'])
            ->getJson("/api/item/{$item->id}/history")
            ->assertStatus(403);
    }

    public function test_history_requires_authentication(): void
    {
        $this->getJson('/api/item/some-id/history')->assertStatus(401);
    }

    public function test_history_of_another_teams_item_is_rejected(): void
    {
        [$user] = $this->newUserWithTeam();
        [$stranger] = $this->newUserWithTeam();
        $foreignItem = Item::factory()->onTeam($stranger->ownedTeams()->first())->create();

        $this->actingAsApi($user, ['item:read'])
            ->getJson("/api/item/{$foreignItem->id}/history")
            ->assertStatus(403);
    }
}
