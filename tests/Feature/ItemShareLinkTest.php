<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\ItemShareLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithApi;
use Tests\TestCase;

/**
 * API-024: public share links on items — a random capability token (never
 * the item uuid) served on the unauthenticated read-only page /share/{token},
 * managed through POST/GET/DELETE api/item/{item}/share with the same
 * authorization matrix as the barcode registry.
 */
class ItemShareLinkTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithApi;

    /**
     * Activate the link through the API and return the payload.
     */
    private function activate(User $user, Item $item): array
    {
        return $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/item/{$item->id}/share")
            ->json();
    }

    public function test_activate_creates_a_link_with_an_opaque_token(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();

        $response = $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/item/{$item->id}/share")
            ->assertStatus(201);

        /* The token is a random capability string — long, and never the
           item's uuid (that would make the uuid itself a credential). */
        $token = $response->json('token');
        $this->assertIsString($token);
        $this->assertGreaterThanOrEqual(32, strlen($token));
        $this->assertNotSame($item->id, $token);
        $this->assertSame(url('/share/' . $token), $response->json('share_url'));
        $this->assertNotNull($response->json('activated_at'));
        $this->assertNull($response->json('deactivated_at'));

        $this->assertDatabaseHas('item_share_links', [
            'item_id' => $item->id,
            'team_id' => $team->id,
            'token' => $token,
            'deactivated_at' => null,
        ]);
    }

    public function test_activate_is_idempotent_while_active(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();
        $first = $this->activate($user, $item);

        $revisionAfterFirst = $team->fresh()->revision;

        $second = $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/item/{$item->id}/share")
            ->assertOk()
            ->json();

        /* Same link, same token, no extra row, no revision churn */
        $this->assertSame($first['token'], $second['token']);
        $this->assertSame(1, ItemShareLink::count());
        $this->assertSame($revisionAfterFirst, $team->fresh()->revision);
    }

    public function test_show_state_without_link_is_an_empty_shape(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();

        $this->actingAsApi($user, ['item:read'])
            ->getJson("/api/item/{$item->id}/share")
            ->assertOk()
            ->assertJson([
                'share_url' => null,
                'token' => null,
                'activated_at' => null,
                'deactivated_at' => null,
            ]);
    }

    public function test_show_state_returns_the_active_link(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();
        $payload = $this->activate($user, $item);

        $this->actingAsApi($user, ['item:read'])
            ->getJson("/api/item/{$item->id}/share")
            ->assertOk()
            ->assertJsonPath('token', $payload['token'])
            ->assertJsonPath('share_url', $payload['share_url']);
    }

    public function test_revoke_kills_the_public_url_and_nulls_the_state(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();
        $payload = $this->activate($user, $item);

        $this->get("/share/{$payload['token']}")->assertOk();

        $this->actingAsApi($user, ['item:write'])
            ->deleteJson("/api/item/{$item->id}/share")
            ->assertOk()
            ->assertJsonPath('success', true);

        /* The public URL is dead immediately... */
        $this->get("/share/{$payload['token']}")->assertStatus(404);

        /* ...and the API state exposes nothing usable */
        $state = $this->actingAsApi($user, ['item:read'])
            ->getJson("/api/item/{$item->id}/share")
            ->assertOk()
            ->json();
        $this->assertNull($state['share_url']);
        $this->assertNull($state['token']);
        $this->assertNotNull($state['deactivated_at']);

        /* Revoking again is idempotent */
        $revisionAfterRevoke = $team->fresh()->revision;
        $this->actingAsApi($user, ['item:write'])
            ->deleteJson("/api/item/{$item->id}/share")
            ->assertOk();
        $this->assertSame($revisionAfterRevoke, $team->fresh()->revision);
    }

    public function test_reactivating_a_revoked_link_issues_a_fresh_token(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();
        $first = $this->activate($user, $item);

        $this->actingAsApi($user, ['item:write'])
            ->deleteJson("/api/item/{$item->id}/share")
            ->assertOk();

        $second = $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/item/{$item->id}/share")
            ->assertOk()
            ->json();

        /* FRESH token: the old URL stays permanently dead, still one row */
        $this->assertNotSame($first['token'], $second['token']);
        $this->assertSame(1, ItemShareLink::count());
        $this->get("/share/{$first['token']}")->assertStatus(404);
        $this->get("/share/{$second['token']}")->assertOk();
    }

    public function test_public_page_renders_box_and_contents_without_identifiers(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $status = \App\Models\Status::factory()->onTeam($team)->create(['name' => 'PublicStatusName']);
        $location = \App\Models\Location::factory()->onTeam($team)->create(['name' => 'PublicLocationName']);
        $item = Item::factory()->onTeam($team)
            ->withStatus($status)
            ->inLocation($location)
            ->create(['name' => 'SecretBoxName']);
        $child = Item::factory()->onTeam($team)
            ->withStatus($status)
            ->inLocation($location)
            ->childOf($item)
            ->create(['name' => 'PublicChildName']);

        $payload = $this->activate($user, $item);

        /* No authentication at all */
        $response = $this->get("/share/{$payload['token']}")->assertOk();

        /* Safe details visible */
        $response->assertSee('SecretBoxName');
        $response->assertSee('PublicChildName');
        $response->assertSee('PublicStatusName');
        $response->assertSee('PublicLocationName');

        /* Nothing identifying: uuids, team data, owner, parent pointer */
        $html = $response->getContent();
        $this->assertStringNotContainsString($item->id, $html);
        $this->assertStringNotContainsString($child->id, $html);
        $this->assertStringNotContainsString((string) $team->id, $html);
        $this->assertStringNotContainsString($user->email, $html);
        $this->assertStringNotContainsString($user->name, $html);
    }

    public function test_public_page_unknown_or_revoked_token_is_a_plain_404(): void
    {
        $this->get('/share/not-a-real-token')->assertStatus(404);
    }

    public function test_public_page_for_a_trashed_box_is_a_404(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create(['name' => 'TrashedBox']);
        $payload = $this->activate($user, $item);

        $item->delete();

        /* The soft-delete global scope hides the box: shared-then-deleted
           boxes stop resolving (no content leak through the token). */
        $this->get("/share/{$payload['token']}")->assertStatus(404);
    }

    public function test_share_routes_require_authentication(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();

        $this->postJson("/api/item/{$item->id}/share")->assertStatus(401);
        $this->getJson("/api/item/{$item->id}/share")->assertStatus(401);
        $this->deleteJson("/api/item/{$item->id}/share")->assertStatus(401);
    }

    public function test_permission_matrix(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();

        /* Token lacks the write ability */
        $this->actingAsApi($user, ['item:read'])
            ->postJson("/api/item/{$item->id}/share")
            ->assertStatus(403);
        $this->actingAsApi($user, ['item:read'])
            ->deleteJson("/api/item/{$item->id}/share")
            ->assertStatus(403);

        /* Read-Only team member with a full-ability token */
        [$member] = $this->newUserWithTeam();
        $this->addTeamMember($member, $team, 'Read Only');
        $this->actingAsApi($member, ['item:write'])
            ->postJson("/api/item/{$item->id}/share")
            ->assertStatus(403);

        /* Foreign-team item */
        [$stranger, $otherTeam] = $this->newUserWithTeam();
        $foreign = Item::factory()->onTeam($otherTeam)->create();
        $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/item/{$foreign->id}/share")
            ->assertStatus(403);

        /* Reads need the read ability + membership, but no row → empty shape */
        $this->assertDatabaseCount('item_share_links', 0);
        $this->actingAsApi($user, ['item:read'])
            ->getJson("/api/item/{$item->id}/share")
            ->assertOk();
    }

    public function test_mutations_bump_revision_once_each(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();
        $before = $team->fresh()->revision;

        $payload = $this->activate($user, $item);
        $this->assertSame($before + 1, $team->fresh()->revision);

        /* Idempotent re-activation: no churn */
        $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/item/{$item->id}/share")
            ->assertOk();
        $this->assertSame($before + 1, $team->fresh()->revision);

        /* Revoke bumps once more */
        $this->actingAsApi($user, ['item:write'])
            ->deleteJson("/api/item/{$item->id}/share")
            ->assertOk();
        $this->assertSame($before + 2, $team->fresh()->revision);
    }
}
