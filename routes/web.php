<?php

use App\Http\Controllers\BidController;
use App\Http\Controllers\FilterController;
use App\Http\Controllers\GamificationController;
use App\Http\Controllers\ProposalController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\StatisticsController;
use App\Models\Bid;
use App\Notifications\BidFailed;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

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

Route::get('login', function () {
    return view('content.authentications.auth-login-basic');
})->name('login');

Route::post('auth', function (Request $request) {
    // return $request;
    if (Auth::attempt(['email' => $request->email, 'password' => $request->password])) {
        return redirect('/');
    } else {
        return redirect('/login');
    }
})->name('auth');

Route::middleware(['auth'])->group(function () {
    Route::post('logout', function (Request $request) {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('login');
    })->name('logout');

    Route::get('/', [StatisticsController::class, 'index'])->name('home');
    Route::get('/stats', [StatisticsController::class, 'index'])->name('statistics');
    Route::get('/stats/bids', [StatisticsController::class, 'bids'])->name('stats.bids');
    Route::get('/stats/value', [StatisticsController::class, 'value'])->name('stats.value');
    Route::get('/stats/last24h', [StatisticsController::class, 'last24h'])->name('stats.last24h');
    Route::get('/stats/countries', [StatisticsController::class, 'countries'])->name('stats.countries');
    Route::get('/stats/status', [StatisticsController::class, 'statusBreakdown'])->name('stats.status');
    Route::get('/stats/winrate', [StatisticsController::class, 'winRate'])->name('stats.winrate');
    // Settings area — admin only
    Route::middleware('admin')->group(function () {
        Route::get('/filters', [FilterController::class, 'index'])->name('filters');
        Route::post('/updateFilters', [FilterController::class, 'update'])->name('updateFilters');
        Route::get('/users', [\App\Http\Controllers\UserManagementController::class, 'index'])->name('users');
        Route::post('/users', [\App\Http\Controllers\UserManagementController::class, 'store'])->name('users.store');
        Route::put('/users/{user}', [\App\Http\Controllers\UserManagementController::class, 'update'])->name('users.update');
        Route::get('/chats', [\App\Http\Controllers\ChatController::class, 'index'])->name('chats');
        Route::get('/chats/{thread}/detail', [\App\Http\Controllers\ChatController::class, 'detail'])->name('chats.detail');
    });
    Route::get('/bids', [BidController::class, 'index'])->name('bids');
    Route::get('/bids/data', [BidController::class, 'data'])->name('bids.data');
    Route::get('/bids/{bid}/detail', [BidController::class, 'detail'])->name('bids.detail');
    Route::get('/proposals/{proposal}/nq-detail', [ProposalController::class, 'nqDetail'])->name('proposals.nq-detail');
    Route::resource('bids', BidController::class)->except(['index']);
    Route::post('/updateBidCheck', [BidController::class, 'updateBidCheck'])->name('updateBidCheck');
    Route::Post('/expire_bids', [BidController::class, 'expireBids'])->name('expire_bids');
    Route::get('/review', [ReviewController::class, 'index'])->name('review');
    Route::post('/review/feedback', [ReviewController::class, 'storeFeedback'])->name('review.feedback');
    Route::get('/review/load', [ReviewController::class, 'load'])->name('review.load');
    Route::get('/leaderboard', [GamificationController::class, 'index'])->name('leaderboard');
    Route::get('/insights', [\App\Http\Controllers\InsightsController::class, 'page'])->name('insights');
    Route::get('/insights/bids', [\App\Http\Controllers\BidInsightsController::class, 'page'])->name('insights.bids');
});


Route::get('/notify', function () {
    $bid = Bid::first();
    $bid->notify(new BidFailed($bid));
});

Route::get('/secret-endpoint-verify', function () {
    $proposalController = new ProposalController();
    $proposalController->getProposals();
});

Route::get('/pro', function () {
    $accessAuthToken = config('variables.flKey');
    return redirect(rtrim(config('variables.flBase'), '/') . '/api/projects/0.1/projects/active?', 301, [
        'Freelancer-OAuth-V1' => $accessAuthToken,
    ]);
});