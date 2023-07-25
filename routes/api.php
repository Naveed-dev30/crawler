<?php

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\BidController;
use App\Http\Controllers\FilterController;
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

Route::get('filters', [FilterController::class, 'getFilters']);
Route::get('getProposals', [ProposalController::class, 'getProposals']);

Route::get('getBid', [BidController::class, 'getBid']);
Route::post('changeBidStatus', [BidController::class, 'changeStatus']);


