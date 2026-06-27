<?php

use App\Http\Controllers\ApiLogController;
use App\Http\Controllers\ApiTokenController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProviderController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/api-token', [ApiTokenController::class, 'show'])
        ->name('api-token.show');

    Route::post('/api-token/regenerate', [ApiTokenController::class, 'regenerate'])
        ->name('api-token.regenerate');

    Route::get('logs', [ApiLogController::class, 'index'])->name('logs.index');

    Route::get('providers', [ProviderController::class, 'index'])->name('providers.index');
    Route::post('providers', [ProviderController::class, 'store'])->name('providers.store');
    Route::put('providers/{provider}', [ProviderController::class, 'update'])->name('providers.update');
    Route::delete('providers/{provider}', [ProviderController::class, 'destroy'])->name('providers.destroy');
});

require __DIR__.'/settings.php';
require __DIR__.'/prezet.php';
