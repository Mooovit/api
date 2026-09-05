<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Label;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithApi;
use Tests\TestCase;

class LabelApiTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithApi;

    public function test_index_lists_team_labels_ordered_by_name(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        Label::factory()->onTeam($team)->create(['name' => 'Fragile']);
        Label::factory()->onTeam($team)->create(['name' => 'Heavy']);
        [$stranger] = $this->newUserWithTeam();
        Label::factory()->onTeam($stranger->ownedTeams()->first())->create();

        $response = $this->actingAsApi($user)
            ->getJson('/api/labels')
            ->assertOk()
            ->assertJsonCount(2);

        /* Order by name: Fragile before Heavy (any valid token may list — the
           current index() applies no tokenCan check; pinned as-is). */
        $this->assertEquals(['Fragile', 'Heavy'], collect($response->json())->pluck('name')->all());
    }

    public function test_store_creates_label_with_201(): void
    {
        [$user, $team] = $this->newUserWithTeam();

        $this->actingAsApi($user, ['item:write'])
            ->postJson('/api/labels', ['name' => 'Fragile', 'color' => '#A1B2C3'])
            ->assertStatus(201)
            ->assertJsonPath('name', 'Fragile');

        $this->assertDatabaseHas('labels', ['name' => 'Fragile', 'color' => '#A1B2C3']);
    }

    public function test_store_rejects_invalid_color(): void
    {
        [$user] = $this->newUserWithTeam();

        $this->actingAsApi($user, ['item:write'])
            ->postJson('/api/labels', ['name' => 'Fragile', 'color' => 'red'])
            ->assertStatus(422);
    }

    public function test_store_requires_item_write(): void
    {
        [$user] = $this->newUserWithTeam();

        $this->actingAsApi($user, ['item:read'])
            ->postJson('/api/labels', ['name' => 'Fragile', 'color' => '#A1B2C3'])
            ->assertStatus(403);
    }

    public function test_update_requires_both_fields(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $label = Label::factory()->onTeam($team)->create();

        $this->actingAsApi($user, ['item:write'])
            ->patchJson("/api/labels/{$label->id}", ['name' => 'Only name'])
            ->assertStatus(422);

        $this->actingAsApi($user, ['item:write'])
            ->patchJson("/api/labels/{$label->id}", ['name' => 'Renamed', 'color' => '#123456'])
            ->assertOk()
            ->assertJsonPath('name', 'Renamed');
    }

    public function test_destroy_returns_success(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $label = Label::factory()->onTeam($team)->create();

        $this->actingAsApi($user, ['item:write'])
            ->deleteJson("/api/labels/{$label->id}")
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    /* ------------------------------------------------------------------ */
    /* Attach / detach on items                                            */
    /* ------------------------------------------------------------------ */

    public function test_attach_label_to_top_level_item(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();
        $label = Label::factory()->onTeam($team)->create();

        $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/item/{$item->id}/labels", ['label_id' => $label->id])
            ->assertOk()
            ->assertJsonCount(1, 'item.labels');

        $this->assertDatabaseHas('item_label', ['item_id' => $item->id, 'label_id' => $label->id]);
    }

    public function test_attach_duplicate_label_conflicts_with_409(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();
        $label = Label::factory()->onTeam($team)->create();
        $item->labels()->attach($label);

        $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/item/{$item->id}/labels", ['label_id' => $label->id])
            ->assertStatus(409);
    }

    public function test_attach_on_child_item_is_rejected(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $parent = Item::factory()->onTeam($team)->create();
        $child = Item::factory()->onTeam($team)->childOf($parent)->create();
        $label = Label::factory()->onTeam($team)->create();

        $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/item/{$child->id}/labels", ['label_id' => $label->id])
            ->assertStatus(422);
    }

    public function test_attach_label_from_another_team_is_not_found(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        [$stranger] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();
        $foreignLabel = Label::factory()->onTeam($stranger->ownedTeams()->first())->create();

        $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/item/{$item->id}/labels", ['label_id' => $foreignLabel->id])
            ->assertStatus(404);
    }

    public function test_detach_label(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();
        $label = Label::factory()->onTeam($team)->create();
        $item->labels()->attach($label);

        $this->actingAsApi($user, ['item:write'])
            ->deleteJson("/api/item/{$item->id}/labels/{$label->id}")
            ->assertOk();

        $this->assertDatabaseMissing('item_label', ['item_id' => $item->id, 'label_id' => $label->id]);
    }

    public function test_attach_requires_item_write_token(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();
        $label = Label::factory()->onTeam($team)->create();

        $this->actingAsApi($user, ['item:read'])
            ->postJson("/api/item/{$item->id}/labels", ['label_id' => $label->id])
            ->assertStatus(403);
    }

    public function test_read_only_member_cannot_attach_label(): void
    {
        [$owner, $team] = $this->newUserWithTeam();
        $viewer = $this->addTeamMember(User::factory()->create(), $team, 'Read Only');
        $item = Item::factory()->onTeam($team)->create();
        $label = Label::factory()->onTeam($team)->create();

        $this->actingAsApi($viewer, ['item:write'])
            ->postJson("/api/item/{$item->id}/labels", ['label_id' => $label->id])
            ->assertStatus(403);
    }
}
