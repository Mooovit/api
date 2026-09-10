<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Label;
use App\Models\Status;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithApi;
use Tests\TestCase;

/**
 * API-033: the revision-keyed item delta (`GET api/item?since_revision=N`).
 *
 * `sync_revision` carries the team's post-increment counter at the moment a
 * row was last written, so the delta window is exact — no `updated_at`
 * second-precision re-delivery (the flaw this ticket exists for). Same
 * `{ changed, deleted_ids }` envelope as the timestamp delta, with the
 * current revision in `X-Revision` so a cursor converges in one round-trip.
 */
class ItemRevisionDeltaTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithApi;

    public function test_since_revision_zero_is_a_full_pull(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        Item::factory()->onTeam($team)->count(3)->create();

        $response = $this->actingAsApi($user, ['item:read'])
            ->getJson('/api/item?since_revision=0')
            ->assertOk()
            ->assertJsonCount(3, 'changed')
            ->assertJsonPath('deleted_ids', [])
            ->assertHeader('X-Revision', (string) $team->fresh()->revision);

        /* Every row was stamped at creation (in the same INSERT) */
        foreach (collect($response->json('changed')) as $row) {
            $this->assertNotNull($row['id']);
        }
    }

    public function test_only_rows_written_after_the_cursor_are_returned(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $renamed = Item::factory()->onTeam($team)->create(['name' => 'Before']);
        Item::factory()->onTeam($team)->create(['name' => 'Untouched']);

        /* Full pull first: the client remembers the header as its cursor */
        $cursor = (int) $this->actingAsApi($user, ['item:read'])
            ->getJson('/api/item')
            ->assertOk()
            ->headers->get('X-Revision');

        /* One write of one item — exactly that item comes back */
        $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/item/{$renamed->id}/rename", ['name' => 'After'])
            ->assertOk();

        $response = $this->actingAsApi($user, ['item:read'])
            ->getJson("/api/item?since_revision={$cursor}")
            ->assertOk();

        $changedIds = collect($response->json('changed'))->pluck('id')->all();
        $this->assertSame([$renamed->id], $changedIds);
        $this->assertStringNotContainsString('Untouched', $response->getContent());
    }

    public function test_uses_the_header_cursor_and_is_then_empty(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        Item::factory()->onTeam($team)->create();

        $first = $this->actingAsApi($user, ['item:read'])
            ->getJson('/api/item?since_revision=0')
            ->assertOk()
            ->assertJsonCount(1, 'changed');

        /* The header of pull 1 as the cursor of pull 2: nothing new */
        $cursor = (int) $first->headers->get('X-Revision');
        $this->actingAsApi($user, ['item:read'])
            ->getJson("/api/item?since_revision={$cursor}")
            ->assertOk()
            ->assertJsonPath('changed', [])
            ->assertJsonPath('deleted_ids', []);
    }

    public function test_soft_delete_surfaces_once_then_never_again(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $removed = Item::factory()->onTeam($team)->create();
        $cursor = (int) $this->actingAsApi($user, ['item:read'])
            ->getJson('/api/item')->headers->get('X-Revision');

        $this->actingAsApi($user, ['item:write'])
            ->deleteJson("/api/item/{$removed->id}")
            ->assertOk();

        /* Its last stamp keys the tombstone id... */
        $first = $this->actingAsApi($user, ['item:read'])
            ->getJson("/api/item?since_revision={$cursor}")
            ->assertOk()
            ->assertJsonPath('changed', [])
            ->assertJsonPath('deleted_ids', [$removed->id]);

        /* ...and the stamp no longer moves, so the next window is empty */
        $next = (int) $first->headers->get('X-Revision');
        $this->actingAsApi($user, ['item:read'])
            ->getJson("/api/item?since_revision={$next}")
            ->assertOk()
            ->assertJsonPath('deleted_ids', []);
    }

    public function test_delete_detaches_the_children_into_changed(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $box = Item::factory()->onTeam($team)->create();
        $child = Item::factory()->onTeam($team)->create(['parent_id' => $box->id]);
        $cursor = (int) $this->actingAsApi($user, ['item:read'])
            ->getJson('/api/item')->headers->get('X-Revision');

        $this->actingAsApi($user, ['item:write'])
            ->deleteJson("/api/item/{$box->id}")
            ->assertOk();

        $response = $this->actingAsApi($user, ['item:read'])
            ->getJson("/api/item?since_revision={$cursor}")
            ->assertOk();

        /* The re-parented child is a `changed` row, the box an id */
        $this->assertSame([$child->id],
            collect($response->json('changed'))->pluck('id')->all());
        $response->assertJsonPath('deleted_ids', [$box->id]);
    }

    public function test_bump_only_writes_advance_the_cursor_with_an_empty_delta(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();
        $stampBefore = $item->fresh()->sync_revision;
        $cursor = (int) $this->actingAsApi($user, ['item:read'])
            ->getJson('/api/item')->headers->get('X-Revision');

        /* Barcode registry write: bumps the team revision, stamps nothing */
        $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/item/{$item->id}/barcodes", ['code' => 'BC-033'])
            ->assertStatus(201);

        $item->refresh();
        $this->assertSame($stampBefore, $item->sync_revision);
        $this->assertGreaterThan($cursor, (int) $team->fresh()->revision);

        $response = $this->actingAsApi($user, ['item:read'])
            ->getJson("/api/item?since_revision={$cursor}")
            ->assertOk()
            ->assertJsonPath('changed', [])
            ->assertJsonPath('deleted_ids', []);

        /* ...but the new counter comes back, so the client's cursor moves
           past the bump instead of re-pulling it forever */
        $this->assertSame((string) $team->fresh()->revision,
            $response->headers->get('X-Revision'));
    }

    public function test_label_attach_and_detach_stamp_the_item(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();
        $label = Label::factory()->create(['team_id' => $team->id]);
        $cursor = (int) $this->actingAsApi($user, ['item:read'])
            ->getJson('/api/item')->headers->get('X-Revision');

        $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/item/{$item->id}/labels", ['label_id' => $label->id])
            ->assertOk();

        /* The row itself is untouched but its representation changed —
           it must carry a fresh stamp so the delta delivers it */
        $this->assertSame((int) $team->fresh()->revision, $item->fresh()->sync_revision);
        $this->actingAsApi($user, ['item:read'])
            ->getJson("/api/item?since_revision={$cursor}")
            ->assertOk()
            ->assertJsonCount(1, 'changed');

        $this->actingAsApi($user, ['item:write'])
            ->deleteJson("/api/item/{$item->id}/labels/{$label->id}")
            ->assertOk();

        $this->assertSame((int) $team->fresh()->revision, $item->fresh()->sync_revision);
    }

    public function test_a_restore_makes_the_item_reappear_in_changed(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();
        $cursor = (int) $this->actingAsApi($user, ['item:read'])
            ->getJson('/api/item')->headers->get('X-Revision');

        $this->actingAsApi($user, ['item:write'])
            ->deleteJson("/api/item/{$item->id}")
            ->assertOk();

        /* No restore endpoint yet (the client trashes forever) — the model
           API is what a future endpoint (and this test) would use */
        $item->refresh()->restore();

        $this->actingAsApi($user, ['item:read'])
            ->getJson("/api/item?since_revision={$cursor}")
            ->assertOk()
            ->assertJsonPath('deleted_ids', [])
            ->assertJsonCount(1, 'changed');
    }

    public function test_stamp_walks_the_write_surface_in_step_with_the_counter(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $status = Status::factory()->onTeam($team)->create();
        $otherStatus = Status::factory()->onTeam($team)->create();
        $location = \App\Models\Location::factory()->create(['team_id' => $team->id]);
        $otherLocation = \App\Models\Location::factory()->create(['team_id' => $team->id]);

        /* create */
        $item = Item::factory()->onTeam($team)->create();
        $this->assertSame((int) $team->fresh()->revision, $item->fresh()->sync_revision);

        /* rename / assign / move / pick / unpick — each mutation re-stamps
           with the current counter */
        foreach ([
            ['/rename', ['name' => 'Renamed']],
            ['/assign', ['status_id' => $otherStatus->id, 'location_id' => $otherLocation->id]],
            ['/assign', ['status_id' => $status->id, 'location_id' => $location->id]],
            ['/pick', []],
            ['/unpick', []],
        ] as [$verb, $payload]) {
            $this->actingAsApi($user, ['item:write'])
                ->postJson("/api/item/{$item->id}{$verb}", $payload)
                ->assertOk();

            $this->assertSame(
                (int) $team->fresh()->revision,
                $item->fresh()->sync_revision,
                "the {$verb} write re-stamped the row"
            );
        }

        /* A no-op unpick saves nothing: no bump, no stamp movement */
        $revision = $team->fresh()->revision;
        $stamp = $item->fresh()->sync_revision;
        $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/item/{$item->id}/unpick")
            ->assertOk();
        $this->assertSame($revision, $team->fresh()->revision);
        $this->assertSame($stamp, $item->fresh()->sync_revision);
    }

    public function test_bulk_ops_stamp_their_rows_with_one_counter_value(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $status = Status::factory()->onTeam($team)->create();
        $location = \App\Models\Location::factory()->create(['team_id' => $team->id]);
        $a = Item::factory()->onTeam($team)->create();
        $b = Item::factory()->onTeam($team)->create();
        $cursor = (int) $this->actingAsApi($user, ['item:read'])
            ->getJson('/api/item')->headers->get('X-Revision');

        $this->actingAsApi($user, ['item:write'])
            ->postJson('/api/item/bulk-move', [
                'ids' => [$a->id, $b->id],
                'parent_id' => null,
            ])
            ->assertOk();

        /* One bump for the whole logical write, carried by both rows */
        $stamp = (int) $team->fresh()->revision;
        $this->assertSame($stamp, $a->fresh()->sync_revision);
        $this->assertSame($stamp, $b->fresh()->sync_revision);

        $this->actingAsApi($user, ['item:write'])
            ->postJson('/api/item/bulk-assign', [
                'ids' => [$a->id, $b->id],
                'status_id' => $status->id,
                'location_id' => $location->id,
            ])
            ->assertOk();

        $nextStamp = (int) $team->fresh()->revision;
        $this->assertGreaterThan($stamp, $nextStamp);
        $this->assertSame($nextStamp, $a->fresh()->sync_revision);
        $this->assertSame($nextStamp, $b->fresh()->sync_revision);

        /* Both windows delivered exactly the batch rows */
        $this->actingAsApi($user, ['item:read'])
            ->getJson("/api/item?since_revision={$cursor}")
            ->assertOk()
            ->assertJsonCount(2, 'changed');
    }

    public function test_transfer_stamps_the_subtree_for_the_destination_team(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        [$partner, $otherTeam] = $this->newUserWithTeam();
        /* Transfer needs item:write on BOTH sides — the mover joins the
           destination team as admin */
        $this->addTeamMember($user, $otherTeam, 'admin');
        $status = Status::factory()->onTeam($otherTeam)->create();
        $location = \App\Models\Location::factory()->create(['team_id' => $otherTeam->id]);
        $box = Item::factory()->onTeam($team)->create();
        $child = Item::factory()->onTeam($team)->create(['parent_id' => $box->id]);

        $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/item/{$box->id}/transfer", [
                'team_id' => $otherTeam->id,
                'location_id' => $location->id,
                'status_id' => $status->id,
            ])
            ->assertOk();

        /* Every transferred row carries the destination's post-increment
           counter — the destination's next delta delivers the subtree */
        $this->assertSame((int) $otherTeam->fresh()->revision, $box->fresh()->sync_revision);
        $this->assertSame((int) $otherTeam->fresh()->revision, $child->fresh()->sync_revision);

        $this->actingAsApi($partner, ['item:read'])
            ->getJson('/api/item?since_revision=0')
            ->assertOk()
            ->assertJsonCount(2, 'changed');
    }

    public function test_delta_is_team_scoped(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        [$stranger, $otherTeam] = $this->newUserWithTeam();

        $foreign = Item::factory()->onTeam($otherTeam)->create();
        $mine = Item::factory()->onTeam($team)->create();

        $response = $this->actingAsApi($user, ['item:read'])
            ->getJson('/api/item?since_revision=0')
            ->assertOk()
            ->assertJsonCount(1, 'changed');

        $this->assertSame($mine->id, $response->json('changed.0.id'));
        $this->assertStringNotContainsString($foreign->id, $response->getContent());
    }

    public function test_garbage_cursors_are_422(): void
    {
        [$user] = $this->newUserWithTeam();

        $this->actingAsApi($user, ['item:read'])
            ->getJson('/api/item?since_revision=abc')
            ->assertStatus(422);

        $this->actingAsApi($user, ['item:read'])
            ->getJson('/api/item?since_revision=1.5')
            ->assertStatus(422);

        $this->actingAsApi($user, ['item:read'])
            ->getJson('/api/item?since_revision=-1')
            ->assertStatus(422);
    }

    public function test_sync_revision_never_leaks_into_payloads(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        Item::factory()->onTeam($team)->create();

        $flat = $this->actingAsApi($user, ['item:read'])->getJson('/api/item');
        $delta = $this->actingAsApi($user, ['item:read'])
            ->getJson('/api/item?since_revision=0');

        foreach ([$flat, $delta] as $response) {
            $this->assertStringNotContainsString('sync_revision', $response->getContent());
        }

        $show = $this->actingAsApi($user, ['item:read'])
            ->getJson('/api/item/' . Item::first()->id);
        $this->assertStringNotContainsString('sync_revision', $show->getContent());
    }
}
