<?php

use App\Http\Controllers\HistoryController;
use App\Http\Controllers\ItemController;
use App\Http\Controllers\LabelController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\StatusController;
use App\Http\Controllers\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/


Route::post('/register', [UserController::class, 'register']);
Route::post('/authenticate', [UserController::class, 'authenticate']);
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function (Request $request) {
        $permissions = collect();
        foreach($request->user()->allTeams() as $team) {
            $permissions[$team->id] = $request->user()->teamPermissions($team);
        }
        return [
            "user" => $request->user(),
            "userPermissions" => $permissions,
            "tokenPermissions" => $request->user()->currentAccessToken()->abilities,
        ];
    });
    Route::get('/teams', function (Request $request) {
        return $request->user()->allTeams();
    });
    /* API-003: cheap change detection — clients poll this integer (or the
       X-Revision header on GET api/item) instead of pulling the full list */
    Route::get('/revision', function (Request $request) {
        $user = $request->user();
        $team = $user->currentTeam;

        /* Mirrors the read endpoints: team membership + at least one read ability */
        $canRead = $team instanceof \App\Models\Team
            && $user->belongsToTeam($team)
            && ($user->tokenCan('item:read') || $user->tokenCan('status:read') || $user->tokenCan('location:read'));

        if (!$canRead) {
            throw new \Illuminate\Auth\Access\AuthorizationException();
        }

        return response()->json(['revision' => (int) $team->revision]);
    });

    /* API-007: bulk operations — declared before the resource so the
       item/bulk-* paths can never be swallowed by the {item} binding. */
    Route::post('item/bulk-move', [ItemController::class, 'bulkMove']);
    Route::post('item/bulk-assign', [ItemController::class, 'bulkAssign']);
    Route::resource('item', ItemController::class);
    Route::get('item/{item}/history', [HistoryController::class, 'index']);
    /* API-004: intent verbs (anti-clobber) — POST only, the host does not
       support PATCH (load balancer). Each verb touches exactly its field(s). */
    Route::post('item/{item}/move', [ItemController::class, 'move']);
    Route::post('item/{item}/assign', [ItemController::class, 'assign']);
    Route::post('item/{item}/rename', [ItemController::class, 'rename']);
    Route::resource('status', StatusController::class);
    Route::resource('location', LocationController::class);
    
    // Label management API routes
    Route::get('labels', [LabelController::class, 'index']);
    Route::post('labels', [LabelController::class, 'store']);
    Route::patch('labels/{label}', [LabelController::class, 'update']);
    Route::delete('labels/{label}', [LabelController::class, 'destroy']);
    Route::post('item/{item}/labels', [LabelController::class, 'attachToItem']);
    Route::delete('item/{item}/labels/{label}', [LabelController::class, 'detachFromItem']);

});
