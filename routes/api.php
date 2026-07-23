<?php

use App\ApiResponse;
use App\Http\Controllers\SwitchController;
use App\Http\Middleware\VerifyApiAccess;
use Illuminate\Support\Facades\Route;

if (config('app.docs_only_routes')) {
    return;
}

Route::prefix('v1')->middleware(VerifyApiAccess::class)->controller(SwitchController::class)->group(function () {
    Route::prefix('payment')->group(function () {
        Route::post('/request', 'requestPayment');
        Route::post('/verify', 'verifyPayment');
    });
});

// catch all 404 for api routes
Route::any('/{any}', function () {
    return ApiResponse::error('Not Found', 404);
})->where('any', '.*');
