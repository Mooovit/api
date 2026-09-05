<?php

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\DeviceTokenController;
use App\Http\Controllers\DeviceTeamController;
use App\Http\Controllers\EnrollmentCodeController;
use App\Http\Controllers\HistoryController;
use App\Http\Controllers\ItemController;
use App\Http\Controllers\LabelController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\ItemBarcodeController;
use App\Http\Controllers\StatusController;
use App\Http\Controllers\TeamS3Controller;
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
/* API-017: scan-to-enroll — exchange a one-time code for a device token
   (unauthenticated by design: the code IS the proof) */
Route::post('/enroll', [EnrollmentCodeController::class, 'enroll']);
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
        /* API-015: the token's current team when set, the user's otherwise */
        $team = $user->effectiveTeam();

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
    /* API-009: team-wide activity feed (filterable, paginated) */
    Route::get('activity', [ActivityController::class, 'index']);
    /* API-010: audit/stocktake snapshots per location */
    Route::get('location/{location}/audits', [AuditController::class, 'index']);
    Route::post('location/{location}/audits', [AuditController::class, 'store']);
    /* API-004: intent verbs (anti-clobber) — POST only, the host does not
       support PATCH (load balancer). Each verb touches exactly its field(s). */
    Route::post('item/{item}/move', [ItemController::class, 'move']);
    Route::post('item/{item}/assign', [ItemController::class, 'assign']);
    Route::post('item/{item}/rename', [ItemController::class, 'rename']);
    /* API-016: cross-team item transfer (permission checks on both teams) */
    Route::post('item/{item}/transfer', [ItemController::class, 'transfer']);
    Route::resource('status', StatusController::class);
    Route::resource('location', LocationController::class);
    
    // Label management API routes
    Route::get('labels', [LabelController::class, 'index']);
    Route::post('labels', [LabelController::class, 'store']);
    Route::patch('labels/{label}', [LabelController::class, 'update']);
    Route::delete('labels/{label}', [LabelController::class, 'destroy']);
    Route::post('item/{item}/labels', [LabelController::class, 'attachToItem']);
    Route::delete('item/{item}/labels/{label}', [LabelController::class, 'detachFromItem']);

    /* API-011: per-team barcode registry on items */
    Route::post('item/{item}/barcodes', [ItemBarcodeController::class, 'attach']);
    Route::delete('item/{item}/barcodes/{barcode}', [ItemBarcodeController::class, 'detach']);

    /* API-012: named, revocable device tokens for the PDA fleet */
    Route::post('device-tokens', [DeviceTokenController::class, 'store']);
    Route::get('device-tokens', [DeviceTokenController::class, 'index']);
    Route::delete('device-tokens/{id}', [DeviceTokenController::class, 'destroy']);

    /* API-017: one-time, short-lived enrollment codes for new PDAs */
    Route::post('enrollment-codes', [EnrollmentCodeController::class, 'store']);

    /* API-015: per-device (per-token) current team — fetch + switch */
    Route::get('device-team', [DeviceTeamController::class, 'show']);
    Route::post('device-team', [DeviceTeamController::class, 'update']);

    /* API-013: image attachments on items */
    Route::post('item/{item}/attachments', [AttachmentController::class, 'store']);
    Route::get('item/{item}/attachments', [AttachmentController::class, 'index']);
    Route::get('attachment/{attachment}', [AttachmentController::class, 'show']);
    Route::delete('attachment/{attachment}', [AttachmentController::class, 'destroy']);

    /* API-018: savepoint backups — CSV snapshot per team, last 7 kept */
    Route::get('backups', [BackupController::class, 'index']);
    Route::post('backups', [BackupController::class, 'store']);
    /* API-021: objects in the team's configured bucket (declared before
       the {backup} binding can't conflict — 'backups' vs 'backup' — but
       keep list routes together) */
    Route::get('backups/bucket', [BackupController::class, 'bucket']);
    Route::get('backup/{backup}', [BackupController::class, 'show']);
    Route::delete('backup/{backup}', [BackupController::class, 'destroy']);
    /* API-019: diff two savepoints (base → target) */
    Route::get('backup/{backup}/compare/{other}', [BackupController::class, 'compare']);

    /* API-021: per-team S3 offload credentials (upsert / read / drop) and
       the computed retention rules (local last-7 + bucket lifecycle) */
    Route::post('team/s3', [TeamS3Controller::class, 'store']);
    Route::get('team/s3', [TeamS3Controller::class, 'show']);
    Route::get('team/s3/rules', [TeamS3Controller::class, 'rules']);
    Route::delete('team/s3', [TeamS3Controller::class, 'destroy']);

});
