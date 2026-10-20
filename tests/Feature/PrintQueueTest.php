<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\PrintStation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithApi;
use Tests\TestCase;

/**
 * API-039: token flavor of the server-side print queue — POST
 * api/print/jobs (enqueue an item label, optional printer target) and
 * GET api/print/stations (live stations only). Authz = team permission
 * (item:write / item:read) AND the `print:write` token ability.
 * Claim/release semantics are covered by PrintQueueWebTest (session flavor).
 */
class PrintQueueTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithApi;

    private function setUpTeam(): array
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();

        return [$user, $team, $item];
    }

    public function test_guest_cannot_enqueue(): void
    {
        $this->postJson('/api/print/jobs', ['item' => 'x', 'template' => '30334'])
            ->assertStatus(401);
    }

    public function test_enqueue_requires_print_write_ability(): void
    {
        [$user, , $item] = $this->setUpTeam();

        /* item:write alone is not enough — printing has its own ability */
        $this->actingAsApi($user, ['item:write'])
            ->postJson('/api/print/jobs', ['item' => $item->id, 'template' => '30334'])
            ->assertStatus(403);
    }

    public function test_enqueue_requires_item_write_team_permission(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();
        $viewer = $this->addTeamMember(User::factory()->create(), $team, 'Read Only');

        $this->actingAsApi($viewer, ['print:write'])
            ->postJson('/api/print/jobs', ['item' => $item->id, 'template' => '30334'])
            ->assertStatus(403);
    }

    public function test_enqueue_creates_pending_job_without_bumping_revision(): void
    {
        [$user, $team, $item] = $this->setUpTeam();
        $revisionBefore = $team->fresh()->revision;

        $response = $this->actingAsApi($user, ['print:write'])
            ->postJson('/api/print/jobs', [
                'item' => $item->id,
                'template' => '30252',
                'target_printer' => null,
            ])
            ->assertStatus(201);

        $this->assertTrue($response->json('success'));
        $this->assertSame('pending', $response->json('job.status'));

        $this->assertDatabaseHas('print_jobs', [
            'id' => $response->json('job.id'),
            'team_id' => $team->id,
            'item_id' => $item->id,
            'template' => '30252',
            'status' => 'pending',
            'target_printer' => null,
            'user_id' => $user->id,
        ]);

        /* Plumbing writes must NOT move the sync cursor (sync_batches rule) */
        $this->assertSame($revisionBefore, $team->fresh()->revision);
    }

    public function test_enqueue_validates_template_and_item(): void
    {
        [$user, , $item] = $this->setUpTeam();

        $this->actingAsApi($user, ['print:write'])
            ->postJson('/api/print/jobs', ['item' => $item->id, 'template' => '99999'])
            ->assertStatus(422);

        $this->actingAsApi($user, ['print:write'])
            ->postJson('/api/print/jobs', ['template' => '30334'])
            ->assertStatus(422);
    }

    public function test_enqueue_cross_team_item_returns_404(): void
    {
        [$user, $team, $item] = $this->setUpTeam();
        [$otherUser, $otherTeam] = $this->newUserWithTeam();
        $foreignItem = Item::factory()->onTeam($otherTeam)->create();

        /* A job for another team's item is a plain 404 — no existence leak */
        $this->actingAsApi($user, ['print:write'])
            ->postJson('/api/print/jobs', ['item' => $foreignItem->id, 'template' => '30334'])
            ->assertStatus(404);

        $this->assertDatabaseCount('print_jobs', 0);
    }

    public function test_enqueue_with_dead_target_returns_409_naming_the_printer(): void
    {
        [$user, $team, $item] = $this->setUpTeam();

        /* A station that USED to serve the printer, heartbeat long gone */
        PrintStation::create([
            'team_id' => $team->id,
            'client_id' => 'stale-client',
            'printer_name' => 'Warehouse DYMO',
            'last_seen_at' => now()->subSeconds(200),
        ]);

        $response = $this->actingAsApi($user, ['print:write'])
            ->postJson('/api/print/jobs', [
                'item' => $item->id,
                'template' => '30334',
                'target_printer' => 'Warehouse DYMO',
            ])
            ->assertStatus(409);

        $this->assertStringContainsString('Warehouse DYMO', $response->json('error'));
        $this->assertDatabaseCount('print_jobs', 0);
    }

    public function test_enqueue_untargeted_succeeds_even_without_any_station(): void
    {
        [$user, , $item] = $this->setUpTeam();

        /* No station declared at all — the job waits for the first claimer */
        $this->actingAsApi($user, ['print:write'])
            ->postJson('/api/print/jobs', ['item' => $item->id, 'template' => '30334'])
            ->assertStatus(201);
    }

    public function test_enqueue_with_live_target_succeeds(): void
    {
        [$user, $team, $item] = $this->setUpTeam();

        PrintStation::create([
            'team_id' => $team->id,
            'client_id' => 'live-client',
            'printer_name' => 'Office DYMO',
            'last_seen_at' => now(),
        ]);

        $response = $this->actingAsApi($user, ['print:write'])
            ->postJson('/api/print/jobs', [
                'item' => $item->id,
                'template' => '30334',
                'target_printer' => 'Office DYMO',
            ])
            ->assertStatus(201);

        $this->assertSame('Office DYMO', $response->json('job.target_printer'));
    }

    public function test_stations_list_returns_only_live(): void
    {
        [$user, $team] = $this->newUserWithTeam();

        PrintStation::create([
            'team_id' => $team->id,
            'client_id' => 'fresh-client',
            'printer_name' => 'Fresh DYMO',
            'label' => 'Front desk',
            'template' => '30334',
            'last_seen_at' => now(),
        ]);
        PrintStation::create([
            'team_id' => $team->id,
            'client_id' => 'stale-client',
            'printer_name' => 'Gone DYMO',
            'last_seen_at' => now()->subSeconds(3600),
        ]);

        $response = $this->actingAsApi($user, ['print:write'])
            ->getJson('/api/print/stations')
            ->assertOk();

        $stations = $response->json('stations');
        $this->assertCount(1, $stations);
        $this->assertSame('Fresh DYMO', $stations[0]['printer_name']);
        $this->assertSame('Front desk', $stations[0]['label']);
        $this->assertNotNull($stations[0]['last_seen_at']);
    }

    public function test_stations_are_team_scoped(): void
    {
        [$user] = $this->newUserWithTeam();
        [$otherUser, $otherTeam] = $this->newUserWithTeam();

        PrintStation::create([
            'team_id' => $otherTeam->id,
            'client_id' => 'foreign-client',
            'printer_name' => 'Foreign DYMO',
            'last_seen_at' => now(),
        ]);

        $this->actingAsApi($user, ['print:write'])
            ->getJson('/api/print/stations')
            ->assertOk()
            ->assertJsonCount(0, 'stations');
    }
}
