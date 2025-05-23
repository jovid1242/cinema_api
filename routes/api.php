<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\MovieController;
use App\Http\Controllers\HallController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\StatisticsController;


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

Route::get('/ping', function () {
    return response()->json(['status' => 'Laravel is working']);
});

Route::middleware('auth:api')->get('/user', function (Request $request) {
    return $request->user();
});

// Маршруты для получения фильмов (без авторизации)
Route::get('movies', [MovieController::class, 'index']);
Route::get('movies/{movie}', [MovieController::class, 'show']);

Route::group([
    'middleware' => 'api',
    'prefix' => 'auth'
], function ($router) {
    Route::post('login', [AuthController::class, 'login'])->name('login');
    Route::post('register', [AuthController::class, 'register']);
    Route::post('logout', [AuthController::class, 'logout']);
    Route::get('get_me', [AuthController::class, 'getMe']);
});

Route::middleware('auth:api')->group(function () {
    // Остальные маршруты для фильмов (требуют авторизации)
    Route::post('movies', [MovieController::class, 'store']);
    Route::put('movies/{movie}', [MovieController::class, 'update']);
    Route::patch('movies/{movie}', [MovieController::class, 'update']);
    Route::delete('movies/{movie}', [MovieController::class, 'destroy']);

    Route::apiResource('halls', HallController::class);
    Route::apiResource('sessions', SessionController::class);
    Route::get('/tickets', [TicketController::class, 'index']);
    Route::post('/tickets', [TicketController::class, 'store']);
    Route::get('/tickets/{id}', [TicketController::class, 'show']);
    Route::put('/tickets/{id}', [TicketController::class, 'update']);
    Route::delete('/tickets/{id}', [TicketController::class, 'destroy']);
    Route::get('/sessions/{sessionId}/seats', [TicketController::class, 'getAvailableSeats']);

    Route::get('/statistics/overview', [StatisticsController::class, 'overview']);

    Route::get('/users', [AuthController::class, 'getUsers']);

    Route::put('/users/{id}', [AuthController::class, 'updateUser']);

    Route::delete('/users/{id}', [AuthController::class, 'deleteUser']);
});
