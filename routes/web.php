<?php

use App\Http\Controllers\ItemController;
use App\Models\Item;
use App\Models\Location;
use App\Models\Status;
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

Route::get('/kanban/{type}', function(Request $request, String $type) {
    $user = $request->user();
    $team_id = $user->current_team_id;
    $query = null;

    switch ($type) {
        default:
        case "status":
            $query = ["model" => Status::class, "field" => "status_id"];
            break;
        case "location":
            $query = ["model" => Location::class, "field" => "location_id"];
            break;
    }

    $jsonData = [];
    foreach($query['model']::where(["team_id" => $team_id])->with('items')->get() as $instance) {
        $boardItem = [];
        foreach($instance->items as $item) {
            if(!$item->parent_id) {
                array_push($boardItem, [
                    "id" => $item->id,
                    "title" => $item->name
                ]);
            }
        }
        array_push($jsonData, [
            "id" => $instance->id,
            "title" => $instance->name,
            "item" => $boardItem
        ]);
    }

    return view('kanban')->with('jsonData', json_encode($jsonData))->with('field', $query['field']);
});

Route::middleware(['auth:sanctum', 'verified'])->get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->name('dashboard');
