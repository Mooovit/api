<?php

namespace App\Http\Controllers;

use App\Models\Team;
use Illuminate\Http\Request;

/**
 * API-038 — the label print station: scan a box (its uuid QR or any printed
 * sticker), see the contents, touch a row to print a DYMO sticker (item
 * name + the item's uuid as Code128 and QR) for any item inside.
 *
 * Printing happens client-side through the vendored DYMO Connect framework
 * (public/js/dymo.js) against the workstation's local DYMO Connect service —
 * this controller only serves the page. Item data stays gated by
 * KanbanController::getItemDetails (item:read), which the page reuses.
 *
 * Gate: session user (auth:sanctum) with a current team, matching the
 * kanban pages.
 */
class LabelPrintController extends Controller
{
    /**
     * GET /label-print — the print station page.
     *
     * @param Request $request
     * @return \Illuminate\Contracts\View\View
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $team = Team::find($user->current_team_id);
        abort_unless($team, 404);

        return view('label-print', ['teamName' => $team->name]);
    }
}
