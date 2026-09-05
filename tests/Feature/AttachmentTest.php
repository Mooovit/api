<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithApi;
use Tests\TestCase;

/**
 * API-013: image attachments on items — upload/list/download/delete with
 * metadata-only serialization (no filesystem paths in payloads).
 */
class AttachmentTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithApi;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    /**
     * Upload a fake image through the API and return the created row.
     */
    private function upload(User $user, Item $item, string $name = 'photo.jpg', array $extra = []): Attachment
    {
        $response = $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/item/{$item->id}/attachments", array_merge([
                'file' => UploadedFile::fake()->image($name),
                'caption' => null,
            ], $extra))
            ->assertStatus(201);

        return Attachment::findOrFail($response->json('id'));
    }

    public function test_upload_list_download_delete_round_trip(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();
        $revisionBefore = $team->fresh()->revision;

        /* Upload */
        $upload = UploadedFile::fake()->image('seal.jpg');
        $uploadedBytes = file_get_contents($upload->getRealPath());

        $response = $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/item/{$item->id}/attachments", [
                'file' => $upload,
                'caption' => 'Box seal',
            ])
            ->assertStatus(201);

        $created = Attachment::findOrFail($response->json('id'));
        $this->assertSame('seal.jpg', $created->original_name);
        $this->assertSame('image/jpeg', $created->mime_type);
        $this->assertSame('Box seal', $created->caption);
        $this->assertSame($user->id, $created->user_id);

        /* File on disk matches the uploaded bytes */
        Storage::disk('local')->assertExists($created->path);
        $this->assertSame($uploadedBytes, Storage::disk('local')->get($created->path));
        $this->assertSame($revisionBefore + 1, $team->fresh()->revision);

        /* List — metadata only, newest first */
        $rows = $this->actingAsApi($user, ['item:read'])
            ->getJson("/api/item/{$item->id}/attachments")
            ->assertOk()
            ->json();
        $this->assertCount(1, $rows);
        $this->assertSame($created->id, $rows[0]['id']);
        $this->assertStringContainsString("/api/attachment/{$created->id}", $rows[0]['url']);
        $this->assertArrayNotHasKey('path', $rows[0]);
        $this->assertArrayNotHasKey('disk', $rows[0]);

        /* Download — bytes match the uploaded file (StreamedResponse, so
           read through streamedContent()) */
        $this->app['auth']->forgetGuards();
        $download = $this->withToken($this->tokenFor($user, ['item:read']))
            ->get("/api/attachment/{$created->id}")
            ->assertOk();
        $this->assertSame($uploadedBytes, $download->streamedContent());

        /* Delete — row + file gone, revision bumped again */
        $this->actingAsApi($user, ['item:write'])
            ->deleteJson("/api/attachment/{$created->id}")
            ->assertOk()
            ->assertJsonPath('success', 'success');
        Storage::disk('local')->assertMissing($created->path);
        $this->assertDatabaseMissing('attachments', ['id' => $created->id]);
        $this->assertSame($revisionBefore + 2, $team->fresh()->revision);
    }

    /**
     * Mint a token directly (helper for binary downloads, where multipart
     * json helpers don't apply).
     */
    private function tokenFor(User $user, array $abilities): string
    {
        return explode('|', $user->createToken('t', $abilities)->plainTextToken)[1];
    }

    public function test_item_detail_payload_lists_attachment_metadata(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();

        /* Empty array when none — additive field, always present */
        $shown = $this->actingAsApi($user, ['item:read'])
            ->getJson("/api/item/{$item->id}")
            ->assertOk();
        $this->assertSame([], $shown->json('attachments'));

        $created = $this->upload($user, $item);

        $shown = $this->actingAsApi($user, ['item:read'])
            ->getJson("/api/item/{$item->id}")
            ->assertOk();
        $rows = $shown->json('attachments');
        $this->assertCount(1, $rows);
        $this->assertSame($created->id, $rows[0]['id']);
        $this->assertSame('photo.jpg', $rows[0]['original_name']);
        $this->assertArrayNotHasKey('path', $rows[0]);
    }

    public function test_list_is_newest_first(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();
        $oldest = $this->upload($user, $item, 'first.jpg');

        DB::table('attachments')->where('id', $oldest->id)
            ->update(['created_at' => now()->subMinutes(10)]);

        $newest = $this->upload($user, $item, 'second.jpg');

        $rows = $this->actingAsApi($user, ['item:read'])
            ->getJson("/api/item/{$item->id}/attachments")
            ->assertOk()
            ->json();

        $this->assertSame([$newest->id, $oldest->id], array_column($rows, 'id'));
    }

    public function test_validation_rejects_non_images_and_oversize(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();

        /* Non-image mime */
        $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/item/{$item->id}/attachments", [
                'file' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        /* > 10 MB (max:10240 kb) */
        $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/item/{$item->id}/attachments", [
                'file' => UploadedFile::fake()->create('huge.jpg', 11000, 'image/jpeg'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->assertDatabaseCount('attachments', 0);
    }

    public function test_permission_matrix(): void
    {
        [$user, $team] = $this->newUserWithTeam();
        $item = Item::factory()->onTeam($team)->create();

        /* Write routes need item:write: token without it → 403 */
        $this->actingAsApi($user, ['item:read'])
            ->postJson("/api/item/{$item->id}/attachments", [
                'file' => UploadedFile::fake()->image('x.jpg'),
            ])
            ->assertStatus(403);

        /* Read-Only team member: cannot write, can read */
        [$member] = $this->newUserWithTeam();
        $this->addTeamMember($member, $team, 'Read Only');

        $this->actingAsApi($member, ['item:write'])
            ->postJson("/api/item/{$item->id}/attachments", [
                'file' => UploadedFile::fake()->image('x.jpg'),
            ])
            ->assertStatus(403);

        $created = $this->upload($user, $item);

        $this->actingAsApi($member, ['item:read'])
            ->getJson("/api/item/{$item->id}/attachments")
            ->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($this->tokenFor($member, ['item:read']))
            ->get("/api/attachment/{$created->id}")
            ->assertOk();
        $this->actingAsApi($member, ['item:write'])
            ->deleteJson("/api/attachment/{$created->id}")
            ->assertStatus(403);

        /* Foreign-team item: all four verbs 403 */
        [$stranger, $otherTeam] = $this->newUserWithTeam();
        $foreign = Item::factory()->onTeam($otherTeam)->create();
        $foreignAttachment = $this->upload($stranger, $foreign);

        $this->actingAsApi($user, ['item:write'])
            ->postJson("/api/item/{$foreign->id}/attachments", [
                'file' => UploadedFile::fake()->image('y.jpg'),
            ])
            ->assertStatus(403);
        $this->actingAsApi($user, ['item:read'])
            ->getJson("/api/item/{$foreign->id}/attachments")
            ->assertStatus(403);
        $this->actingAsApi($user, ['item:read'])
            ->getJson("/api/attachment/{$foreignAttachment->id}")
            ->assertStatus(403);
        $this->actingAsApi($user, ['item:write'])
            ->deleteJson("/api/attachment/{$foreignAttachment->id}")
            ->assertStatus(403);

        $this->assertDatabaseCount('attachments', 2);
    }

    public function test_unknown_attachment_404(): void
    {
        [$user] = $this->newUserWithTeam();

        $this->actingAsApi($user, ['item:read'])
            ->getJson('/api/attachment/00000000-0000-0000-0000-000000000000')
            ->assertStatus(404);
        $this->actingAsApi($user, ['item:write'])
            ->deleteJson('/api/attachment/00000000-0000-0000-0000-000000000000')
            ->assertStatus(404);
    }
}
