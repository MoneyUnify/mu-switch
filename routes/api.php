<?php

use App\Http\Controllers\SwitchController;
use App\Http\Middleware\VerifyApiAccess;
use Illuminate\Support\Facades\Route;
use App\ApiResponse;

Route::prefix('v1')->middleware(VerifyApiAccess::class)->controller(SwitchController::class)->group(function () {
    Route::prefix('payment')->group(function () {
        Route::post('/request', 'requestPayment');
    });
});

// catch all 404 for api routes
Route::any('/{any}', function () {
    return ApiResponse::error('Not Found', 404);
})->where('any', '.*');