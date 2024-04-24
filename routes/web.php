<?php

use App\Http\Controllers\BidController;
use App\Http\Controllers\FilterController;
use App\Http\Controllers\ProposalController;
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
    Route::get('/', [BidController::class, 'index'])->name('home');
    Route::get('/stats', [BidController::class, 'stats'])->name('statistics');
    Route::get('/filters', [FilterController::class, 'index'])->name('filters');
    Route::get('/updateFilters', [FilterController::class, 'update'])->name('updateFilters');
    Route::resource('bids', BidController::class);
    Route::post('/updateBidCheck', [BidController::class, 'updateBidCheck'])->name('updateBidCheck');
    Route::Post('/expire_bids', [BidController::class, 'expireBids'])->name('expire_bids');
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
    return redirect('https://www.freelancer.com/api/projects/0.1/projects/active?', 301, [
        'Freelancer-OAuth-V1' => $accessAuthToken,
    ]);
});