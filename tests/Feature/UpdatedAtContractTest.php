<?php

namespace Tests\Feature;

use App\Models\History;
use App\Models\Item;
use App\Models\Label;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithApi;
use Tests\TestCase;

/**
 * Pins the timestamp contract the Android sync layer depends on (server.md §1):
 * every list row and every single-item/history payload carries created_at and
 * updated_at — and history rows carry changed_at — serialized as ISO-8601 UTC
 * with microseconds ("2025-08-20T12:34:56.000000Z"), which clients compare
 * verbatim.
 */
class UpdatedAtContractTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithApi;

    /** ISO-8601 UTC, microseconds, trailing Z — Laravel's default for timestamps(). */
    private const ISO_8601_UTC = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/';

    /**
     * Assert a payload field is present and ISO-8601 UTC parseable.
     *
     * @param mixed $value The serialized timestamp from a JSON response
     */
    private function assertIsoTimestamp($value, string $field): void
    {
        $this->assertNotNull($value, "[$field] must be present in the payload");
        $this->assertMatchesRegularExpression(
            self::ISO_8601_UTC,
            $value,
            "[$field] must be ISO-8601 UTC with microseconds, got: " . var_export($value, true)
        );
    }

    public function test_item_index_rows_carry_timestamps(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        Item::factory()->onTeam($team)->count(2)->create();

        $items = $this->actingAsApi($user, ['item:read'])
            ->getJson('/api/item')
            ->assertOk()
            ->json();

        $this->assertNotEmpty($items);
        foreach ($items as $item) {
            $this->assertIsoTimestamp($item['created_at'] ?? null, 'items[].created_at');
            $this->assertIsoTimestamp($item['updated_at'] ?? null, 'items[].updated_at');
        }
    }

    public function test_status_index_rows_carry_timestamps(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        \App\Models\Status::factory()->onTeam($team)->count(2)->create();

        $statuses = $this->actingAsApi($user, ['status:read'])
            ->getJson('/api/status')
            ->assertOk()
            ->json();

        $this->assertNotEmpty($statuses);
        foreach ($statuses as $status) {
            $this->assertIsoTimestamp($status['created_at'] ?? null, 'statuses[].created_at');
            $this->assertIsoTimestamp($status['updated_at'] ?? null, 'statuses[].updated_at');
        }
    }

    public function test_location_index_rows_carry_timestamps(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        \App\Models\Location::factory()->onTeam($team)->count(2)->create();

        $locations = $this->actingAsApi($user, ['location:read'])
            ->getJson('/api/location')
            ->assertOk()
            ->json();

        $this->assertNotEmpty($locations);
        foreach ($locations as $location) {
            $this->assertIsoTimestamp($location['created_at'] ?? null, 'locations[].created_at');
            $this->assertIsoTimestamp($location['updated_at'] ?? null, 'locations[].updated_at');
        }
    }

    public function test_labels_index_rows_carry_timestamps(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        Label::factory()->onTeam($team)->count(2)->create();

        $labels = $this->actingAsApi($user, ['item:read'])
            ->getJson('/api/labels')
            ->assertOk()
            ->json();

        $this->assertNotEmpty($labels);
        foreach ($labels as $label) {
            $this->assertIsoTimestamp($label['created_at'] ?? null, 'labels[].created_at');
            $this->assertIsoTimestamp($label['updated_at'] ?? null, 'labels[].updated_at');
        }
    }

    public function test_item_show_carries_timestamps(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();

        $item = $this->actingAsApi($user, ['item:read'])
            ->getJson("/api/item/{$item->id}")
            ->assertOk()
            ->json();

        $this->assertIsoTimestamp($item['created_at'] ?? null, 'item.created_at');
        $this->assertIsoTimestamp($item['updated_at'] ?? null, 'item.updated_at');
    }

    public function test_history_rows_carry_changed_at(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();
        History::factory()->forItem($item, $user)->create([
            'field_name' => 'name',
            'changed_at' => now()->subMinutes(5),
        ]);

        $rows = $this->actingAsApi($user, ['item:read'])
            ->getJson("/api/item/{$item->id}/history")
            ->assertOk()
            ->json();

        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertIsoTimestamp($row['changed_at'] ?? null, 'history[].changed_at');
            $this->assertIsoTimestamp($row['created_at'] ?? null, 'history[].created_at');
            $this->assertIsoTimestamp($row['updated_at'] ?? null, 'history[].updated_at');
        }
    }
}
