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

    Route::resource('item', ItemController::class);
    Route::get('item/{item}/history', [HistoryController::class, 'index']);
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
