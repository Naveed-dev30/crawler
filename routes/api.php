<?php

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\BidController;
use App\Http\Controllers\BidInsightsController;
use App\Http\Controllers\FilterController;
use App\Http\Controllers\GamificationController;
use App\Http\Controllers\InsightsController;
use App\Http\Controllers\ProposalController;

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

Route::get('getProposals', [ProposalController::class, 'getProposals']);

Route::get('getBid', [BidController::class, 'getBid']);
Route::post('changeBidStatus', [BidController::class, 'changeStatus']);

Route::post('gamification/ingest', [GamificationController::class, 'ingest'])
    ->middleware('gamification.token');

Route::post('insights/ingest', [InsightsController::class, 'ingest'])
    ->middleware('gamification.token');
Route::get('insights', [InsightsController::class, 'index']);

Route::post('insights/bids/ingest', [BidInsightsController::class, 'ingest'])
    ->middleware('gamification.token');
Route::get('insights/bids', [BidInsightsController::class, 'index']);
Route::get('insights/bids/{bidInsight}/changes', [BidInsightsController::class, 'changes']);

Route::prefix('v1')->group(function () {
    // Mobile chat app
    Route::prefix('mobile')->group(function () {
        Route::post('login', [\App\Http\Controllers\Api\V1\Mobile\AuthController::class, 'login']);

        Route::middleware(['auth:sanctum', 'mobile'])->group(function () {
            Route::get('threads', [\App\Http\Controllers\Api\V1\Mobile\ThreadController::class, 'index']);
            Route::get('threads/{thread}', [\App\Http\Controllers\Api\V1\Mobile\ThreadController::class, 'show']);
            Route::post('threads/{thread}/block', [\App\Http\Controllers\Api\V1\Mobile\ThreadController::class, 'block']);
            Route::post('threads/{thread}/unblock', [\App\Http\Controllers\Api\V1\Mobile\ThreadController::class, 'unblock']);
            Route::post('threads/{thread}/assign', [\App\Http\Controllers\Api\V1\Mobile\ThreadController::class, 'assign']);
            Route::get('threads/{thread}/messages', [\App\Http\Controllers\Api\V1\Mobile\MessageController::class, 'index']);
            Route::post('threads/{thread}/messages', [\App\Http\Controllers\Api\V1\Mobile\MessageController::class, 'store']);
            Route::get('logs', [\App\Http\Controllers\Api\V1\Mobile\LogController::class, 'index']);
            Route::get('notifications', [\App\Http\Controllers\Api\V1\Mobile\NotificationController::class, 'index']);
            Route::post('notifications/{notification}/read', [\App\Http\Controllers\Api\V1\Mobile\NotificationController::class, 'markRead']);
            Route::get('users', [\App\Http\Controllers\Api\V1\Mobile\UserController::class, 'index']);
        });
    });
});
