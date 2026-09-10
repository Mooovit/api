<?php

namespace App\Http\Controllers;

use App\Models\ItemShareLink;

/**
 * API-024: the unauthenticated, read-only public page behind a share link.
 * The token IS the credential — an unknown, revoked, or (via the soft-delete
 * global scope) trashed-box token is a plain 404 with no hint that the token
 * ever existed. Only safe details are rendered: box name, its status/location
 * names, and the contained boxes' names — never ids, team data, or owners.
 */
class PublicShareController extends Controller
{
    /**
     * GET /share/{token} — public read-only view of one box's contents.
     *
     * @param  string  $token
     * @return \Illuminate\View\View
     */
    public function show(string $token)
    {
        $link = ItemShareLink::where('token', $token)->active()->first();

        /* `$item` stays null for a soft-deleted box: the default (API-005)
           scope excludes trashed rows, so shared-then-deleted boxes 404. */
        $item = $link ? $link->item : null;

        if (!$item) {
            abort(404);
        }

        $item->load([
            'status',
            'location',
            'childrens' => function ($query) {
                $query->with(['status', 'location'])->orderBy('name');
            },
        ]);

        return view('public.box', [
            'box' => $item,
            'contents' => $item->childrens,
        ]);
    }
}
