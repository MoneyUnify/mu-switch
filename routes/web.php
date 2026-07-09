<?php

use App\Http\Controllers\ApiLogController;
use App\Http\Controllers\ApiTokenController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FeePolicyController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ProviderController;
use App\Support\Coverage;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => Inertia::render('welcome', [
    'countries' => Coverage::countries(),
    'stats' => Coverage::stats(),
]))->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/api-token', [ApiTokenController::class, 'show'])
        ->name('api-token.show');

    Route::post('/api-token/regenerate', [ApiTokenController::class, 'regenerate'])
        ->name('api-token.regenerate');

    Route::get('logs', [ApiLogController::class, 'index'])->name('logs.index');

    Route::get('providers', [ProviderController::class, 'index'])->name('providers.index');
    Route::get('providers/{provider}/logs', [ProviderController::class, 'logs'])->name('providers.logs');
    Route::post('providers', [ProviderController::class, 'store'])->name('providers.store');
    Route::put('providers/{provider}', [ProviderController::class, 'update'])->name('providers.update');
    Route::delete('providers/{provider}', [ProviderController::class, 'destroy'])->name('providers.destroy');

    Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');

    Route::get('fee-policy', [FeePolicyController::class, 'show'])->name('fee-policy.show');
    Route::put('fee-policy', [FeePolicyController::class, 'update'])->name('fee-policy.update');
});

require __DIR__.'/settings.php';
require __DIR__.'/prezet.php';
