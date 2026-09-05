<?php

use App\Http\Controllers\ItemController;
use App\Http\Controllers\KanbanController;
use App\Http\Controllers\BackupScheduleController;
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

// Kanban routes
Route::middleware('auth:sanctum')->group(function () {
    // Specific routes must come BEFORE generic {type} route
    Route::get('/kanban/activity', [KanbanController::class, 'activity']);
    Route::get('/kanban/history', [KanbanController::class, 'getRecentHistory']);
    Route::get('/kanban/search', [KanbanController::class, 'search']);
    Route::get('/kanban/item/{itemId}', [KanbanController::class, 'getItemDetails']);
    Route::post('/kanban/update-item', [KanbanController::class, 'updateItemByBarcode']);
    Route::post('/kanban/status', [KanbanController::class, 'createStatus']);
    Route::patch('/kanban/status/{statusId}', [KanbanController::class, 'updateStatus']);
    Route::delete('/kanban/status/{statusId}', [KanbanController::class, 'deleteStatus']);
    Route::post('/kanban/location', [KanbanController::class, 'createLocation']);
    Route::patch('/kanban/location/{locationId}', [KanbanController::class, 'updateLocation']);
    Route::delete('/kanban/location/{locationId}', [KanbanController::class, 'deleteLocation']);
    
    // Generic route must come LAST
    Route::get('/kanban/{type}', [KanbanController::class, 'index']);
    Route::get('/kanban/labels', [KanbanController::class, 'getLabels']);
    Route::post('/kanban/label', [KanbanController::class, 'createLabel']);
    Route::patch('/kanban/label/{labelId}', [KanbanController::class, 'updateLabel']);
    Route::delete('/kanban/label/{labelId}', [KanbanController::class, 'deleteLabel']);
    Route::post('/kanban/item/{itemId}/labels', [KanbanController::class, 'addLabelToItem']);
    Route::delete('/kanban/item/{itemId}/labels/{labelId}', [KanbanController::class, 'removeLabelFromItem']);
});

Route::middleware(['auth:sanctum', 'verified'])->get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->name('dashboard');

// Auto-backup schedules (API-020) — server UI, one schedule per team;
// POST for writes (host load balancer — no PATCH), DELETE where natural.
Route::middleware(['auth:sanctum', 'verified'])->group(function () {
    Route::get('/teams/{team}/backups/schedule', [BackupScheduleController::class, 'show'])
        ->name('backups.schedules.show');
    Route::post('/teams/{team}/backups/schedule', [BackupScheduleController::class, 'store'])
        ->name('backups.schedules.store');
    Route::delete('/teams/{team}/backups/schedule', [BackupScheduleController::class, 'destroy'])
        ->name('backups.schedules.destroy');
});
