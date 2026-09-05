<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\Item;
use App\Support\TeamRevision;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Image attachments on items (API-013).
 *
 * Storage layout: `attachments/{team_id}/{item_id}/{uuid}.{ext}` on the
 * configured disk (`local` by default — swap via config/filesystems.php, the
 * code only talks to `Storage`). Every client-facing shape flows through
 * `Attachment::metadata()` — filesystem paths never leave the server; the
 * download URL requires an authenticated token, it is not a public link.
 *
 * Attachment writes bump the team revision (API-003) so delta-syncing
 * clients know to re-pull; the item row itself is not touched.
 */
class AttachmentController extends Controller
{
    /**
     * Authorization shared by all attachment verbs: the owning item's team
     * permission + token ability (the API-001-corrected pattern — never
     * `current_team`).
     *
     * @param Request $request
     * @param Item $item
     * @param string $permission 'item:read' or 'item:write'
     * @throws AuthorizationException
     */
    private function authorizeAttachment(Request $request, Item $item, string $permission): void
    {
        $user = $request->user();
        if (!$user->hasTeamPermission($item->team, $permission) ||
            !$user->tokenCan($permission)
        ) {
            throw new AuthorizationException();
        }
    }

    /**
     * POST api/item/{item}/attachments (API-013) — multipart upload:
     * `file` (required image: jpeg/png/webp/heic, max 10 MB) + optional
     * `caption`. Mime is guessed from the file contents (finfo), never from
     * the client string alone. Returns the attachment metadata (201).
     *
     * @param Request $request
     * @param Item $item
     * @return JsonResponse
     * @throws AuthorizationException|ValidationException
     */
    public function store(Request $request, Item $item): JsonResponse
    {
        $this->authorizeAttachment($request, $item, 'item:write');

        $data = $request->validate([
            'file' => 'required|file|mimes:jpeg,jpg,png,webp,heic|max:10240',
            'caption' => 'nullable|string|max:255',
        ]);

        $file = $request->file('file');
        $filename = Str::uuid()->toString() . '.' . $file->extension();
        $path = $file->storeAs("attachments/{$item->team_id}/{$item->id}", $filename, 'local');

        $attachment = Attachment::create([
            'item_id' => $item->id,
            'team_id' => $item->team_id,
            'user_id' => $request->user()->id,
            'disk' => 'local',
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'caption' => $data['caption'] ?? null,
        ]);

        /* Attachment rows fire no revision observer — bump explicitly */
        TeamRevision::bump($attachment);

        return response()->json($attachment->metadata(), 201);
    }

    /**
     * GET api/item/{item}/attachments (API-013) — metadata list, newest
     * first (`created_at desc, id desc`).
     *
     * @param Request $request
     * @param Item $item
     * @return JsonResponse
     * @throws AuthorizationException
     */
    public function index(Request $request, Item $item): JsonResponse
    {
        $this->authorizeAttachment($request, $item, 'item:read');

        $rows = $item->attachments()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Attachment $attachment) => $attachment->metadata())
            ->values();

        return response()->json($rows);
    }

    /**
     * GET api/attachment/{attachment} (API-013) — stream the binary with the
     * stored mime type. `item:read` on the owning item's team.
     *
     * @param Request $request
     * @param Attachment $attachment
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     * @throws AuthorizationException
     */
    public function show(Request $request, Attachment $attachment)
    {
        $this->authorizeAttachment($request, $attachment->item, 'item:read');

        return Storage::disk($attachment->disk)->response(
            $attachment->path,
            $attachment->original_name ?? 'attachment',
            ['Content-Type' => $attachment->mime_type]
        );
    }

    /**
     * DELETE api/attachment/{attachment} (API-013) — remove the file and the
     * row; anyone with `item:write` on the owning team (uploader included).
     * Bumps the team revision once.
     *
     * @param Request $request
     * @param Attachment $attachment
     * @return JsonResponse
     * @throws AuthorizationException
     */
    public function destroy(Request $request, Attachment $attachment): JsonResponse
    {
        $this->authorizeAttachment($request, $attachment->item, 'item:write');

        DB::transaction(function () use ($attachment) {
            $attachment->delete();
            Storage::disk($attachment->disk)->delete($attachment->path);
        });

        /* The row is gone — bump via the item (same team target) */
        TeamRevision::bump($attachment->item);

        return response()->json(['success' => 'success']);
    }
}
