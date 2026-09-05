<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Label;
use App\Models\Location;
use App\Models\Status;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithApi;
use Tests\TestCase;

/**
 * API-003: per-team revision counter.
 *
 * Every team-scoped write bumps `teams.revision` exactly once; reads never
 * bump it. Clients poll GET api/revision or read X-Revision on GET api/item.
 */
class RevisionApiTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithApi;

    /**
     * Read the team's current revision through the endpoint.
     */
    private function revision(User $user): int
    {
        return (int) $this->actingAsApi($user, ['item:read', 'item:write', 'status:read', 'status:write', 'location:read', 'location:write'])
            ->getJson('/api/revision')
            ->assertOk()
            ->json('revision');
    }

    public function test_revision_starts_at_zero_and_is_stable_across_reads(): void
    {
        [$user] = $this->newUserWithTeam();

        $first = $this->revision($user);
        $second = $this->revision($user);

        $this->assertSame(0, $first, 'A team with no writes must start at revision 0');
        $this->assertSame($first, $second, 'Reads must not bump the revision');
    }

    public function test_item_store_bumps_revision_once(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $location = Location::factory()->onTeam($team)->create();
        $status = Status::factory()->onTeam($team)->create();
        $before = $this->revision($user);

        $this->actingAsApi($user, ['item:write'])
            ->postJson('/api/item', [
                'name' => 'Box A',
                'team_id' => $team->id,
                'location_id' => $location->id,
                'status_id' => $status->id,
            ])
            ->assertStatus(201);

        $this->assertSame($before + 1, $this->revision($user));
    }

    public function test_item_update_bumps_revision_once(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create(['name' => 'Before']);
        $before = $this->revision($user);

        $this->actingAsApi($user, ['item:write'])
            ->patchJson("/api/item/{$item->id}", ['name' => 'After'])
            ->assertOk();

        $this->assertSame($before + 1, $this->revision($user));
    }

    public function test_noop_item_update_does_not_bump_revision(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create(['name' => 'Same']);
        $before = $this->revision($user);

        $this->actingAsApi($user, ['item:write'])
            ->patchJson("/api/item/{$item->id}", ['name' => 'Same'])
            ->assertOk();

        $this->assertSame($before, $this->revision($user), 'No-change writes must leave the counter untouched');
    }

    public function test_item_destroy_bumps_revision_once(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();
        $before = $this->revision($user);

        $this->actingAsApi($user, ['item:write'])
            ->deleteJson("/api/item/{$item->id}")
            ->assertOk();

        $this->assertSame($before + 1, $this->revision($user));
    }

    public function test_status_and_location_writes_bump_revision(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $before = $this->revision($user);

        $this->actingAsApi($user, ['status:write'])
            ->postJson('/api/status', ['name' => 'New', 'team_id' => $team->id])
            ->assertStatus(201);
        $this->assertSame($before + 1, $this->revision($user), 'status store must bump once');

        $this->actingAsApi($user, ['location:write'])
            ->postJson('/api/location', ['name' => 'Shelf', 'team_id' => $team->id])
            ->assertStatus(201);
        $this->assertSame($before + 2, $this->revision($user), 'location store must bump once');

        $status = Status::where('team_id', $team->id)->first();
        $this->actingAsApi($user, ['status:write'])
            ->patchJson("/api/status/{$status->id}", ['name' => 'Renamed'])
            ->assertOk();
        $this->assertSame($before + 3, $this->revision($user), 'status update must bump once');
    }

    public function test_label_writes_bump_revision(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $before = $this->revision($user);

        $this->actingAsApi($user, ['item:write'])
            ->postJson('/api/labels', ['name' => 'Fragile', 'color' => '#FF0000'])
            ->assertStatus(201);
        $this->assertSame($before + 1, $this->revision($user), 'label store must bump once');

        $label = Label::where('team_id', $team->id)->first();
        $this->actingAsApi($user, ['item:write'])
            ->patchJson("/api/labels/{$label->id}", ['name' => 'Fragile!', 'color' => '#00FF00'])
            ->assertOk();
        $this->assertSame($before + 2, $this->revision($user), 'label update must bump once');

        $this->actingAsApi($user, ['item:write'])
            ->deleteJson("/api/labels/{$label->id}")
            ->assertOk();
        $this->assertSame($before + 3, $this->revision($user), 'label destroy must bump once');
    }

    public function test_label_attach_and_detach_bump_revision(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();
        $label = Label::factory()->onTeam($team)->create();
        $before = $this->revision($user);

        $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/item/{$item->id}/labels", ['label_id' => $label->id])
            ->assertOk();
        $this->assertSame($before + 1, $this->revision($user), 'attach must bump once');

        $this->actingAsApi($user, ['item:write'])
            ->deleteJson("/api/item/{$item->id}/labels/{$label->id}")
            ->assertOk();
        $this->assertSame($before + 2, $this->revision($user), 'detach must bump once');
    }

    public function test_item_index_exposes_revision_header_matching_the_endpoint(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        Item::factory()->onTeam($team)->create();

        $headerRevision = (int) $this->actingAsApi($user, ['item:read'])
            ->getJson('/api/item')
            ->assertOk()
            ->headers->get('X-Revision');

        $this->assertGreaterThanOrEqual(1, $headerRevision, 'Header must reflect the factory writes');
        $this->assertSame($headerRevision, $this->revision($user), 'X-Revision and GET api/revision must agree');
    }

    public function test_revision_requires_a_read_ability(): void
    {
        [$user] = $this->newUserWithTeam();

        $this->actingAsApi($user, ['item:write'])
            ->getJson('/api/revision')
            ->assertStatus(403);
    }
}
