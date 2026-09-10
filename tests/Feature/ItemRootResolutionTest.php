<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Location;
use App\Models\Status;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithApi;
use Tests\TestCase;

/**
 * Model-level effective status/location resolution: an item inside a box
 * presents its ROOT box's values — resolved recursively up the parent chain
 * to the item without `parent_id` (Item::rootAncestor / effective_*).
 */
class ItemRootResolutionTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithApi;

    public function test_a_root_item_uses_its_own_status_and_location(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $status = Status::factory()->onTeam($team)->create();
        $location = Location::factory()->onTeam($team)->create();

        $root = Item::factory()->onTeam($team)
            ->withStatus($status)
            ->inLocation($location)
            ->create();

        $this->assertTrue($root->rootAncestor()->is($root));
        $this->assertSame($status->id, $root->effective_status->id);
        $this->assertSame($location->id, $root->effective_location->id);
    }

    public function test_a_direct_child_presents_its_parent_box_values(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $statusA = Status::factory()->onTeam($team)->create();
        $locationA = Location::factory()->onTeam($team)->create();
        $statusB = Status::factory()->onTeam($team)->create();
        $locationB = Location::factory()->onTeam($team)->create();

        $box = Item::factory()->onTeam($team)
            ->withStatus($statusA)
            ->inLocation($locationA)
            ->create();
        $child = Item::factory()->onTeam($team)
            ->withStatus($statusB)
            ->inLocation($locationB)
            ->childOf($box)
            ->create();

        /* The child's OWN stored values are ignored — the box's count */
        $this->assertTrue($child->rootAncestor()->is($box));
        $this->assertSame($statusA->id, $child->effective_status->id);
        $this->assertSame($locationA->id, $child->effective_location->id);
    }

    public function test_a_deeply_nested_item_resolves_to_the_root_box(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $rootStatus = Status::factory()->onTeam($team)->create();
        $rootLocation = Location::factory()->onTeam($team)->create();
        $otherStatus = Status::factory()->onTeam($team)->create();
        $otherLocation = Location::factory()->onTeam($team)->create();

        $root = Item::factory()->onTeam($team)
            ->withStatus($rootStatus)
            ->inLocation($rootLocation)
            ->create();
        $middle = Item::factory()->onTeam($team)
            ->withStatus($otherStatus)
            ->inLocation($otherLocation)
            ->childOf($root)
            ->create();
        $leaf = Item::factory()->onTeam($team)
            ->withStatus($otherStatus)
            ->inLocation($otherLocation)
            ->childOf($middle)
            ->create();

        /* box in box in box: BOTH levels resolve past their own values to
           the root's (the one without parent_id) */
        $this->assertTrue($middle->rootAncestor()->is($root));
        $this->assertTrue($leaf->rootAncestor()->is($root));
        $this->assertSame($rootStatus->id, $leaf->effective_status->id);
        $this->assertSame($rootLocation->id, $leaf->effective_location->id);
        $this->assertSame($rootStatus->id, $middle->effective_status->id);
        $this->assertSame($rootLocation->id, $middle->effective_location->id);
    }

    public function test_a_root_without_values_yields_null_no_fallback_down_the_chain(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $status = Status::factory()->onTeam($team)->create();
        $location = Location::factory()->onTeam($team)->create();

        /* Root box carries NO status/location; the leaf does */
        $root = Item::factory()->onTeam($team)->create();
        $leaf = Item::factory()->onTeam($team)
            ->withStatus($status)
            ->inLocation($location)
            ->childOf($root)
            ->create();

        /* The values ARE the root box's — no per-level fallback */
        $this->assertNull($leaf->effective_status);
        $this->assertNull($leaf->effective_location);
    }

    public function test_a_trashed_parent_stops_the_walk_at_the_item_itself(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $status = Status::factory()->onTeam($team)->create();
        $location = Location::factory()->onTeam($team)->create();

        $box = Item::factory()->onTeam($team)
            ->withStatus($status)
            ->inLocation($location)
            ->create();
        $child = Item::factory()->onTeam($team)->childOf($box)->create();

        $box->delete();

        /* The soft-delete global scope hides the box — the child's chain
           can't be resolved, so it presents its own values */
        $this->assertTrue($child->rootAncestor()->is($child));
        $this->assertNull($child->effective_status);
        $this->assertNull($child->effective_location);
    }

    public function test_a_corrupt_cycle_terminates_instead_of_hanging(): void
    {
        [$user, $team] = $this->newUserWithTeam();

        /* Corrupt data: a -> b -> a (no root in the chain) */
        $a = Item::factory()->onTeam($team)->create();
        $b = Item::factory()->onTeam($team)->childOf($a)->create();
        $a->forceFill(['parent_id' => $b->id])->saveQuietly();

        /* The visited-id guard stops the walk — it must return a member of
           the chain (never loop forever) */
        $resolved = $a->rootAncestor();
        $this->assertContains($resolved->id, [$a->id, $b->id]);
    }
}
