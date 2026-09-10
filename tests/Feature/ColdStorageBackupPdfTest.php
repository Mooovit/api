<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Status;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithApi;
use Tests\TestCase;

/**
 * API-031 — GET api/backups/cold-storage/pdf: the API-029 sheet as a
 * SERVER-rendered PDF (same `item:write` gate as the JSON bundle).
 */
class ColdStorageBackupPdfTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithApi;

    public function test_guests_are_rejected(): void
    {
        $this->getJson('/api/backups/cold-storage/pdf')->assertStatus(401);
    }

    public function test_a_token_without_item_write_ability_is_forbidden(): void
    {
        [$user] = $this->newUserWithTeam();

        $this->actingAsApi($user, ['item:read'])
            ->getJson('/api/backups/cold-storage/pdf')
            ->assertStatus(403);
    }

    public function test_read_only_members_are_forbidden(): void
    {
        [, $team] = $this->newUserWithTeam();
        $readOnly = $this->addTeamMember(\App\Models\User::factory()->create(), $team, 'Read Only');

        $this->actingAsApi($readOnly, ['*'])
            ->getJson('/api/backups/cold-storage/pdf')
            ->assertStatus(403);
    }

    public function test_owner_gets_a_valid_pdf_of_the_backup_sheet(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $status = Status::factory()->onTeam($team)->create(['name' => 'In stock']);
        Item::factory()->onTeam($team)->withStatus($status)->create(['name' => 'Box A']);

        $response = $this->actingAsApi($user, ['item:write'])
            ->getJson('/api/backups/cold-storage/pdf')
            ->assertOk();

        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString(
            'filename="cold-storage-' . $team->id,
            (string) $response->headers->get('Content-Disposition')
        );

        /* A tiny dataset = 1 chunk → summary page + 1 chunk page. */
        $pdf = $response->getContent();
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertSame(2, $this->pdfPageCount($pdf));
    }

    public function test_page_count_follows_the_chunk_count(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $status = Status::factory()->onTeam($team)->create(['name' => 'In stock']);
        $box = Item::factory()->onTeam($team)->withStatus($status)->create();
        /* Direct inserts — the factory's unique-word faker would exhaust. */
        for ($i = 0; $i < 60; $i++) {
            Item::create([
                'name' => "Generated box number $i with a reasonably long name",
                'team_id' => $team->id,
                'parent_id' => $box->id,
                'status_id' => $status->id,
            ]);
        }

        $chunkCount = $this->actingAsApi($user, ['item:write'])
            ->getJson('/api/backups/cold-storage')->json('chunkCount');
        $this->assertGreaterThan(1, $chunkCount);

        $pdf = $this->actingAsApi($user, ['item:write'])
            ->getJson('/api/backups/cold-storage/pdf')
            ->assertOk()
            ->getContent();

        $this->assertSame(1 + (int) ceil($chunkCount / 12), $this->pdfPageCount($pdf));
    }

    public function test_two_exports_are_byte_identical(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $status = Status::factory()->onTeam($team)->create(['name' => 'In stock']);
        Item::factory()->onTeam($team)->withStatus($status)->create(['name' => 'Box A']);

        /* Freeze the clock: the exported-at line is the only input that
           could differ between two exports of the same dataset. */
        Carbon::setTestNow(Carbon::parse('2026-09-07 10:00:00'));

        try {
            $first = $this->actingAsApi($user, ['item:write'])
                ->getJson('/api/backups/cold-storage/pdf')->getContent();
            $second = $this->actingAsApi($user, ['item:write'])
                ->getJson('/api/backups/cold-storage/pdf')->getContent();

            $this->assertSame($first, $second);
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * The page count from the PDF's /Pages object.
     */
    private function pdfPageCount(string $pdf): int
    {
        $this->assertSame(1, preg_match('/\/Count (\d+)/', $pdf, $m));

        return (int) $m[1];
    }
}
