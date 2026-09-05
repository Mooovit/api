<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\ItemBarcode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithApi;
use Tests\TestCase;

/**
 * API-011: per-team barcode registry on items — attach/detach, team-scoped
 * uniqueness, payload listing, pivot-like write semantics.
 */
class ItemBarcodeTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithApi;

    /**
     * Attach a code through the API and return the created row.
     */
    private function attachCode(User $user, Item $item, string $code, array $extra = []): ItemBarcode
    {
        $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/item/{$item->id}/barcodes", array_merge(['code' => $code], $extra))
            ->assertStatus(201);

        return ItemBarcode::where('item_id', $item->id)->where('code', $code)->firstOrFail();
    }

    public function test_attach_registers_code_and_lists_it_on_the_item(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();
        $revisionBefore = $team->fresh()->revision;

        $response = $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/item/{$item->id}/barcodes", [
                'code' => 'ABC-123',
                'type' => 'code128',
            ])
            ->assertStatus(201);

        $this->assertTrue($response->json('success'));
        $this->assertSame('ABC-123', $response->json('item.barcodes.0.code'));
        $this->assertSame('code128', $response->json('item.barcodes.0.type'));
        $this->assertSame($item->id, $response->json('item.barcodes.0.item_id'));

        $this->assertDatabaseHas('item_barcodes', [
            'item_id' => $item->id,
            'code' => 'ABC-123',
            'type' => 'code128',
        ]);
        $this->assertSame($revisionBefore + 1, $team->fresh()->revision);

        /* Type is optional */
        $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/item/{$item->id}/barcodes", ['code' => 'DEF-456'])
            ->assertStatus(201)
            ->assertJsonPath('item.barcodes.1.type', null);

        /* show() lists the codes */
        $shown = $this->actingAsApi($user, ['item:read'])
            ->getJson("/api/item/{$item->id}")
            ->assertOk();
        $this->assertSame(['ABC-123', 'DEF-456'], array_column($shown->json('barcodes'), 'code'));
    }

    public function test_index_payload_stays_lean_without_barcodes(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();
        $this->attachCode($user, $item, 'LEAN-1');

        /* Documented decision: codes ride on show() only — the list/delta
           payloads stay lean; clients re-pull the item to see them. */
        $rows = $this->actingAsApi($user, ['item:read'])
            ->getJson('/api/item')
            ->assertOk()
            ->json();

        $this->assertArrayNotHasKey('barcodes', $rows[0]);
    }

    public function test_codes_are_trimmed_but_case_sensitive(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();

        $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/item/{$item->id}/barcodes", ['code' => '  abc  '])
            ->assertStatus(201);

        /* Stored trimmed... */
        $this->assertDatabaseHas('item_barcodes', ['item_id' => $item->id, 'code' => 'abc']);

        /* ...and case-sensitive: the uppercase variant is a different code */
        $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/item/{$item->id}/barcodes", ['code' => 'ABC'])
            ->assertStatus(201);
    }

    public function test_duplicate_code_in_team_conflicts(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $a = Item::factory()->onTeam($team)->create();
        $b = Item::factory()->onTeam($team)->create();

        $this->attachCode($user, $a, 'DUP-1');

        /* Same item and another item: both 409 */
        $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/item/{$a->id}/barcodes", ['code' => 'DUP-1'])
            ->assertStatus(409);
        $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/item/{$b->id}/barcodes", ['code' => 'DUP-1'])
            ->assertStatus(409);

        $this->assertSame(1, ItemBarcode::count());
    }

    public function test_same_code_allowed_across_teams(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        [$stranger, $otherTeam] = $this->newUserWithTeam();
        $mine = Item::factory()->onTeam($team)->create();
        $theirs = Item::factory()->onTeam($otherTeam)->create();

        $this->attachCode($user, $mine, 'SHARED');

        $this->actingAsApi($stranger, ['item:write'])
            ->postJson("/api/item/{$theirs->id}/barcodes", ['code' => 'SHARED'])
            ->assertStatus(201);
    }

    public function test_detach_by_row_id_by_code_path_and_by_code_query(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();
        $byId = $this->attachCode($user, $item, 'CODE-A');
        $byPath = $this->attachCode($user, $item, 'CODE-B');
        $byQuery = $this->attachCode($user, $item, 'CODE-C');

        /* By barcode row id */
        $this->actingAsApi($user, ['item:write'])
            ->deleteJson("/api/item/{$item->id}/barcodes/{$byId->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        /* By code in the path */
        $this->actingAsApi($user, ['item:write'])
            ->deleteJson("/api/item/{$item->id}/barcodes/{$byPath->code}")
            ->assertOk();

        /* By code via ?code= (wins over the path segment) */
        $this->actingAsApi($user, ['item:write'])
            ->deleteJson("/api/item/{$item->id}/barcodes/whatever?code={$byQuery->code}")
            ->assertOk();

        $this->assertSame(0, $item->barcodes()->count());
    }

    public function test_attach_bumps_revision_but_not_item_updated_at(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();
        DB::table('items')->where('id', $item->id)
            ->update(['updated_at' => now()->subMinutes(30)]);
        $updatedAtBefore = $item->fresh()->updated_at;
        $revisionBefore = $team->fresh()->revision;

        $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/item/{$item->id}/barcodes", ['code' => 'PIVOT-1'])
            ->assertStatus(201);

        /* Pivot-like registry write: revision moves, the item row does not */
        $this->assertTrue($item->fresh()->updated_at->equalTo($updatedAtBefore));
        $this->assertSame($revisionBefore + 1, $team->fresh()->revision);
    }

    public function test_permission_matrix(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();

        /* Token lacks the ability */
        $this->actingAsApi($user, ['item:read'])
            ->postJson("/api/item/{$item->id}/barcodes", ['code' => 'X'])
            ->assertStatus(403);

        /* Read-Only team member with a full-ability token */
        [$member] = $this->newUserWithTeam();
        $this->addTeamMember($member, $team, 'Read Only');
        $this->actingAsApi($member, ['item:write'])
            ->postJson("/api/item/{$item->id}/barcodes", ['code' => 'X'])
            ->assertStatus(403);

        /* Foreign-team item */
        [$stranger, $otherTeam] = $this->newUserWithTeam();
        $foreign = Item::factory()->onTeam($otherTeam)->create();
        $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/item/{$foreign->id}/barcodes", ['code' => 'X'])
            ->assertStatus(403);

        /* Same rules on detach */
        $row = ItemBarcode::create(['item_id' => $item->id, 'team_id' => $team->id, 'code' => 'DMZ']);
        $this->actingAsApi($member, ['item:write'])
            ->deleteJson("/api/item/{$item->id}/barcodes/{$row->id}")
            ->assertStatus(403);

        $this->assertDatabaseCount('item_barcodes', 1);
    }

    public function test_detach_unknown_or_foreign_row_404(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();

        $this->actingAsApi($user, ['item:write'])
            ->deleteJson('/api/item/' . $item->id . '/barcodes/00000000-0000-0000-0000-000000000000')
            ->assertStatus(404);

        /* Known code, but attached to another item of the same team */
        $other = Item::factory()->onTeam($team)->create();
        ItemBarcode::create(['item_id' => $other->id, 'team_id' => $team->id, 'code' => 'ELSEWHERE']);
        $this->actingAsApi($user, ['item:write'])
            ->deleteJson("/api/item/{$item->id}/barcodes/ELSEWHERE")
            ->assertStatus(404);
    }

    public function test_soft_deleted_item_hides_codes_and_keeps_them_reserved(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();
        $this->attachCode($user, $item, 'GONE-1');

        $item->delete();

        /* show() 404s (global scope) — the codes are hidden with the item */
        $this->actingAsApi($user, ['item:read'])
            ->getJson("/api/item/{$item->id}")
            ->assertStatus(404);

        /* The row survives (cascade on hard delete only) and the code stays
           reserved within the team — documented behavior */
        $replacement = Item::factory()->onTeam($team)->create();
        $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/item/{$replacement->id}/barcodes", ['code' => 'GONE-1'])
            ->assertStatus(409);
    }

    public function test_validation(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();

        $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/item/{$item->id}/barcodes", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code']);

        $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/item/{$item->id}/barcodes", [
                'code' => str_repeat('x', 192),
                'type' => str_repeat('y', 192),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code', 'type']);
    }
}
