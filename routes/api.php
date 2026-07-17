<?php

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\BidController;
use App\Http\Controllers\FilterController;
use App\Http\Controllers\GamificationController;
use App\Http\Controllers\ProposalController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BidController as ApiBidController;

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

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
  return $request->user();
});

Route::get('filters', [FilterController::class, 'getFilters']);
Route::get('getProposals', [ProposalController::class, 'getProposals']);

Route::get('getBid', [BidController::class, 'getBid']);
Route::post('changeBidStatus', [BidController::class, 'changeStatus']);

Route::post('gamification/ingest', [GamificationController::class, 'ingest'])
    ->middleware('gamification.token');


Route::prefix('v1')->group(function () {
    Route::post('login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('user', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);

        Route::get('bids', [ApiBidController::class, 'index']);
        Route::get('bids/{bid}', [ApiBidController::class, 'show']);
    });
});
