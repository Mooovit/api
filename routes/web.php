<?php

use App\Http\Controllers\ItemController;
use App\Http\Controllers\KanbanController;
use App\Http\Controllers\LabelPrintController;
use App\Http\Controllers\PublicShareController;
use App\Http\Controllers\BackupSheetController;
use App\Http\Controllers\BackupScheduleController;
use App\Http\Controllers\BackupsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DevicesController;
use App\Http\Controllers\TeamS3SettingsController;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::post('/item/{item}', [ItemController::class, 'update']);

// API-024: public share links — unauthenticated, read-only box contents
// behind a random capability token (managed via POST/DELETE api/item/{item}/share).
// Registered before the authed groups; the token is the credential, unknown
// or revoked tokens are a plain 404.
Route::get('/share/{token}', [PublicShareController::class, 'show']);

// Kanban routes — fixed sub-paths BEFORE the generic {type} route; writes
// are POST (host load balancer — no PATCH), deletes stay DELETE where natural.
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/kanban/activity', [KanbanController::class, 'activity']);
    Route::get('/kanban/history', [KanbanController::class, 'getRecentHistory']);
    /* API-029: the printable cold-storage backup sheet (owner/admin gated) */
    Route::get('/kanban/backup', [BackupSheetController::class, 'sheet']);
    Route::get('/kanban/search', [KanbanController::class, 'search']);
    Route::get('/kanban/revision', [KanbanController::class, 'revision']);
    Route::get('/kanban/delta', [KanbanController::class, 'delta']);
    Route::get('/kanban/item/{itemId}', [KanbanController::class, 'getItemDetails']);
    Route::post('/kanban/update-item', [KanbanController::class, 'updateItemByBarcode']);
    Route::post('/kanban/status', [KanbanController::class, 'createStatus']);
    Route::post('/kanban/status/{statusId}', [KanbanController::class, 'updateStatus']);
    Route::delete('/kanban/status/{statusId}', [KanbanController::class, 'deleteStatus']);
    Route::post('/kanban/location', [KanbanController::class, 'createLocation']);
    Route::post('/kanban/location/{locationId}', [KanbanController::class, 'updateLocation']);
    Route::delete('/kanban/location/{locationId}', [KanbanController::class, 'deleteLocation']);
    Route::get('/kanban/labels', [KanbanController::class, 'getLabels']);
    Route::post('/kanban/label', [KanbanController::class, 'createLabel']);
    Route::post('/kanban/label/{labelId}', [KanbanController::class, 'updateLabel']);
    Route::delete('/kanban/label/{labelId}', [KanbanController::class, 'deleteLabel']);
    Route::post('/kanban/item/{itemId}/labels', [KanbanController::class, 'addLabelToItem']);
    Route::delete('/kanban/item/{itemId}/labels/{labelId}', [KanbanController::class, 'removeLabelFromItem']);
    Route::post('/kanban/item/{itemId}/barcodes', [KanbanController::class, 'attachBarcode']);
    Route::delete('/kanban/item/{itemId}/barcodes/{barcode}', [KanbanController::class, 'detachBarcode']);

    // Public share links (API-024/025) — session flavor for the kanban page
    // (it cannot call /api/* — no stateful session group there). Semantics
    // are shared with the API trio via App\Support\ItemShare.
    Route::post('/kanban/item/{itemId}/share', [KanbanController::class, 'shareItem']);
    Route::delete('/kanban/item/{itemId}/share', [KanbanController::class, 'unshareItem']);

    // API-038: label print station — scan a box, touch contents to print
    // DYMO stickers. Top-level path (no /kanban/{type} collision); item
    // data itself stays gated by GET /kanban/item/{itemId}.
    Route::get('/label-print', [LabelPrintController::class, 'index'])
        ->name('label-print.index');

    // Generic route must come LAST
    Route::get('/kanban/{type}', [KanbanController::class, 'index']);
});

Route::middleware(['auth:sanctum', 'verified'])->get('/dashboard', [DashboardController::class, 'index'])
    ->name('dashboard');

// Auto-backup schedules (API-020) — server UI, one schedule per team;
// POST for writes (host load balancer — no PATCH), DELETE where natural.
// Backups (API-018/019) + S3 offload (API-021) follow the same pattern;
// fixed sub-paths (s3, compare) are registered BEFORE the {backup} routes.
Route::middleware(['auth:sanctum', 'verified'])->group(function () {
    Route::get('/teams/{team}/backups/schedule', [BackupScheduleController::class, 'show'])
        ->name('backups.schedules.show');
    Route::post('/teams/{team}/backups/schedule', [BackupScheduleController::class, 'store'])
        ->name('backups.schedules.store');
    Route::delete('/teams/{team}/backups/schedule', [BackupScheduleController::class, 'destroy'])
        ->name('backups.schedules.destroy');

    Route::get('/teams/{team}/backups', [BackupsController::class, 'index'])
        ->name('backups.index');
    Route::post('/teams/{team}/backups', [BackupsController::class, 'store'])
        ->name('backups.store');
    Route::post('/teams/{team}/backups/compare', [BackupsController::class, 'compare'])
        ->name('backups.compare');
    Route::get('/teams/{team}/backups/s3', [TeamS3SettingsController::class, 'show'])
        ->name('backups.s3.show');
    Route::post('/teams/{team}/backups/s3', [TeamS3SettingsController::class, 'store'])
        ->name('backups.s3.store');
    Route::delete('/teams/{team}/backups/s3', [TeamS3SettingsController::class, 'destroy'])
        ->name('backups.s3.destroy');
    /* Session flavor of the API-031 cold-storage PDF (dashboard print link) */
    Route::get('/teams/{team}/backups/cold-storage/pdf', [BackupsController::class, 'coldStoragePdf'])
        ->name('backups.cold-storage.pdf');
    Route::get('/teams/{team}/backups/{backup}/download', [BackupsController::class, 'download'])
        ->name('backups.download');
    Route::delete('/teams/{team}/backups/{backup}', [BackupsController::class, 'destroy'])
        ->name('backups.destroy');
});

// Devices (API-012/017) — server UI: token mint (plain text flashed once),
// fleet list, revoke, enrollment codes. User-level, like the API endpoints.
Route::middleware(['auth:sanctum', 'verified'])->group(function () {
    Route::get('/devices', [DevicesController::class, 'index'])
        ->name('devices.index');
    Route::post('/devices/tokens', [DevicesController::class, 'storeToken'])
        ->name('devices.tokens.store');
    Route::delete('/devices/tokens', [DevicesController::class, 'destroyAllTokens'])
        ->name('devices.tokens.destroyAll');
    Route::delete('/devices/tokens/{token}', [DevicesController::class, 'destroyToken'])
        ->name('devices.tokens.destroy');
    Route::post('/devices/tokens/{token}/team', [DevicesController::class, 'updateTokenTeam'])
        ->name('devices.tokens.team');
    Route::post('/devices/enrollment-codes', [DevicesController::class, 'mintCode'])
        ->name('devices.codes.store');
});
