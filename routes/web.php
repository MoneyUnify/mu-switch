<?php

use App\Http\Controllers\ApiTokenController;
use App\Http\Controllers\ProviderController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', function (Request $request) {
        return Inertia::render('dashboard', [
            'apiToken' => $request->user()->ensureApiToken(),
        ]);
    })->name('dashboard');

    Route::get('/api-token', [ApiTokenController::class, 'show'])
        ->name('api-token.show');

    Route::post('/api-token/regenerate', [ApiTokenController::class, 'regenerate'])
        ->name('api-token.regenerate');

    Route::get('providers', [ProviderController::class, 'index'])->name('providers.index');
    Route::post('providers', [ProviderController::class, 'store'])->name('providers.store');
    Route::put('providers/{provider}', [ProviderController::class, 'update'])->name('providers.update');
    Route::delete('providers/{provider}', [ProviderController::class, 'destroy'])->name('providers.destroy');
});

require __DIR__.'/settings.php';
