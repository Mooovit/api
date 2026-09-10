<?php

namespace Tests\Feature;

use App\Models\History;
use App\Models\Item;
use App\Models\Label;
use App\Models\Location;
use App\Models\Status;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithApi;
use Tests\TestCase;

/**
 * The kanban web surface (routes/web.php, consumed by the kanban blade
 * pages): board/activity views, JSON read endpoints, CRUD, team scoping and
 * permissions — reworked on top of the API features (API-003/006 revision +
 * delta live sync, API-011 barcode registry via session, POST-only writes
 * since the host load balancer drops PATCH).
 */
class KanbanTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithApi;

    /**
     * Switch the session user safely (guards memoize across requests in one
     * test — same rationale as InteractsWithApi::actingAsApi).
     */
    private function actingAsFresh($user): self
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user);
    }

    public function test_status_board_groups_root_items_per_status_column(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $statusA = Status::factory()->onTeam($team)->create();
        $statusB = Status::factory()->onTeam($team)->create();
        $location = Location::factory()->onTeam($team)->create();
        $redLabel = Label::factory()->onTeam($team)->create(['color' => '#FF0000']);
        $yellowLabel = Label::factory()->onTeam($team)->create(['color' => '#FFFF00']);

        $inA = Item::factory()->onTeam($team)->withStatus($statusA)->inLocation($location)->create();
        $inA->labels()->attach([$redLabel->id, $yellowLabel->id]);
        $inB = Item::factory()->onTeam($team)->withStatus($statusB)->create();
        $child = Item::factory()->onTeam($team)->withStatus($statusA)->childOf($inA)->create();

        $response = $this->actingAsFresh($user)->get('/kanban/status')
            ->assertOk()
            ->assertViewIs('kanban');

        $response->assertViewHas('jsonData', function (string $jsonData) use ($statusA, $statusB, $inA, $inB, $child) {
            $board = json_decode($jsonData, true);
            $this->assertSame($statusA->id, $board[0]['id']);
            $this->assertSame($statusB->id, $board[1]['id']);
            $this->assertSame([$inA->id], array_column($board[0]['item'], 'id'));
            $this->assertSame([$inB->id], array_column($board[1]['item'], 'id'));

            /* Children never appear on the board, whatever their status */
            $allIds = array_merge(...array_map(fn ($column) => array_column($column['item'], 'id'), $board));
            $this->assertNotContains($child->id, $allIds);

            return true;
        });

        /* Row shape: resolved names + computed label text color */
        $row = collect(json_decode($response->viewData('jsonData'), true))
            ->firstWhere('id', $statusA->id)['item'][0];
        $this->assertSame($inA->name, $row['title']);
        $this->assertSame($statusA->name, $row['status_name']);
        $this->assertSame($location->name, $row['location_name']);
        $this->assertSame(
            [$redLabel->id, $yellowLabel->id],
            array_column($row['labels'], 'id'),
        );
        $this->assertSame('#ffffff', collect($row['labels'])->firstWhere('id', $redLabel->id)['text_color']);
        $this->assertSame('#000000', collect($row['labels'])->firstWhere('id', $yellowLabel->id)['text_color']);
    }

    public function test_location_board_groups_items_and_hides_unlocated_ones(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $locationA = Location::factory()->onTeam($team)->create();
        $locationB = Location::factory()->onTeam($team)->create();

        $inA = Item::factory()->onTeam($team)->inLocation($locationA)->create();
        $inB = Item::factory()->onTeam($team)->inLocation($locationB)->create();
        $unlocated = Item::factory()->onTeam($team)->create();

        $response = $this->actingAsFresh($user)->get('/kanban/location')
            ->assertOk()
            ->assertViewIs('kanban');

        $response->assertViewHas('jsonData', function (string $jsonData) use ($locationA, $locationB, $inA, $inB, $unlocated) {
            $board = json_decode($jsonData, true);
            $this->assertSame([$inA->id], array_column(
                collect($board)->firstWhere('id', $locationA->id)['item'], 'id'));
            $this->assertSame([$inB->id], array_column(
                collect($board)->firstWhere('id', $locationB->id)['item'], 'id'));

            /* An item without location belongs to no column at all */
            $allIds = array_merge(...array_map(fn ($column) => array_column($column['item'], 'id'), $board));
            $this->assertNotContains($unlocated->id, $allIds);

            return true;
        });
    }

    public function test_activity_view_renders_team_history_only(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();
        History::factory()->forItem($item, $user)->create();

        [$stranger, $otherTeam] = $this->newUserWithTeam();
        $foreignItem = Item::factory()->onTeam($otherTeam)->create();
        History::factory()->forItem($foreignItem, $stranger)->create();

        $this->actingAsFresh($user)->get('/kanban/activity')
            ->assertOk()
            ->assertViewIs('activity')
            ->assertViewHas('enhancedHistory', fn ($history) => $history->count() === 1);
    }

    public function test_recent_history_returns_enhanced_rows_newest_first(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();
        $statusA = Status::factory()->onTeam($team)->create();
        $statusB = Status::factory()->onTeam($team)->create();

        $older = History::factory()->forItem($item, $user)->create([
            'field_name' => 'status_id',
            'old_value' => $statusA->id,
            'new_value' => $statusB->id,
            'changed_at' => now()->subMinutes(5),
        ]);
        $newer = History::factory()->forItem($item, $user)->create([
            'field_name' => 'name',
            'old_value' => 'Old name',
            'new_value' => 'New name',
            'changed_at' => now(),
        ]);

        [$stranger, $otherTeam] = $this->newUserWithTeam();
        $foreignItem = Item::factory()->onTeam($otherTeam)->create();
        History::factory()->forItem($foreignItem, $stranger)->create();

        $rows = $this->actingAsFresh($user)->getJson('/kanban/history')
            ->assertOk()
            ->assertJsonCount(2)
            ->json();

        /* Newest first */
        $this->assertSame([$newer->id, $older->id], array_column($rows, 'id'));

        /* id-valued fields resolve to names, plain fields stay raw */
        $this->assertSame('Old name', $rows[0]['old_value_name']);
        $this->assertSame('New name', $rows[0]['new_value_name']);
        $this->assertSame($statusA->name, $rows[1]['old_value_name']);
        $this->assertSame($statusB->name, $rows[1]['new_value_name']);
    }

    public function test_search_finds_by_name_and_exact_id_and_is_team_scoped(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $widgetA = Item::factory()->onTeam($team)->create(['name' => 'Blue Widget']);
        $widgetB = Item::factory()->onTeam($team)->create(['name' => 'Red Widget']);
        $child = Item::factory()->onTeam($team)->childOf($widgetB)->create();

        [$stranger, $otherTeam] = $this->newUserWithTeam();
        $foreign = Item::factory()->onTeam($otherTeam)->create(['name' => 'Foreign Widget']);

        /* LIKE on the name (case-insensitive), foreign teams invisible */
        $rows = $this->actingAsFresh($user)->getJson('/kanban/search?query=widget')
            ->assertOk()
            ->assertJsonCount(2)
            ->json();
        $this->assertEqualsCanonicalizing([$widgetA->id, $widgetB->id], array_column($rows, 'id'));

        /* Exact id match (mobile-app behavior) */
        $rows = $this->actingAsFresh($user)->getJson("/kanban/search?query={$widgetA->id}")
            ->assertOk()
            ->assertJsonCount(1)
            ->json();
        $this->assertSame($widgetA->id, $rows[0]['id']);

        /* Empty query returns the whole team incl. children, with counts */
        $rows = $this->actingAsFresh($user)->getJson('/kanban/search')
            ->assertOk()
            ->assertJsonCount(3)
            ->json();
        $byId = collect($rows)->keyBy('id');
        $this->assertSame(1, $byId[$widgetB->id]['children_count']);
        $this->assertSame(0, $byId[$widgetA->id]['children_count']);
        $this->assertSame(0, $byId[$child->id]['children_count']);
    }

    public function test_search_filter_boxes_vs_items(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $box = Item::factory()->onTeam($team)->create();
        $child = Item::factory()->onTeam($team)->childOf($box)->create();

        $boxIds = array_column(
            $this->actingAsFresh($user)->getJson('/kanban/search?filter=boxes')->assertOk()->json(), 'id');
        $this->assertSame([$box->id], $boxIds);

        $itemIds = array_column(
            $this->actingAsFresh($user)->getJson('/kanban/search?filter=items')->assertOk()->json(), 'id');
        $this->assertEqualsCanonicalizing([$child->id], $itemIds);
    }

    public function test_item_details_returns_item_children_and_resolved_history(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $status = Status::factory()->onTeam($team)->create();
        $item = Item::factory()->onTeam($team)->withStatus($status)->create();
        $box = Item::factory()->onTeam($team)->create(['name' => 'Parent Box']);
        $child = Item::factory()->onTeam($team)->childOf($item)->create();

        $moved = History::factory()->forItem($item, $user)->create([
            'field_name' => 'parent_id',
            'old_value' => null,
            'new_value' => $box->id,
            'changed_at' => now()->subMinutes(5),
        ]);
        $renamed = History::factory()->forItem($item, $user)->create([
            'field_name' => 'status_id',
            'old_value' => $status->id,
            'new_value' => $status->id,
            'changed_at' => now(),
        ]);

        $response = $this->actingAsFresh($user)->getJson("/kanban/item/{$item->id}")
            ->assertOk()
            ->assertJsonPath('item.id', $item->id)
            ->assertJsonCount(1, 'children')
            ->assertJsonPath('children.0.id', $child->id);

        $history = $response->json('history');
        $this->assertSame([$renamed->id, $moved->id], array_column($history, 'id'));
        $historyById = collect($history)->keyBy('id');
        /* parent_id resolves to the parent item's name */
        $this->assertSame('Parent Box', $historyById[$moved->id]['new_value_name']);
        /* status_id resolves to the status name */
        $this->assertSame($status->name, $historyById[$renamed->id]['new_value_name']);
    }

    public function test_item_details_scopes_and_authorizes(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();

        [$stranger, $otherTeam] = $this->newUserWithTeam();
        $foreign = Item::factory()->onTeam($otherTeam)->create();

        /* Foreign-team item looks like a 404 (current-team filter) */
        $this->actingAsFresh($user)->getJson("/kanban/item/{$foreign->id}")
            ->assertStatus(404)
            ->assertJsonPath('error', 'Item not found');

        /* A token without item:read is rejected even on its own team's item */
        $this->actingAsApi($user, ['status:read'])
            ->getJson("/kanban/item/{$item->id}")
            ->assertStatus(403);
    }

    public function test_update_item_records_history_only_for_changed_fields(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $statusA = Status::factory()->onTeam($team)->create();
        $statusB = Status::factory()->onTeam($team)->create();
        $locationA = Location::factory()->onTeam($team)->create();
        $locationB = Location::factory()->onTeam($team)->create();
        $item = Item::factory()->onTeam($team)->withStatus($statusA)->inLocation($locationA)->create();

        /* Only status_id actually changes; location is re-sent unchanged */
        $this->actingAsFresh($user)->postJson('/kanban/update-item', [
            'item_id' => $item->id,
            'status_id' => $statusB->id,
            'location_id' => $locationA->id,
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('item.status_id', $statusB->id);

        $this->assertDatabaseHas('histories', [
            'item_id' => $item->id,
            'user_id' => $user->id,
            'field_name' => 'status_id',
            'old_value' => $statusA->id,
            'new_value' => $statusB->id,
        ]);
        $this->assertDatabaseMissing('histories', [
            'item_id' => $item->id,
            'field_name' => 'location_id',
        ]);
    }

    public function test_update_item_validates_and_authorizes(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        [$stranger, $otherTeam] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();
        $foreignStatus = Status::factory()->onTeam($otherTeam)->create();

        /* Missing / unknown item id */
        $this->actingAsFresh($user)->postJson('/kanban/update-item', [
            'status_id' => $foreignStatus->id,
        ])->assertStatus(422);
        $this->actingAsFresh($user)->postJson('/kanban/update-item', [
            'item_id' => '00000000-0000-0000-0000-000000000000',
        ])->assertStatus(422);

        /* Status of another team → 404 (team-filtered firstOrFail) */
        $this->actingAsFresh($user)->postJson('/kanban/update-item', [
            'item_id' => $item->id,
            'status_id' => $foreignStatus->id,
        ])->assertStatus(404);

        /* Foreign item → 403 (permission against the item's team) */
        [$stranger, $otherTeam] = $this->newUserWithTeam();
        $foreignItem = Item::factory()->onTeam($otherTeam)->create();
        $this->actingAsFresh($user)->postJson('/kanban/update-item', [
            'item_id' => $foreignItem->id,
            'status_id' => $foreignStatus->id,
        ])->assertStatus(403);

        /* Read-only member → 403 */
        [$member] = $this->newUserWithTeam();
        $this->addTeamMember($member, $team, 'Read Only');
        $this->actingAsFresh($member)->postJson('/kanban/update-item', [
            'item_id' => $item->id,
            'status_id' => $foreignStatus->id,
        ])->assertStatus(403);
    }

    public function test_status_crud_from_kanban(): void
    {
        [$user, $team] = $this->newUserWithTeam();

        $created = $this->actingAsFresh($user)->postJson('/kanban/status', ['name' => 'Packing'])
            ->assertOk()
            ->assertJsonPath('name', 'Packing')
            ->assertJsonPath('team_id', $team->id)
            ->json();
        $this->assertDatabaseHas('statuses', ['id' => $created['id'], 'name' => 'Packing']);

        /* POST for writes — the host load balancer does not support PATCH */
        $this->actingAsFresh($user)->postJson("/kanban/status/{$created['id']}", ['name' => 'Packed'])
            ->assertOk()
            ->assertJsonPath('name', 'Packed');

        $this->actingAsFresh($user)->deleteJson("/kanban/status/{$created['id']}")
            ->assertOk()
            ->assertJsonPath('success', true);
        $this->assertSoftDeleted('statuses', ['id' => $created['id']]);

        /* Foreign-team status is invisible: 404 on update + delete */
        [$stranger, $otherTeam] = $this->newUserWithTeam();
        $foreign = Status::factory()->onTeam($otherTeam)->create();
        $this->actingAsFresh($user)->postJson("/kanban/status/{$foreign->id}", ['name' => 'X'])
            ->assertStatus(404);
        $this->actingAsFresh($user)->deleteJson("/kanban/status/{$foreign->id}")
            ->assertStatus(404);

        /* Read-only member cannot write */
        [$member] = $this->newUserWithTeam();
        $this->addTeamMember($member, $team, 'Read Only');
        $this->actingAsFresh($member)->postJson('/kanban/status', ['name' => 'Nope'])
            ->assertStatus(403);
    }

    public function test_location_crud_from_kanban(): void
    {
        [$user, $team] = $this->newUserWithTeam();

        $created = $this->actingAsFresh($user)->postJson('/kanban/location', ['name' => 'Dock A'])
            ->assertOk()
            ->assertJsonPath('name', 'Dock A')
            ->json();

        $this->actingAsFresh($user)->postJson("/kanban/location/{$created['id']}", ['name' => 'Dock B'])
            ->assertOk()
            ->assertJsonPath('name', 'Dock B');

        $this->actingAsFresh($user)->deleteJson("/kanban/location/{$created['id']}")
            ->assertOk()
            ->assertJsonPath('success', true);

        [$stranger, $otherTeam] = $this->newUserWithTeam();
        $foreign = Location::factory()->onTeam($otherTeam)->create();
        $this->actingAsFresh($user)->postJson("/kanban/location/{$foreign->id}", ['name' => 'X'])
            ->assertStatus(404);
        $this->actingAsFresh($user)->deleteJson("/kanban/location/{$foreign->id}")
            ->assertStatus(404);
    }

    public function test_label_crud_from_kanban(): void
    {
        [$user, $team] = $this->newUserWithTeam();

        /* Color must be #RRGGBB */
        $this->actingAsFresh($user)->postJson('/kanban/label', ['name' => 'Fragile', 'color' => 'green'])
            ->assertStatus(422);

        $created = $this->actingAsFresh($user)->postJson('/kanban/label', ['name' => 'Fragile', 'color' => '#00FF00'])
            ->assertOk()
            ->assertJsonPath('name', 'Fragile')
            ->assertJsonPath('color', '#00FF00')
            ->json();

        $this->actingAsFresh($user)->postJson("/kanban/label/{$created['id']}", ['name' => 'Heavy', 'color' => '#FF0000'])
            ->assertOk()
            ->assertJsonPath('name', 'Heavy');

        $this->actingAsFresh($user)->deleteJson("/kanban/label/{$created['id']}")
            ->assertOk()
            ->assertJsonPath('success', true);

        [$stranger, $otherTeam] = $this->newUserWithTeam();
        $foreign = Label::factory()->onTeam($otherTeam)->create();
        $this->actingAsFresh($user)->postJson("/kanban/label/{$foreign->id}", ['name' => 'X', 'color' => '#FF0000'])
            ->assertStatus(404);
        $this->actingAsFresh($user)->deleteJson("/kanban/label/{$foreign->id}")
            ->assertStatus(404);
    }

    public function test_label_attach_remove_on_items(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();
        $label = Label::factory()->onTeam($team)->create();

        $attached = $this->actingAsFresh($user)->postJson("/kanban/item/{$item->id}/labels", [
            'label_id' => $label->id,
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->json();
        $this->assertSame([$label->id], array_column($attached['item']['labels'], 'id'));

        /* Duplicate attach → 409 */
        $this->actingAsFresh($user)->postJson("/kanban/item/{$item->id}/labels", [
            'label_id' => $label->id,
        ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Label already attached to item');

        $this->actingAsFresh($user)->deleteJson("/kanban/item/{$item->id}/labels/{$label->id}")
            ->assertOk()
            ->assertJsonPath('success', true);
        $this->assertDatabaseCount('item_label', 0);

        /* Foreign label / foreign item → 404 */
        [$stranger, $otherTeam] = $this->newUserWithTeam();
        $foreignLabel = Label::factory()->onTeam($otherTeam)->create();
        $foreignItem = Item::factory()->onTeam($otherTeam)->create();
        $this->actingAsFresh($user)->postJson("/kanban/item/{$item->id}/labels", [
            'label_id' => $foreignLabel->id,
        ])->assertStatus(404);
        $this->actingAsFresh($user)->deleteJson("/kanban/item/{$foreignItem->id}/labels/{$label->id}")
            ->assertStatus(404);

        /* Read-only member cannot attach */
        [$member] = $this->newUserWithTeam();
        $this->addTeamMember($member, $team, 'Read Only');
        $this->actingAsFresh($member)->postJson("/kanban/item/{$item->id}/labels", [
            'label_id' => $label->id,
        ])->assertStatus(403);
    }

    public function test_kanban_requires_authentication(): void
    {
        $this->getJson('/kanban/status')->assertStatus(401);
        $this->getJson('/kanban/activity')->assertStatus(401);
        $this->getJson('/kanban/history')->assertStatus(401);
        $this->getJson('/kanban/search')->assertStatus(401);
        $this->getJson('/kanban/revision')->assertStatus(401);
        $this->getJson('/kanban/delta')->assertStatus(401);
        $this->postJson('/kanban/update-item', [])->assertStatus(401);
    }

    /**
     * Fixed as part of the board rework: `/kanban/labels` is now registered
     * BEFORE the generic `{type}` route, so the JSON catalogue is reachable
     * (the old quirk pinned it as shadowed by the status-board view).
     */
    public function test_labels_route_returns_the_team_catalogue(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $mine = Label::factory()->onTeam($team)->create(['name' => 'Fragile']);
        [$foreign, $otherTeam] = $this->newUserWithTeam();
        Label::factory()->onTeam($otherTeam)->create(['name' => 'Foreign']);

        $this->actingAsFresh($user)->get('/kanban/labels')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $mine->id)
            ->assertJsonPath('0.name', 'Fragile');
    }

    public function test_revision_returns_the_team_counter(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        Item::factory()->onTeam($team)->create();
        $expected = $team->fresh()->revision;

        $this->actingAsFresh($user)->get('/kanban/revision')
            ->assertOk()
            ->assertJsonPath('revision', $expected);

        /* The counter is team-scoped: another team's writes don't move it */
        [$foreign] = $this->newUserWithTeam();
        Item::factory()->onTeam($foreign->currentTeam)->create();
        $this->actingAsFresh($user)->get('/kanban/revision')
            ->assertOk()
            ->assertJsonPath('revision', $expected);
    }

    public function test_delta_returns_changed_and_deleted_since(): void
    {
        Carbon::setTestNow('2026-09-05 10:00:00');
        [$user, $team] = $this->newUserWithTeam();
        $status = Status::factory()->onTeam($team)->create();

        $kept = Item::factory()->onTeam($team)->create(['name' => 'Kept']);
        $gone = Item::factory()->onTeam($team)->create(['name' => 'Gone']);

        Carbon::setTestNow('2026-09-05 10:05:00');
        $fresh = Item::factory()->onTeam($team)->create(['name' => 'Fresh', 'status_id' => $status->id]);
        $kept->update(['name' => 'Kept renamed']);
        $gone->delete();

        /* `since` is required */
        $this->actingAsFresh($user)->getJson('/kanban/delta')->assertStatus(422);

        $response = $this->actingAsFresh($user)
            ->get('/kanban/delta?since=' . urlencode('2026-09-05T10:00:00.000000Z'))
            ->assertOk()
            ->assertJsonPath('revision', $team->fresh()->revision);

        $json = $response->json();
        /* The soft-deleted row is gone from `changed` (default scope) and
           listed in `deleted_ids` instead */
        $this->assertEqualsCanonicalizing(
            [$fresh->id, $kept->id],
            array_column($json['changed'], 'id')
        );
        $renamed = collect($json['changed'])->firstWhere('id', $kept->id);
        $this->assertSame('Kept renamed', $renamed['title']);
        $this->assertSame([$gone->id], $json['deleted_ids']);

        /* Children arrive as rows too (the JS drops them client-side) */
        $child = Item::factory()->childOf($fresh)->onTeam($team)->create();
        $json = $this->actingAsFresh($user)
            ->get('/kanban/delta?since=' . urlencode('2026-09-05T10:01:00.000000Z'))
            ->assertOk()
            ->json();
        $childRow = collect($json['changed'])->firstWhere('id', $child->id);
        $this->assertNotNull($childRow);
        $this->assertSame($fresh->id, $childRow['parent_id']);

        /* Foreign-team items are invisible */
        [$foreign] = $this->newUserWithTeam();
        $foreignItem = Item::factory()->onTeam($foreign->currentTeam)->create();
        $json = $this->actingAsFresh($user)
            ->get('/kanban/delta?since=' . urlencode('2026-09-05T09:00:00.000000Z'))
            ->assertOk()
            ->json();
        $this->assertNotContains($foreignItem->id, array_column($json['changed'], 'id'));
    }

    public function test_barcode_attach_detach_from_kanban(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();

        /* Attach — codes are trimmed, stored verbatim, team-scoped unique */
        $attached = $this->actingAsFresh($user)->postJson("/kanban/item/{$item->id}/barcodes", [
            'code' => '  WH-001  ',
        ])
            ->assertStatus(201)
            ->assertJsonPath('success', true)
            ->json();
        $this->assertSame('WH-001', $attached['item']['barcodes'][0]['code']);

        /* Duplicate in the same team → 409, even on another item */
        $other = Item::factory()->onTeam($team)->create();
        $this->actingAsFresh($user)->postJson("/kanban/item/{$other->id}/barcodes", ['code' => 'WH-001'])
            ->assertStatus(409)
            ->assertJsonPath('error', 'Code already registered in this team');

        /* Detach by row id and by raw code */
        $code = $attached['item']['barcodes'][0]['code'];
        $this->actingAsFresh($user)->deleteJson("/kanban/item/{$item->id}/barcodes/{$attached['item']['barcodes'][0]['id']}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->actingAsFresh($user)->postJson("/kanban/item/{$item->id}/barcodes", ['code' => $code])
            ->assertStatus(201);
        $this->actingAsFresh($user)->deleteJson("/kanban/item/{$item->id}/barcodes/{$code}")
            ->assertOk()
            ->assertJsonPath('success', true);
        $this->assertDatabaseMissing('item_barcodes', ['code' => $code]);

        /* Foreign item → 404; read-only member → 403 */
        [$stranger, $otherTeam] = $this->newUserWithTeam();
        $foreignItem = Item::factory()->onTeam($otherTeam)->create();
        $this->actingAsFresh($user)->postJson("/kanban/item/{$foreignItem->id}/barcodes", ['code' => 'X1'])
            ->assertStatus(404);

        [$member] = $this->newUserWithTeam();
        $this->addTeamMember($member, $team, 'Read Only');
        $this->actingAsFresh($member)->postJson("/kanban/item/{$item->id}/barcodes", ['code' => 'X1'])
            ->assertStatus(403);
    }

    public function test_share_link_activate_revoke_and_reissue_from_kanban(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();

        /* Activate — first call creates (201) and returns the public URL */
        $payload = $this->actingAsFresh($user)->postJson("/kanban/item/{$item->id}/share")
            ->assertStatus(201)
            ->assertJsonPath('success', true)
            ->json('share');
        $this->assertNotNull($payload['share_url']);
        $this->assertStringContainsString('/share/', $payload['share_url']);
        $this->assertNotSame($item->id, $payload['token']);
        $this->get('/share/' . $payload['token'])->assertOk();

        /* Re-activate while active → idempotent 200, same token */
        $again = $this->actingAsFresh($user)->postJson("/kanban/item/{$item->id}/share")
            ->assertOk()
            ->json('share');
        $this->assertSame($payload['token'], $again['token']);

        /* Deactivate — the public URL dies immediately */
        $this->actingAsFresh($user)->deleteJson("/kanban/item/{$item->id}/share")
            ->assertOk()
            ->assertJsonPath('success', true);
        $this->get('/share/' . $payload['token'])->assertStatus(404);

        /* Re-activating a revoked link issues a FRESH token (old URL stays dead) */
        $third = $this->actingAsFresh($user)->postJson("/kanban/item/{$item->id}/share")
            ->assertOk()
            ->json('share');
        $this->assertNotSame($payload['token'], $third['token']);
        $this->get('/share/' . $payload['token'])->assertStatus(404);
        $this->get('/share/' . $third['token'])->assertOk();

        /* Foreign item → 404 (current-team scoping); read-only member → 403 */
        [$stranger, $otherTeam] = $this->newUserWithTeam();
        $foreignItem = Item::factory()->onTeam($otherTeam)->create();
        $this->actingAsFresh($user)->postJson("/kanban/item/{$foreignItem->id}/share")
            ->assertStatus(404);

        [$member] = $this->newUserWithTeam();
        $this->addTeamMember($member, $team, 'Read Only');
        $this->actingAsFresh($member)->postJson("/kanban/item/{$item->id}/share")
            ->assertStatus(403);
        $this->actingAsFresh($member)->deleteJson("/kanban/item/{$item->id}/share")
            ->assertStatus(403);
    }

    public function test_get_item_details_carries_share_state(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();

        /* Not shared yet: all-null additive shape */
        $details = $this->actingAsFresh($user)->getJson("/kanban/item/{$item->id}")
            ->assertOk()
            ->json();
        $this->assertArrayHasKey('share', $details);
        $this->assertNull($details['share']['share_url']);
        $this->assertNull($details['share']['token']);

        /* After activation the same round-trip carries the live link */
        $payload = $this->actingAsFresh($user)->postJson("/kanban/item/{$item->id}/share")
            ->json('share');

        $details = $this->actingAsFresh($user)->getJson("/kanban/item/{$item->id}")
            ->assertOk()
            ->json();
        $this->assertSame($payload['share_url'], $details['share']['share_url']);
        $this->assertSame($payload['token'], $details['share']['token']);
    }

    public function test_kanban_board_serves_the_vendored_qr_script_and_card_button(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $status = Status::factory()->onTeam($team)->create();
        $item = Item::factory()->onTeam($team)->withStatus($status)->create();

        /* Blade smoke: the vendored lib + the per-card QR affordance ship
           with the page (works offline on the LAN — no CDN for the QR) */
        $response = $this->actingAsFresh($user)->get('/kanban/status')
            ->assertOk();

        $html = $response->getContent();
        $this->assertStringContainsString('js/vendor/qrcode.min.js', $html);
        $this->assertStringContainsString('showCardQr(', $html);

        /* The vendored lib ships with the repo (static files are served by
           the web server, not the framework router — so assert on disk) */
        $this->assertFileExists(public_path('js/vendor/qrcode.min.js'));
    }

    public function test_item_details_surface_ships_the_identity_uuid_qr(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();

        /* API-028: the details modal is JS-rendered — the identity QR
           plumbing (render target + copy helper) ships in the served
           kanban.js (a static file, asserted on disk like the lib above) */
        $js = file_get_contents(public_path('js/kanban.js'));
        $this->assertStringContainsString('function renderDetailsUuidQr(', $js);
        $this->assertStringContainsString("getElementById('detailsUuidQr')", $js);
        $this->assertStringContainsString('window.copyItemUuid = copyItemUuid;', $js);

        /* The uuid the QR encodes is the details payload's item id */
        $this->actingAsFresh($user)
            ->getJson("/kanban/item/{$item->id}")
            ->assertOk()
            ->assertJsonPath('item.id', $item->id);
    }

    /* ---- API-026: incremental activity feed (`after` cursor) ---- */

    public function test_recent_history_after_returns_only_rows_newer_than_the_cursor(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();

        $older = History::factory()->forItem($item, $user)->create([
            'changed_at' => now()->subMinutes(5),
        ]);
        $newer = History::factory()->forItem($item, $user)->create([
            'changed_at' => now(),
        ]);

        /* Cursor = the newest row the client already shows, advanced past
           its second: only strictly newer rows come back */
        $after = $older->changed_at->copy()->addSecond()->toISOString();

        $json = $this->actingAsFresh($user)
            ->getJson('/kanban/history?after=' . urlencode($after))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->json();

        $this->assertSame([$newer->id], array_column($json['data'], 'id'));
        $this->assertSame($newer->changed_at->toISOString(), $json['cursor']);
    }

    public function test_recent_history_after_redelivers_tied_rows_for_client_dedupe(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();

        $older = History::factory()->forItem($item, $user)->create([
            'changed_at' => now()->subMinutes(5),
        ]);
        $newer = History::factory()->forItem($item, $user)->create([
            'changed_at' => now(),
        ]);

        /* Cursor EXACTLY on the shown row's timestamp: the sanctioned simple
           tie-handling re-delivers it (bounded to that timestamp) and the
           client dedupes by id — nothing is ever skipped */
        $after = $older->changed_at->toISOString();

        $json = $this->actingAsFresh($user)
            ->getJson('/kanban/history?after=' . urlencode($after))
            ->assertOk()
            ->json();

        $this->assertSame([$newer->id, $older->id], array_column($json['data'], 'id'));
        $this->assertSame($newer->changed_at->toISOString(), $json['cursor']);
    }

    public function test_recent_history_after_with_no_new_rows_echoes_the_cursor(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();
        History::factory()->forItem($item, $user)->create([
            'changed_at' => now()->subHour(),
        ]);

        $after = now()->addMinute()->toISOString();

        $json = $this->actingAsFresh($user)
            ->getJson('/kanban/history?after=' . urlencode($after))
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->json();

        /* The client can chain blindly: empty set → same cursor back */
        $this->assertSame(Carbon::parse($after)->toISOString(), $json['cursor']);
    }

    public function test_recent_history_after_is_team_scoped(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();
        History::factory()->forItem($item, $user)->create([
            'changed_at' => now()->subMinutes(5),
        ]);

        /* A foreign-team entry NEWER than the cursor never crosses over */
        [$stranger, $otherTeam] = $this->newUserWithTeam();
        $foreignItem = Item::factory()->onTeam($otherTeam)->create();
        $foreignRow = History::factory()->forItem($foreignItem, $stranger)->create([
            'changed_at' => now(),
        ]);

        $after = now()->subMinutes(1)->toISOString();

        $json = $this->actingAsFresh($user)
            ->getJson('/kanban/history?after=' . urlencode($after))
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->json();

        $this->assertNotSame($foreignRow->id, $json['cursor']);
    }

    public function test_history_names_survive_catalogue_deletion(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $status = Status::factory()->onTeam($team)->create();
        $location = Location::factory()->onTeam($team)->create();
        $item = Item::factory()->onTeam($team)
            ->withStatus($status)
            ->inLocation($location)
            ->create();

        $move = History::factory()->forItem($item, $user)->create([
            'field_name' => 'location_id',
            'old_value' => null,
            'new_value' => $location->id,
        ]);
        $assign = History::factory()->forItem($item, $user)->create([
            'field_name' => 'status_id',
            'old_value' => $status->id,
            'new_value' => $status->id,
        ]);

        /* The catalogue rows are deleted AFTER the history was written */
        $location->delete();
        $status->delete();

        /* The live panel still resolves NAMES (not raw uuids) — API-027 */
        $rows = $this->actingAsFresh($user)
            ->getJson('/kanban/history')
            ->assertOk()
            ->json();
        $moveRow = collect($rows)->firstWhere('id', $move->id);
        $this->assertSame($location->name, $moveRow['new_value_name']);

        /* The item-details modal resolves the same way */
        $details = $this->actingAsFresh($user)
            ->getJson("/kanban/item/{$item->id}")
            ->assertOk()
            ->json();
        $detailsMove = collect($details['history'])->firstWhere('id', $move->id);
        $this->assertSame($location->name, $detailsMove['new_value_name']);
        $detailsAssign = collect($details['history'])->firstWhere('id', $assign->id);
        $this->assertSame($status->name, $detailsAssign['old_value_name']);
        $this->assertSame($status->name, $detailsAssign['new_value_name']);
    }

    public function test_board_cards_keep_a_deleted_location_name_while_columns_stay_live(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $status = Status::factory()->onTeam($team)->create();
        $location = Location::factory()->onTeam($team)->create();
        $item = Item::factory()->onTeam($team)
            ->withStatus($status)
            ->inLocation($location)
            ->create();

        $location->delete();

        /* The STATUS board still renders the card with the deleted
           location's NAME ("where is this box?" still answers) */
        $response = $this->actingAsFresh($user)->get('/kanban/status')->assertOk();
        $board = json_decode($response->viewData('jsonData'), true);
        $this->assertSame([$status->id], array_column($board, 'id'));
        $this->assertSame([$item->id], array_column($board[0]['item'], 'id'));
        $this->assertSame($location->name, $board[0]['item'][0]['location_name']);

        /* The LOCATION board: the deleted location is GONE as a column */
        $response = $this->actingAsFresh($user)->get('/kanban/location')->assertOk();
        $board = json_decode($response->viewData('jsonData'), true);
        $this->assertSame([], $board);
    }
}
