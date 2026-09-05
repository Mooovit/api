<?php

namespace Tests\Feature;

use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithApi;
use Tests\TestCase;

/**
 * API-006: delta sync on GET api/item?since=...
 *
 * `since` switches the list into `{ changed, deleted_ids }`; without it the
 * plain array contract is untouched. Tombstones surface only as ids (the
 * SoftDeletes global scope keeps their bodies out of `changed`).
 */
class ItemDeltaSyncTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithApi;

    /**
     * Raw-table backdate so an item predates the `since` watermark (second
     * column precision makes same-second comparisons unreliable).
     */
    private function backdate(Item $item, int $minutes): void
    {
        DB::table('items')
            ->where('id', $item->id)
            ->update(['updated_at' => now()->subMinutes($minutes)]);
    }

    public function test_no_since_returns_the_plain_array(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        Item::factory()->onTeam($team)->count(2)->create();

        $response = $this->actingAsApi($user, ['item:read'])
            ->getJson('/api/item')
            ->assertOk()
            ->assertJsonCount(2);

        $this->assertIsArray($response->json());
        $this->assertArrayNotHasKey('changed', $response->json());
        $this->assertArrayNotHasKey('deleted_ids', $response->json());
    }

    public function test_baseline_before_any_write_is_empty(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $this->backdate(Item::factory()->onTeam($team)->create(), 10);

        $since = urlencode(now()->subMinutes(5)->toIso8601String());

        $this->actingAsApi($user, ['item:read'])
            ->getJson("/api/item?since={$since}")
            ->assertOk()
            ->assertJsonPath('changed', [])
            ->assertJsonPath('deleted_ids', []);
    }

    public function test_delta_reports_creates_updates_and_deletes(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $renamed = Item::factory()->onTeam($team)->create(['name' => 'Before']);
        $removed = Item::factory()->onTeam($team)->create(['name' => 'Removed']);
        $untouched = Item::factory()->onTeam($team)->create(['name' => 'Untouched']);
        $this->backdate($renamed, 10);
        $this->backdate($removed, 10);
        $this->backdate($untouched, 10);

        $since = urlencode(now()->subMinutes(5)->toIso8601String());

        /* A create, an update and a delete after the watermark */
        $created = Item::factory()->onTeam($team)->create(['name' => 'Created']);
        $this->actingAsApi($user, ['item:write'])
            ->patchJson("/api/item/{$renamed->id}", ['name' => 'After'])
            ->assertOk();
        $this->actingAsApi($user, ['item:write'])
            ->deleteJson("/api/item/{$removed->id}")
            ->assertOk();

        $response = $this->actingAsApi($user, ['item:read'])
            ->getJson("/api/item?since={$since}")
            ->assertOk()
            ->assertJsonCount(2, 'changed')
            ->assertJsonPath('deleted_ids', [$removed->id]);

        /* Exactly the created + updated rows — the deleted box only ever
           surfaces as an id, and the untouched box never appears */
        $changedIds = collect($response->json('changed'))->pluck('id')->all();
        $this->assertContains($created->id, $changedIds);
        $this->assertContains($renamed->id, $changedIds);
        $this->assertNotContains($removed->id, $changedIds);
        $this->assertStringNotContainsString('Removed', $response->getContent());
        $this->assertStringNotContainsString('Untouched', $response->getContent());

        /* Delta rows carry the list serialization (timestamps included) */
        $response->assertJsonStructure(['changed' => [['id', 'name', 'team_id', 'created_at', 'updated_at']]]);
    }

    public function test_second_identical_call_is_idempotent(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $removed = Item::factory()->onTeam($team)->create();
        $this->backdate($removed, 10);
        $since = urlencode(now()->subMinutes(5)->toIso8601String());

        $this->actingAsApi($user, ['item:write'])
            ->deleteJson("/api/item/{$removed->id}")
            ->assertOk();

        /* The tombstone stays within the window — repeated pulls still list
           the id (clients dedupe; a future pruning policy would change this) */
        for ($i = 0; $i < 2; $i++) {
            $this->actingAsApi($user, ['item:read'])
                ->getJson("/api/item?since={$since}")
                ->assertOk()
                ->assertJsonPath('changed', [])
                ->assertJsonPath('deleted_ids', [$removed->id]);
        }
    }

    public function test_delta_is_team_scoped(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        [$stranger, $otherTeam] = $this->newUserWithTeam();
        $since = urlencode(now()->subMinutes(5)->toIso8601String());

        $foreign = Item::factory()->onTeam($otherTeam)->create();
        $mine = Item::factory()->onTeam($team)->create();

        $response = $this->actingAsApi($user, ['item:read'])
            ->getJson("/api/item?since={$since}")
            ->assertOk()
            ->assertJsonCount(1, 'changed');

        $this->assertSame($mine->id, $response->json('changed.0.id'));
        $this->assertStringNotContainsString($foreign->id, $response->getContent());
    }

    public function test_deleted_ids_do_not_leak_other_teams(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        [$stranger, $otherTeam] = $this->newUserWithTeam();
        $foreign = Item::factory()->onTeam($otherTeam)->create();
        $since = urlencode(now()->subMinutes(5)->toIso8601String());

        $this->actingAsApi($stranger, ['item:write'])
            ->deleteJson("/api/item/{$foreign->id}")
            ->assertOk();

        $this->actingAsApi($user, ['item:read'])
            ->getJson("/api/item?since={$since}")
            ->assertOk()
            ->assertJsonPath('deleted_ids', []);
    }

    public function test_since_must_be_a_date(): void
    {
        [$user] = $this->newUserWithTeam();

        $this->actingAsApi($user, ['item:read'])
            ->getJson('/api/item?since=not-a-date')
            ->assertStatus(422);
    }

    public function test_delta_carries_the_team_revision_header(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $since = urlencode(now()->subMinutes(5)->toIso8601String());

        $this->actingAsApi($user, ['item:read'])
            ->getJson("/api/item?since={$since}")
            ->assertOk()
            ->assertHeader('X-Revision', (string) $team->fresh()->revision);
    }
}
