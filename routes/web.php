<?php

use App\Http\Controllers\BidController;
use Illuminate\Support\Facades\Route;
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

Route::get('/', [BidController::class,'index']);
Route::get('/filters', [FilterController::class, 'index']);
Route::get('/updateFilters', [FilterController::class, 'update'])->name('updateFilters');


Route::resource('bids', BidController::class);
