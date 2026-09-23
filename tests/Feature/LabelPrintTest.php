<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Status;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithApi;
use Tests\TestCase;

/**
 * API-038 — the /label-print print station page (session web surface):
 * auth gating, team scoping, and that the page ships the DYMO printing
 * assets plus the kanban board's print hooks. The actual printing runs
 * client-side in the browser (vendored DYMO Connect framework +
 * public/js/dymo.js against the workstation's DYMO Connect service) and
 * cannot be exercised here — the ticket carries the manual hardware
 * checklist.
 */
class LabelPrintTest extends TestCase
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

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/label-print')->assertRedirect(route('login'));
    }

    public function test_page_renders_with_dymo_assets_for_session_user(): void
    {
        [$user] = $this->newUserWithTeam();

        $this->actingAsFresh($user)->get('/label-print')
            ->assertOk()
            ->assertViewIs('label-print')
            ->assertViewHas('teamName')
            ->assertSee('labelScanInput')
            ->assertSee('js/dymo.js')
            ->assertSee('js/vendor/dymo.connect.framework.js')
            ->assertSee('js/label-print.js');
    }

    public function test_user_without_a_current_team_gets_404(): void
    {
        $user = User::factory()->create();

        $this->actingAsFresh($user)->get('/label-print')->assertNotFound();
    }

    public function test_kanban_board_ships_the_print_hooks(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $status = Status::factory()->onTeam($team)->create();
        $item = Item::factory()->onTeam($team)->withStatus($status)->create();

        $this->actingAsFresh($user)->get('/kanban/status')
            ->assertOk()
            ->assertSee('dymo-print')
            ->assertSee('printItemLabel')
            ->assertSee('js/dymo.js')
            ->assertSee('Label Print Station');

        /* The server-rendered card itself carries the print button */
        $this->actingAsFresh($user)->get('/kanban/status')
            ->assertSee('printItemLabel(\'' . $item->id . '\', this)', false);
    }
}
