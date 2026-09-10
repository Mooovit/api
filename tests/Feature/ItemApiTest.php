<?php

namespace Tests\Feature;

use App\Models\History;
use App\Models\Item;
use App\Models\Label;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithApi;
use Tests\TestCase;

class ItemApiTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithApi;

    /* ------------------------------------------------------------------ */
    /* GET api/item                                                        */
    /* ------------------------------------------------------------------ */

    public function test_index_returns_team_scoped_items_with_timestamps(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        [$stranger] = $this->newUserWithTeam();

        Item::factory()->onTeam($team)->count(2)->create();
        Item::factory()->onTeam($stranger->ownedTeams()->first())->create();

        $response = $this->actingAsApi($user, ['item:read'])
            ->getJson('/api/item')
            ->assertOk();

        $response->assertJsonCount(2);
        $response->assertJsonStructure([[
            'id', 'name', 'parent_id', 'team_id', 'location_id', 'status_id',
            'created_at', 'updated_at',
        ]]);
        /* Labels are only loaded on the single-item endpoint */
        $response->assertJsonMissing(['labels' => []]);
    }

    public function test_index_requires_item_read_ability(): void
    {
        [$user] = $this->newUserWithTeam();

        $this->actingAsApi($user, ['status:read'])->getJson('/api/item')->assertStatus(403);
    }

    public function test_index_requires_authentication(): void
    {
        /* No bearer token at all (withToken persists across requests in a test,
           so this lives in its own test method) */
        $this->getJson('/api/item')->assertStatus(401);
    }

    /* ------------------------------------------------------------------ */
    /* GET api/item/:id                                                    */
    /* ------------------------------------------------------------------ */

    public function test_show_returns_item_with_labels(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();
        $item->labels()->attach(Label::factory()->onTeam($team)->create());

        $this->actingAsApi($user, ['item:read'])
            ->getJson("/api/item/{$item->id}")
            ->assertOk()
            ->assertJsonPath('id', $item->id)
            ->assertJsonCount(1, 'labels')
            ->assertJsonStructure(['created_at', 'updated_at']);
    }

    public function test_show_loads_childrens_when_requested(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $parent = Item::factory()->onTeam($team)->create();
        Item::factory()->onTeam($team)->childOf($parent)->count(2)->create();

        /* The controller checks isset($request->childrens): a bare `?childrens`
           resolves to null and does NOT trigger the load — clients must send a
           value (e.g. ?childrens=1). Pinned as-is. */
        $this->actingAsApi($user, ['item:read'])
            ->getJson("/api/item/{$parent->id}?childrens=1")
            ->assertOk()
            ->assertJsonCount(2, 'childrens');
    }

    public function test_show_rejects_items_from_another_team(): void
    {
        [$user] = $this->newUserWithTeam();
        [$stranger] = $this->newUserWithTeam();
        $foreignItem = Item::factory()->onTeam($stranger->ownedTeams()->first())->create();

        /* Regression test for API-001: show() used to authorize against the
           user's current team, leaking any team's item by id. */
        $this->actingAsApi($user, ['item:read'])
            ->getJson("/api/item/{$foreignItem->id}")
            ->assertStatus(403);
    }

    /* ------------------------------------------------------------------ */
    /* POST api/item                                                       */
    /* ------------------------------------------------------------------ */

    public function test_store_creates_item(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $location = \App\Models\Location::factory()->onTeam($team)->create();
        $status = \App\Models\Status::factory()->onTeam($team)->create();
        $parent = Item::factory()->onTeam($team)->create();

        $this->actingAsApi($user, ['item:write'])
            ->postJson('/api/item', [
                'name' => 'Box A',
                'team_id' => $team->id,
                'location_id' => $location->id,
                'status_id' => $status->id,
                'parent_id' => $parent->id,
            ])
            ->assertStatus(201)
            ->assertJsonPath('name', 'Box A')
            ->assertJsonPath('parent_id', $parent->id);

        $this->assertDatabaseHas('items', ['name' => 'Box A', 'team_id' => $team->id]);
    }

    public function test_store_validates_input(): void
    {
        [$user, $team] = $this->newUserWithTeam();

        /* Missing required fields */
        $this->actingAsApi($user, ['item:write'])
            ->postJson('/api/item', ['name' => 'Box A'])
            ->assertStatus(422);

        /* Nonexistent parent */
        $location = \App\Models\Location::factory()->onTeam($team)->create();
        $status = \App\Models\Status::factory()->onTeam($team)->create();
        $this->actingAsApi($user, ['item:write'])
            ->postJson('/api/item', [
                'name' => 'Box B',
                'team_id' => $team->id,
                'location_id' => $location->id,
                'status_id' => $status->id,
                'parent_id' => '00000000-0000-0000-0000-000000000000',
            ])
            ->assertStatus(422);
    }

    public function test_store_rejects_parent_from_another_team(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        [$stranger] = $this->newUserWithTeam();
        $foreignParent = Item::factory()->onTeam($stranger->ownedTeams()->first())->create();
        $location = \App\Models\Location::factory()->onTeam($team)->create();
        $status = \App\Models\Status::factory()->onTeam($team)->create();

        $this->actingAsApi($user, ['item:write'])
            ->postJson('/api/item', [
                'name' => 'Box C',
                'team_id' => $team->id,
                'location_id' => $location->id,
                'status_id' => $status->id,
                'parent_id' => $foreignParent->id,
            ])
            ->assertStatus(404);
    }

    public function test_store_requires_item_write_ability(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        /* Validation runs before authorization in store(), so the payload must
           be complete for the 403 to be reachable. */
        $location = \App\Models\Location::factory()->onTeam($team)->create();
        $status = \App\Models\Status::factory()->onTeam($team)->create();

        $this->actingAsApi($user, ['item:read'])
            ->postJson('/api/item', [
                'name' => 'X',
                'team_id' => $team->id,
                'location_id' => $location->id,
                'status_id' => $status->id,
            ])
            ->assertStatus(403);
    }

    /* ------------------------------------------------------------------ */
    /* PUT/PATCH api/item/:id  (legacy resource methods — pinned as-is)    */
    /* ------------------------------------------------------------------ */

    public function test_update_is_sparse_and_records_history_for_changed_fields(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $newParent = Item::factory()->onTeam($team)->create();
        $item = Item::factory()->onTeam($team)
            ->inLocation()->withStatus()
            ->create(['name' => 'Original']);

        $this->actingAsApi($user, ['item:write'])
            ->patchJson("/api/item/{$item->id}", ['parent_id' => $newParent->id])
            ->assertOk()
            ->assertJsonPath('parent_id', $newParent->id)
            ->assertJsonPath('name', 'Original');

        $item->refresh();
        $this->assertSame('Original', $item->name);
        $this->assertNotNull($item->location_id);
        $this->assertNotNull($item->status_id);

        /* Exactly one history row, for the field that actually changed */
        $this->assertDatabaseCount('histories', 1);
        $this->assertDatabaseHas('histories', [
            'item_id' => $item->id,
            'field_name' => 'parent_id',
            'old_value' => null,
            'new_value' => $newParent->id,
        ]);
    }

    public function test_update_records_name_history(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create(['name' => 'Before']);

        $this->actingAsApi($user, ['item:write'])
            ->patchJson("/api/item/{$item->id}", ['name' => 'After'])
            ->assertOk()
            ->assertJsonPath('name', 'After');

        $this->assertDatabaseHas('histories', [
            'item_id' => $item->id,
            'field_name' => 'name',
            'old_value' => 'Before',
            'new_value' => 'After',
        ]);
    }

    public function test_update_writes_no_history_when_nothing_changed(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create(['name' => 'Same']);

        $this->actingAsApi($user, ['item:write'])
            ->patchJson("/api/item/{$item->id}", ['name' => 'Same'])
            ->assertOk();

        $this->assertDatabaseCount('histories', 0);
    }

    public function test_update_requires_item_write_ability(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();

        $this->actingAsApi($user, ['item:read'])
            ->patchJson("/api/item/{$item->id}", ['name' => 'New'])
            ->assertStatus(403);
    }

    public function test_web_form_update_works_with_session_auth_without_token_abilities(): void
    {
        /* The web kanban updates items through POST /item/{item} (no token):
           the tokenCan check is intentionally skipped for non-api/* paths. */
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create(['name' => 'Before']);

        $this->actingAs($user)
            ->post("/item/{$item->id}", ['name' => 'From Web'])
            ->assertOk()
            ->assertJsonPath('name', 'From Web');
    }

    /* ------------------------------------------------------------------ */
    /* DELETE api/item/:id                                                 */
    /* ------------------------------------------------------------------ */

    public function test_destroy_deletes_item(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();

        $this->actingAsApi($user, ['item:write'])
            ->deleteJson("/api/item/{$item->id}")
            ->assertOk()
            ->assertJsonPath('success', 'success');

        /* API-005: deletion is a soft delete — invisible to every client
           surface, but the tombstone row survives for delta sync (API-006). */
        $this->assertNotNull(Item::withTrashed()->find($item->id)->deleted_at);
    }

    public function test_destroy_requires_item_write_ability(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();

        $this->actingAsApi($user, ['item:read'])
            ->deleteJson("/api/item/{$item->id}")
            ->assertStatus(403);
    }

    /* ------------------------------------------------------------------ */
    /* Team-permission matrix                                              */
    /* ------------------------------------------------------------------ */

    public function test_read_only_member_cannot_write_but_can_read(): void
    {
        [$owner, $team] = $this->newUserWithTeam();
        $viewer = $this->addTeamMember(User::factory()->create(), $team, 'Read Only');
        $item = Item::factory()->onTeam($team)->create(['name' => 'Original']);

        $this->actingAsApi($viewer, ['item:read'])
            ->getJson("/api/item/{$item->id}")
            ->assertOk();

        $this->actingAsApi($viewer, ['item:read', 'item:write'])
            ->patchJson("/api/item/{$item->id}", ['name' => 'New'])
            ->assertStatus(403);
    }

    public function test_history_rows_reference_the_acting_user(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create(['name' => 'Before']);

        $this->actingAsApi($user, ['item:write'])
            ->patchJson("/api/item/{$item->id}", ['name' => 'After']);

        $history = History::first();
        $this->assertNotNull($history);
        $this->assertSame($user->id, $history->user_id);
    }

    public function test_writes_reject_trashed_status_and_location_ids(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $liveStatus = \App\Models\Status::factory()->onTeam($team)->create();
        $liveLocation = \App\Models\Location::factory()->onTeam($team)->create();
        $item = Item::factory()->onTeam($team)
            ->withStatus($liveStatus)
            ->inLocation($liveLocation)
            ->create();

        $trashedStatus = \App\Models\Status::factory()->onTeam($team)->create();
        $trashedLocation = \App\Models\Location::factory()->onTeam($team)->create();
        $trashedStatus->delete();
        $trashedLocation->delete();

        /* store */
        $this->actingAsApi($user, ['item:write'])
            ->postJson('/api/item', [
                'name' => 'Box',
                'team_id' => $team->id,
                'location_id' => $trashedLocation->id,
                'status_id' => $liveStatus->id,
            ])
            ->assertStatus(422);

        /* update */
        $this->actingAsApi($user, ['item:write'])
            ->patchJson("/api/item/{$item->id}", ['status_id' => $trashedStatus->id])
            ->assertStatus(422);

        /* assign */
        $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/item/{$item->id}/assign", [
                'status_id' => $liveStatus->id,
                'location_id' => $trashedLocation->id,
            ])
            ->assertStatus(422);

        /* bulk-assign */
        $this->actingAsApi($user, ['item:write'])
            ->postJson('/api/item/bulk-assign', [
                'ids' => [$item->id],
                'status_id' => $trashedStatus->id,
                'location_id' => $liveLocation->id,
            ])
            ->assertStatus(422);

        /* Nothing was written anywhere */
        $item->refresh();
        $this->assertSame($liveStatus->id, $item->status_id);
        $this->assertSame($liveLocation->id, $item->location_id);
        $this->assertDatabaseMissing('items', ['name' => 'Box']);
    }
}
