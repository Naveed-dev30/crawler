<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\BidController;
use App\Http\Controllers\FilterController;

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
  }
})->name('auth');

Route::middleware(['auth'])->group(function () {
  Route::get('/', [BidController::class, 'index']);
  Route::get('/filters', [FilterController::class, 'index']);
  Route::get('/updateFilters', [FilterController::class, 'update'])->name('updateFilters');
  Route::resource('bids', BidController::class);
});
