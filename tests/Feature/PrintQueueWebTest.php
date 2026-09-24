<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\PrintJob;
use App\Models\PrintStation;
use App\Models\Status;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithApi;
use Tests\TestCase;

/**
 * API-039: session flavor of the print queue — the endpoints the kanban
 * page and the /label-print station call — plus the queue semantics
 * themselves (FIFO + targeting, claim exclusivity, stale reclaim, the
 * 3-attempt retry ladder, heartbeat-on-claim). The token flavor contract
 * is covered by PrintQueueTest.
 */
class PrintQueueWebTest extends TestCase
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

    private function setUpTeam(): array
    {
        [$user, $team] = $this->newUserWithTeam();
        $status = Status::factory()->onTeam($team)->create();
        $item = Item::factory()->onTeam($team)->withStatus($status)->create();

        return [$user, $team, $item];
    }

    private function push(Item $item, array $extra = [])
    {
        return $this->postJson('/print/jobs', array_merge([
            'item' => $item->id,
            'template' => '30334',
        ], $extra));
    }

    private function claim(string $clientId, string $printerName)
    {
        return $this->getJson('/print/claim?' . http_build_query([
            'client_id' => $clientId,
            'printer_name' => $printerName,
        ]));
    }

    private function complete(string $jobId, string $clientId, bool $ok, ?string $error = null)
    {
        return $this->postJson("/print/jobs/{$jobId}/complete", array_filter([
            'client_id' => $clientId,
            'ok' => $ok,
            'error' => $error,
        ], fn ($v) => $v !== null));
    }

    private function station(string $clientId, string $printerName): PrintStation
    {
        $this->postJson('/print/station', [
            'client_id' => $clientId,
            'printer_name' => $printerName,
        ])->assertOk();

        return PrintStation::where('client_id', $clientId)->firstOrFail();
    }

    public function test_guest_is_redirected_on_all_print_routes(): void
    {
        $this->post('/print/jobs')->assertRedirect(route('login'));
        $this->get('/print/claim')->assertRedirect(route('login'));
        $this->post('/print/jobs/some-id/complete')->assertRedirect(route('login'));
        $this->post('/print/station')->assertRedirect(route('login'));
    }

    public function test_session_user_without_item_write_gets_403(): void
    {
        [$user, $team, $item] = $this->setUpTeam();
        $viewer = $this->addTeamMember(User::factory()->create(), $team, 'Read Only');

        $this->actingAsFresh($viewer)->postJson('/print/jobs', [
            'item' => $item->id,
            'template' => '30334',
        ])->assertStatus(403);
    }

    public function test_push_creates_pending_job(): void
    {
        [$user, $team, $item] = $this->setUpTeam();

        $response = $this->actingAsFresh($user)->push($item, ['target_printer' => null])
            ->assertStatus(201);

        $this->assertTrue($response->json('success'));
        $this->assertSame('pending', $response->json('job.status'));
        $this->assertDatabaseHas('print_jobs', [
            'id' => $response->json('job.id'),
            'team_id' => $team->id,
            'item_id' => $item->id,
            'status' => 'pending',
        ]);
    }

    public function test_push_cross_team_item_returns_404(): void
    {
        [$user] = $this->setUpTeam();
        [$otherUser, $otherTeam] = $this->newUserWithTeam();
        $foreignItem = Item::factory()->onTeam($otherTeam)->create();

        $this->actingAsFresh($user)->push($foreignItem)->assertStatus(404);
    }

    public function test_claim_is_fifo_with_targeted_jobs_first(): void
    {
        [$user, $team, $item] = $this->setUpTeam();

        $this->actingAsFresh($user);
        $job1 = $this->push($item)->assertStatus(201)->json('job.id');
        $job2 = $this->push($item)->assertStatus(201)->json('job.id');

        /* A live station is required before a targeted push is accepted */
        $this->station('client-a', 'Printer A');
        $job3 = $this->push($item, ['target_printer' => 'Printer A'])->assertStatus(201)->json('job.id');

        /* Station B: the untargeted FIFO — but never the job addressed
           to Printer A, which stays pending the whole time */
        $this->station('client-b', 'Printer B');
        $first = $this->claim('client-b', 'Printer B')->assertOk()->json('job');
        $this->assertSame($job1, $first['id']);
        $this->assertSame($item->id, $first['item_id']);
        $this->assertSame('30334', $first['template']);
        $this->assertSame($item->name, $first['item_name']);
        $second = $this->claim('client-b', 'Printer B')->assertOk()->json('job');
        $this->assertSame($job2, $second['id']);
        $third = $this->claim('client-b', 'Printer B')->assertOk()->json('job');
        $this->assertNull($third, 'a job targeted at Printer A must not go to Printer B');

        /* Station A finally collects its targeted job — queue drained */
        $fourth = $this->claim('client-a', 'Printer A')->assertOk()->json('job');
        $this->assertSame($job3, $fourth['id']);
        $fifth = $this->claim('client-a', 'Printer A')->assertOk()->json('job');
        $this->assertNull($fifth);
    }

    public function test_targeted_job_precedes_older_untargeted_for_its_station(): void
    {
        [$user, , $item] = $this->setUpTeam();

        $this->actingAsFresh($user);
        $this->station('client-a', 'Printer A');
        $untargeted = $this->push($item)->assertStatus(201)->json('job.id');
        $targeted = $this->push($item, ['target_printer' => 'Printer A'])->assertStatus(201)->json('job.id');

        /* The targeted job wins even though the untargeted one is older,
           then the station resumes the plain FIFO */
        $this->assertSame($targeted, $this->claim('client-a', 'Printer A')->json('job.id'));
        $this->assertSame($untargeted, $this->claim('client-a', 'Printer A')->json('job.id'));
    }

    public function test_claim_is_exclusive_between_two_stations(): void
    {
        [$user, , $item] = $this->setUpTeam();

        $this->actingAsFresh($user);
        $this->push($item);
        $this->push($item);

        $this->station('client-1', 'Shared DYMO');
        $this->station('client-2', 'Shared DYMO');

        $first = $this->claim('client-1', 'Shared DYMO')->json('job.id');
        $second = $this->claim('client-2', 'Shared DYMO')->json('job.id');

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertNotSame($first, $second, 'two stations must never claim the same job');
        $this->assertNull($this->claim('client-1', 'Shared DYMO')->json('job'));
    }

    public function test_claim_refreshes_the_station_heartbeat(): void
    {
        [$user, , ] = $this->setUpTeam();
        $this->actingAsFresh($user);

        $station = $this->station('client-hb', 'Heartbeat DYMO');

        /* Age the heartbeat past the liveness window… */
        $station->forceFill(['last_seen_at' => now()->subSeconds(3600)])->save();
        $this->assertFalse($station->fresh()->isLive());

        /* …a claim poll re-freshens it */
        $this->claim('client-hb', 'Heartbeat DYMO')->assertOk();
        $this->assertTrue($station->fresh()->isLive());
    }

    public function test_stale_claim_is_reclaimed(): void
    {
        [$user, , $item] = $this->setUpTeam();

        $this->actingAsFresh($user);
        $jobId = $this->push($item)->json('job.id');

        $this->station('client-dead', 'Lost DYMO');
        $claimed = $this->claim('client-dead', 'Lost DYMO')->json('job');
        $this->assertSame($jobId, $claimed['id']);

        /* The claiming tab died before completing: age the claim past the
           300s reclaim threshold */
        PrintJob::whereKey($jobId)->update(['claimed_at' => now()->subSeconds(400)]);

        $this->station('client-new', 'Lost DYMO');
        $reclaimed = $this->claim('client-new', 'Lost DYMO')->json('job');
        $this->assertSame($jobId, $reclaimed['id']);
        $this->assertSame(2, PrintJob::whereKey($jobId)->value('attempts'));
    }

    public function test_complete_marks_job_printed(): void
    {
        [$user, , $item] = $this->setUpTeam();

        $this->actingAsFresh($user);
        $jobId = $this->push($item)->json('job.id');
        $this->station('client-ok', 'Printer OK');
        $this->claim('client-ok', 'Printer OK');

        $this->complete($jobId, 'client-ok', true)->assertOk();

        $job = PrintJob::whereKey($jobId)->firstOrFail();
        $this->assertSame('printed', $job->status);
        $this->assertNotNull($job->completed_at);
        $this->assertNull($job->error);
    }

    public function test_complete_failure_retries_then_fails(): void
    {
        [$user, , $item] = $this->setUpTeam();

        $this->actingAsFresh($user);
        $jobId = $this->push($item)->json('job.id');
        $this->station('client-f', 'Flaky DYMO');

        /* attempts 1 → back to pending (retry) */
        $this->claim('client-f', 'Flaky DYMO');
        $this->complete($jobId, 'client-f', false, 'Label feed stalled')->assertOk();
        $this->assertSame('pending', PrintJob::whereKey($jobId)->value('status'));

        /* attempts 2 → still pending */
        $this->claim('client-f', 'Flaky DYMO');
        $this->complete($jobId, 'client-f', false, 'Label feed stalled')->assertOk();
        $this->assertSame('pending', PrintJob::whereKey($jobId)->value('status'));

        /* attempts 3 → terminal failed, error kept */
        $this->claim('client-f', 'Flaky DYMO');
        $this->complete($jobId, 'client-f', false, 'Label feed stalled')->assertOk();
        $failed = PrintJob::whereKey($jobId)->firstOrFail();
        $this->assertSame('failed', $failed->status);
        $this->assertSame('Label feed stalled', $failed->error);
        $this->assertNotNull($failed->completed_at);
    }

    public function test_complete_from_wrong_station_is_a_noop(): void
    {
        [$user, , $item] = $this->setUpTeam();

        $this->actingAsFresh($user);
        $jobId = $this->push($item)->json('job.id');
        $this->station('client-owner', 'Owner DYMO');
        $this->station('client-thief', 'Thief DYMO');
        $this->claim('client-owner', 'Owner DYMO');

        $this->complete($jobId, 'client-thief', true)->assertOk()
            ->assertJsonPath('success', false);

        /* Still claimed by the owner — no state was touched */
        $job = PrintJob::whereKey($jobId)->firstOrFail();
        $this->assertSame('claimed', $job->status);
        $this->assertNull($job->completed_at);
    }

    public function test_unknown_client_on_complete_is_a_noop(): void
    {
        [$user, , $item] = $this->setUpTeam();

        $this->actingAsFresh($user);
        $jobId = $this->push($item)->json('job.id');
        $this->station('client-real', 'Real DYMO');
        $this->claim('client-real', 'Real DYMO');

        $this->complete($jobId, 'client-ghost', true)->assertOk()
            ->assertJsonPath('success', false);
        $this->assertSame('claimed', PrintJob::whereKey($jobId)->value('status'));
    }

    public function test_station_declaration_upserts_by_client_id(): void
    {
        [$user] = $this->setUpTeam();

        $this->actingAsFresh($user);
        $this->postJson('/print/station', [
            'client_id' => 'client-upsert',
            'printer_name' => 'Old DYMO',
        ])->assertOk();

        $this->postJson('/print/station', [
            'client_id' => 'client-upsert',
            'printer_name' => 'New DYMO',
            'label' => 'Front desk',
            'template' => '30252',
        ])->assertOk()->assertJsonPath('station.printer_name', 'New DYMO');

        $this->assertSame(1, PrintStation::where('client_id', 'client-upsert')->count());
        $station = PrintStation::where('client_id', 'client-upsert')->firstOrFail();
        $this->assertSame('New DYMO', $station->printer_name);
        $this->assertSame('Front desk', $station->label);
        $this->assertSame('30252', $station->template);
    }
}
