<?php

namespace Tests\Feature;

use App\Models\History;
use App\Models\Item;
use App\Models\Label;
use App\Models\Location;
use App\Models\Status;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithApi;
use Tests\TestCase;

/**
 * API-037: batch sync — POST api/sync/batch applies a phone's whole offline
 * queue in ONE request (ordered ops, per-op isolation, persisted receipts)
 * with a three-way merge on field ops (base_revision + per-field base
 * values): cross-field divergence merges, same-field divergence conflicts.
 *
 * Revision math is pinned throughout: an applied item write bumps the team
 * counter exactly once; conflicts and noops bump nothing; the batch receipt
 * tables themselves are unobserved (recording them bumps nothing).
 */
class SyncBatchTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithApi;

    /**
     * A user (owner), their team and one item with a location + status.
     *
     * @return array{0: \App\Models\User, 1: \App\Models\Team, 2: \App\Models\Item}
     */
    private function setUpTeam(): array
    {
        [$user, $team] = $this->newUserWithTeam();
        $location = Location::factory()->create(['team_id' => $team->id]);
        $status = Status::factory()->create(['team_id' => $team->id]);
        $item = Item::factory()->onTeam($team)->inLocation($location)->withStatus($status)->create();

        return [$user, $team, $item];
    }

    private function submit($user, array $operations)
    {
        return $this->actingAsApi($user, ['item:write'])
            ->postJson('/api/sync/batch', ['operations' => $operations]);
    }

    /* ------------------------------------------------------------------ */
    /* Happy paths                                                         */
    /* ------------------------------------------------------------------ */

    public function test_field_ops_apply_in_order_with_exact_revision_and_history_counts(): void
    {
        [$user, $team, $item] = $this->setUpTeam();
        $child = Item::factory()->onTeam($team)
            ->inLocation($item->location)->withStatus($item->status)
            ->create(['parent_id' => $item->id]);
        $newStatus = Status::factory()->create(['team_id' => $team->id]);
        $newLocation = Location::factory()->create(['team_id' => $team->id]);
        $base = $team->fresh()->revision;

        $response = $this->submit($user, [
            ['op' => 'rename', 'item_id' => $item->id, 'base_revision' => $base,
                'changes' => ['name' => 'Renamed']],
            ['op' => 'move', 'item_id' => $child->id, 'base_revision' => $base,
                'changes' => ['parent_id' => null]],
            ['op' => 'assign', 'item_id' => $item->id, 'base_revision' => $base,
                'changes' => ['status_id' => $newStatus->id, 'location_id' => $newLocation->id]],
            ['op' => 'pick', 'item_id' => $item->id, 'base_revision' => $base],
            ['op' => 'unpick', 'item_id' => $item->id, 'base_revision' => $base],
        ])->assertOk();

        foreach ([0, 1, 2, 3, 4] as $i) {
            $response->assertJsonPath("results.$i.result", 'applied');
            $response->assertJsonPath("results.$i.index", $i);
        }
        $response->assertJsonPath('results.0.item.name', 'Renamed');
        $this->assertTrue(Str::isUuid($response->json('batch_id')));

        $item->refresh();
        $child->refresh();
        $this->assertSame('Renamed', $item->name);
        $this->assertNull($child->parent_id, 'move to root must clear parent_id');
        $this->assertSame($newStatus->id, $item->status_id);
        $this->assertSame($newLocation->id, $item->location_id);
        $this->assertNull($item->picked_at, 'pick then unpick ends unpicked');

        /* rename 1 + move 1 + assign 2 + pick 1 + unpick 1 history rows */
        $this->assertDatabaseCount('histories', 6);

        /* Five applied ops = five exact bumps; the response and the
           X-Revision header carry the post-batch counter. */
        $this->assertSame($base + 5, $team->fresh()->revision);
        $response->assertJsonPath('revision', $base + 5);
        $this->assertSame((string) ($base + 5), $response->headers->get('X-Revision'));
    }

    public function test_create_op_creates_the_item_in_the_batch_team(): void
    {
        [$user, $team, $item] = $this->setUpTeam();
        $base = $team->fresh()->revision;

        $response = $this->submit($user, [
            ['op' => 'create', 'base_revision' => $base, 'changes' => [
                'name' => 'Brand new box',
                'location_id' => $item->location_id,
                'status_id' => $item->status_id,
            ]],
        ])->assertOk();

        $response->assertJsonPath('results.0.result', 'applied');
        $createdId = $response->json('results.0.item_id');
        $this->assertTrue(Str::isUuid($createdId));
        $this->assertNotNull($response->json('results.0.item.name'));

        $created = Item::findOrFail($createdId);
        $this->assertSame('Brand new box', $created->name);
        $this->assertSame($team->id, $created->team_id);

        $this->assertSame($base + 1, $team->fresh()->revision);
        $this->assertDatabaseCount('histories', 0);
    }

    /* ------------------------------------------------------------------ */
    /* Three-way merge                                                     */
    /* ------------------------------------------------------------------ */

    public function test_same_field_divergence_is_a_conflict_and_applies_nothing(): void
    {
        [$user, $team, $item] = $this->setUpTeam();
        $base = $team->fresh()->revision;
        $originalName = $item->name;

        /* Another device renames while the phone is offline (bumps + stamps) */
        $otherName = 'Renamed by the other device';
        $item->update(['name' => $otherName]);

        $this->submit($user, [
            ['op' => 'rename', 'item_id' => $item->id, 'base_revision' => $base,
                'changes' => ['name' => 'Mine'], 'base' => ['name' => $originalName]],
        ])->assertOk()
            ->assertJsonPath('results.0.result', 'conflict')
            ->assertJsonPath('results.0.conflicts.name.base', $originalName)
            ->assertJsonPath('results.0.conflicts.name.yours', 'Mine')
            ->assertJsonPath('results.0.conflicts.name.server', $otherName);

        $this->assertSame($otherName, $item->fresh()->name, 'a conflict applies nothing');

        /* Only the other device's write bumped: the conflict bumped nothing
           and wrote no history row. */
        $this->assertSame($base + 1, $team->fresh()->revision);
        $this->assertDatabaseCount('histories', 0);
    }

    public function test_cross_field_divergence_merges_automatically(): void
    {
        [$user, $team, $item] = $this->setUpTeam();
        $newStatus = Status::factory()->create(['team_id' => $team->id]);
        $base = $team->fresh()->revision; /* the phone's sync point */
        $originalName = $item->name;

        /* Other device changed the STATUS; the phone renamed on a stale base. */
        $item->update(['status_id' => $newStatus->id]);

        $this->submit($user, [
            ['op' => 'rename', 'item_id' => $item->id, 'base_revision' => $base,
                'changes' => ['name' => 'Offline rename'], 'base' => ['name' => $originalName]],
        ])->assertOk()
            ->assertJsonPath('results.0.result', 'applied');

        $item->refresh();
        $this->assertSame('Offline rename', $item->name);
        $this->assertSame($newStatus->id, $item->status_id, 'the untouched field survives');

        /* Other device's status bump + this rename = 2; the merge wrote ONE
           history row (name) — status was not re-written. */
        $this->assertSame($base + 2, $team->fresh()->revision);
        $this->assertDatabaseCount('histories', 1);
    }

    public function test_diverged_but_same_value_is_a_noop(): void
    {
        [$user, $team, $item] = $this->setUpTeam();
        $base = $team->fresh()->revision;
        $originalName = $item->name;

        $item->update(['name' => 'Same Value']);

        $this->submit($user, [
            ['op' => 'rename', 'item_id' => $item->id, 'base_revision' => $base,
                'changes' => ['name' => 'Same Value'], 'base' => ['name' => $originalName]],
        ])->assertOk()
            ->assertJsonPath('results.0.result', 'noop');

        $this->assertSame($base + 1, $team->fresh()->revision);
        $this->assertDatabaseCount('histories', 0);
    }

    /* ------------------------------------------------------------------ */
    /* Noop semantics                                                      */
    /* ------------------------------------------------------------------ */

    public function test_idempotent_ops_are_noops_with_zero_bumps(): void
    {
        [$user, $team, $item] = $this->setUpTeam();
        $base = $team->fresh()->revision;

        $this->submit($user, [
            ['op' => 'unpick', 'item_id' => $item->id, 'base_revision' => $base],
            ['op' => 'rename', 'item_id' => $item->id, 'base_revision' => $base,
                'changes' => ['name' => $item->name]],
        ])->assertOk()
            ->assertJsonPath('results.0.result', 'noop')
            ->assertJsonPath('results.1.result', 'noop');

        $this->assertSame($base, $team->fresh()->revision, 'noops must not bump');
        $this->assertDatabaseCount('histories', 0);
    }

    /* ------------------------------------------------------------------ */
    /* Delete                                                              */
    /* ------------------------------------------------------------------ */

    public function test_delete_replicates_destroy_and_persists_detached_ids(): void
    {
        [$user, $team, $item] = $this->setUpTeam();
        $child = Item::factory()->onTeam($team)
            ->inLocation($item->location)->withStatus($item->status)
            ->create(['parent_id' => $item->id]);
        $base = $team->fresh()->revision;

        $response = $this->submit($user, [
            ['op' => 'delete', 'item_id' => $item->id, 'base_revision' => $base],
        ])->assertOk()
            ->assertJsonPath('results.0.result', 'applied');
        $batchId = $response->json('batch_id');

        $this->assertTrue($item->fresh()->trashed());
        $this->assertNull($child->fresh()->parent_id, 'children re-parent to root');

        /* One history row for the detached child */
        $this->assertDatabaseHas('histories', [
            'item_id' => $child->id,
            'field_name' => 'parent_id',
            'old_value' => $item->id,
            'new_value' => null,
        ]);

        /* Children mass-stamp (1) + the deleted row's observer bump (1) */
        $this->assertSame($base + 2, $team->fresh()->revision);

        /* The GET receipt carries the detached ids for reconciliation */
        $this->actingAsApi($user, ['item:read'])
            ->getJson("/api/sync/batch/{$batchId}")
            ->assertOk()
            ->assertJsonPath('operations.0.detail.detached_ids.0', $child->id)
            ->assertJsonPath('operations.0.result', 'applied');
    }

    public function test_delete_of_a_deleted_item_is_a_noop(): void
    {
        [$user, $team, $item] = $this->setUpTeam();
        $item->delete();
        $base = $team->fresh()->revision;

        $this->submit($user, [
            ['op' => 'delete', 'item_id' => $item->id, 'base_revision' => $base],
        ])->assertOk()
            ->assertJsonPath('results.0.result', 'noop');

        $this->assertSame($base, $team->fresh()->revision);
    }

    /* ------------------------------------------------------------------ */
    /* Labels                                                              */
    /* ------------------------------------------------------------------ */

    public function test_label_ops_apply_stamp_and_stay_idempotent(): void
    {
        [$user, $team, $item] = $this->setUpTeam();
        $label = Label::factory()->onTeam($team)->create();
        $base = $team->fresh()->revision;

        /* attach */
        $this->submit($user, [
            ['op' => 'label_add', 'item_id' => $item->id, 'base_revision' => $base,
                'label_id' => $label->id],
        ])->assertOk()->assertJsonPath('results.0.result', 'applied');

        $this->assertDatabaseHas('item_label', [
            'item_id' => $item->id, 'label_id' => $label->id,
        ]);
        /* pivot write bumps + stamps — visible to the since_revision delta */
        $this->assertSame($base + 1, $team->fresh()->revision);
        $this->assertSame(
            $team->fresh()->revision,
            (int) Item::withTrashed()->find($item->id)->sync_revision,
            'label attach must stamp the item for the revision delta'
        );

        /* duplicate attach is a noop (the single endpoint 409s; a batch is
           idempotent) — no bump, no second pivot row */
        $this->submit($user, [
            ['op' => 'label_add', 'item_id' => $item->id, 'base_revision' => $base,
                'label_id' => $label->id],
        ])->assertOk()->assertJsonPath('results.0.result', 'noop');
        $this->assertSame($base + 1, $team->fresh()->revision);

        /* detach then absent detach: applied +1, then noop */
        $this->submit($user, [
            ['op' => 'label_remove', 'item_id' => $item->id, 'base_revision' => $base,
                'label_id' => $label->id],
            ['op' => 'label_remove', 'item_id' => $item->id, 'base_revision' => $base,
                'label_id' => $label->id],
        ])->assertOk()
            ->assertJsonPath('results.0.result', 'applied')
            ->assertJsonPath('results.1.result', 'noop');

        $this->assertDatabaseMissing('item_label', [
            'item_id' => $item->id, 'label_id' => $label->id,
        ]);
        $this->assertSame($base + 2, $team->fresh()->revision);
    }

    public function test_label_add_on_a_child_item_is_a_has_parent_error(): void
    {
        [$user, $team, $item] = $this->setUpTeam();
        $child = Item::factory()->onTeam($team)
            ->inLocation($item->location)->withStatus($item->status)
            ->create(['parent_id' => $item->id]);
        $label = Label::factory()->onTeam($team)->create();
        $base = $team->fresh()->revision;

        $this->submit($user, [
            ['op' => 'label_add', 'item_id' => $child->id, 'base_revision' => $base,
                'label_id' => $label->id],
        ])->assertOk()->assertJsonPath('results.0.result', 'error')
            ->assertJsonPath('results.0.error', 'has_parent');

        $this->assertSame($base, $team->fresh()->revision);
    }

    public function test_label_of_another_team_is_label_not_found(): void
    {
        [$user, $team, $item] = $this->setUpTeam();
        [, $otherTeam] = $this->newUserWithTeam();
        $foreignLabel = Label::factory()->create(['team_id' => $otherTeam->id]);
        $base = $team->fresh()->revision;

        $this->submit($user, [
            ['op' => 'label_add', 'item_id' => $item->id, 'base_revision' => $base,
                'label_id' => $foreignLabel->id],
        ])->assertOk()->assertJsonPath('results.0.result', 'error')
            ->assertJsonPath('results.0.error', 'label_not_found');
    }

    public function test_label_attach_is_visible_in_the_revision_delta(): void
    {
        [$user, $team, $item] = $this->setUpTeam();
        $label = Label::factory()->onTeam($team)->create();
        $base = $team->fresh()->revision;

        $this->submit($user, [
            ['op' => 'label_add', 'item_id' => $item->id, 'base_revision' => $base,
                'label_id' => $label->id],
        ])->assertOk();

        $this->actingAsApi($user, ['item:read'])
            ->getJson("/api/item?since_revision={$base}")
            ->assertOk()
            ->assertJsonFragment(['id' => $item->id]);
    }

    /* ------------------------------------------------------------------ */
    /* Per-op isolation & per-op errors                                    */
    /* ------------------------------------------------------------------ */

    public function test_one_bad_op_does_not_sink_the_batch(): void
    {
        [$user, $team, $item] = $this->setUpTeam();
        $base = $team->fresh()->revision;

        $this->submit($user, [
            ['op' => 'rename', 'item_id' => $item->id, 'base_revision' => $base,
                'changes' => ['name' => 'Survived']],
            ['op' => 'rename', 'item_id' => Str::uuid()->toString(), 'base_revision' => $base,
                'changes' => ['name' => 'Nowhere']],
            ['op' => 'pick', 'item_id' => $item->id, 'base_revision' => $base],
        ])->assertOk()
            ->assertJsonPath('results.0.result', 'applied')
            ->assertJsonPath('results.1.result', 'error')
            ->assertJsonPath('results.1.error', 'not_found')
            ->assertJsonPath('results.2.result', 'applied');

        $this->assertSame('Survived', $item->fresh()->name);
        $this->assertNotNull($item->fresh()->picked_at);
        $this->assertSame($base + 2, $team->fresh()->revision);
        $this->assertDatabaseCount('sync_batch_operations', 3);
    }

    public function test_per_op_error_codes(): void
    {
        [$user, $team, $item] = $this->setUpTeam();
        $child = Item::factory()->onTeam($team)
            ->inLocation($item->location)->withStatus($item->status)
            ->create(['parent_id' => $item->id]);
        [, $otherTeam] = $this->newUserWithTeam();
        $foreignItem = Item::factory()->create(['team_id' => $otherTeam->id]);
        $trashedStatus = Status::factory()->create(['team_id' => $team->id]);
        $trashedStatus->delete();
        $base = $team->fresh()->revision;

        $this->submit($user, [
            ['op' => 'pick', 'item_id' => Str::uuid()->toString(), 'base_revision' => $base],
            ['op' => 'pick', 'item_id' => $foreignItem->id, 'base_revision' => $base],
            ['op' => 'move', 'item_id' => $item->id, 'base_revision' => $base,
                'changes' => ['parent_id' => $child->id]],
            ['op' => 'assign', 'item_id' => $item->id, 'base_revision' => $base,
                'changes' => ['status_id' => $trashedStatus->id, 'location_id' => $item->location_id]],
        ])->assertOk()
            ->assertJsonPath('results.0.result', 'error')
            ->assertJsonPath('results.1.result', 'error')
            ->assertJsonPath('results.2.result', 'error')
            ->assertJsonPath('results.3.result', 'error')
            ->assertJsonPath('results.0.error', 'not_found')
            ->assertJsonPath('results.1.error', 'foreign_team')
            ->assertJsonPath('results.2.error', 'cycle')
            ->assertJsonPath('results.3.error', 'bad_reference');

        $this->assertSame($base, $team->fresh()->revision, 'all-error batch bumps nothing');
        $this->assertNull($item->fresh()->picked_at);
        $this->assertSame($item->id, $child->fresh()->parent_id);
    }

    /* ------------------------------------------------------------------ */
    /* Envelope validation                                                 */
    /* ------------------------------------------------------------------ */

    public function test_envelope_validation_rejects_and_persists_nothing(): void
    {
        [$user, $team, $item] = $this->setUpTeam();
        $base = $team->fresh()->revision;

        /* empty batch */
        $this->submit($user, [])->assertUnprocessable();
        /* unknown op type */
        $this->submit($user, [
            ['op' => 'bogus', 'item_id' => $item->id, 'base_revision' => $base],
        ])->assertUnprocessable();
        /* missing base_revision */
        $this->submit($user, [
            ['op' => 'pick', 'item_id' => $item->id],
        ])->assertUnprocessable();
        /* missing item_id on a non-create op */
        $this->submit($user, [
            ['op' => 'pick', 'base_revision' => $base],
        ])->assertUnprocessable();
        /* over the 200-op cap */
        $this->submit($user, array_fill(0, 201, [
            'op' => 'pick', 'item_id' => $item->id, 'base_revision' => $base,
        ]))->assertUnprocessable();

        $this->assertDatabaseCount('sync_batches', 0);
        $this->assertSame($base, $team->fresh()->revision);
    }

    /* ------------------------------------------------------------------ */
    /* GET api/sync/batch/{id}                                             */
    /* ------------------------------------------------------------------ */

    public function test_get_batch_returns_the_persisted_receipts_and_totals(): void
    {
        [$user, $team, $item] = $this->setUpTeam();
        $base = $team->fresh()->revision;

        $batchId = $this->submit($user, [
            ['op' => 'rename', 'item_id' => $item->id, 'base_revision' => $base,
                'changes' => ['name' => 'Renamed']],
            ['op' => 'pick', 'item_id' => Str::uuid()->toString(), 'base_revision' => $base],
        ])->assertOk()->json('batch_id');

        $receipt = $this->actingAsApi($user, ['item:read'])
            ->getJson("/api/sync/batch/{$batchId}")
            ->assertOk()
            ->assertJsonPath('batch_id', $batchId)
            ->assertJsonPath('total', 2)
            ->assertJsonPath('applied', 1)
            ->assertJsonPath('failed', 1)
            ->assertJsonPath('conflicted', 0)
            ->assertJsonPath('noop', 0)
            ->assertJsonPath('operations.0.index', 0)
            ->assertJsonPath('operations.0.op', 'rename')
            ->assertJsonPath('operations.0.item_id', $item->id)
            ->assertJsonPath('operations.0.result', 'applied')
            ->assertJsonPath('operations.1.result', 'error')
            ->assertJsonPath('operations.1.detail.error', 'not_found');

        $this->assertNotNull($receipt->json('finished_at'));
        $this->assertNotNull($receipt->json('operations.0.applied_at'));
        $this->assertNull($receipt->json('operations.1.applied_at'));
    }

    public function test_get_batch_of_another_team_is_a_404(): void
    {
        [$user, $team, $item] = $this->setUpTeam();
        [$otherUser] = $this->newUserWithTeam();
        $batchId = $this->submit($user, [
            ['op' => 'pick', 'item_id' => $item->id, 'base_revision' => 0],
        ])->assertOk()->json('batch_id');

        $this->actingAsApi($otherUser, ['item:read'])
            ->getJson("/api/sync/batch/{$batchId}")
            ->assertNotFound();

        $this->actingAsApi($user, ['item:read'])
            ->getJson('/api/sync/batch/' . Str::uuid()->toString())
            ->assertNotFound();
    }

    /* ------------------------------------------------------------------ */
    /* Authorization                                                       */
    /* ------------------------------------------------------------------ */

    public function test_read_only_member_cannot_push_but_can_read_receipts(): void
    {
        [$owner, $team, $item] = $this->setUpTeam();
        $member = $this->addTeamMember(\App\Models\User::factory()->create(), $team, 'Read Only');

        $batchId = $this->submit($owner, [
            ['op' => 'pick', 'item_id' => $item->id, 'base_revision' => 0],
        ])->assertOk()->json('batch_id');

        /* team permission fails even with a write-ability token */
        $this->actingAsApi($member, ['item:write'])
            ->postJson('/api/sync/batch', ['operations' => [
                ['op' => 'pick', 'item_id' => $item->id, 'base_revision' => 0],
            ]])->assertForbidden();

        /* read receipt with a read-ability token works */
        $this->actingAsApi($member, ['item:read'])
            ->getJson("/api/sync/batch/{$batchId}")
            ->assertOk();
    }

    public function test_write_ability_token_is_required_to_push(): void
    {
        [$user, $team, $item] = $this->setUpTeam();

        $this->actingAsApi($user, ['item:read'])
            ->postJson('/api/sync/batch', ['operations' => [
                ['op' => 'pick', 'item_id' => $item->id, 'base_revision' => 0],
            ]])->assertForbidden();
    }

    public function test_unauthenticated_push_is_a_401(): void
    {
        $this->postJson('/api/sync/batch', ['operations' => []])
            ->assertUnauthorized();
    }

    /* ------------------------------------------------------------------ */
    /* Receipt persistence                                                 */
    /* ------------------------------------------------------------------ */

    public function test_recording_the_receipt_never_bumps_the_team_revision(): void
    {
        [$user, $team, $item] = $this->setUpTeam();
        $base = $team->fresh()->revision;

        $response = $this->submit($user, [
            ['op' => 'rename', 'item_id' => $item->id, 'base_revision' => $base,
                'changes' => ['name' => 'One bump']],
        ])->assertOk();

        $this->assertSame($base + 1, $team->fresh()->revision);
        $this->assertSame($base + 1, $response->json('revision'));

        $this->assertDatabaseCount('sync_batches', 1);
        $this->assertDatabaseHas('sync_batches', [
            'team_id' => $team->id,
            'user_id' => $user->id,
            'total' => 1,
            'applied' => 1,
            'conflicted' => 0,
            'noop' => 0,
            'failed' => 0,
        ]);
        $this->assertDatabaseHas('sync_batch_operations', [
            'op' => 'rename',
            'index' => 0,
            'item_id' => $item->id,
            'result' => 'applied',
        ]);
    }
}
