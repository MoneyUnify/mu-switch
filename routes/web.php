<?php

use App\Http\Controllers\ApiTokenController;
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use Inertia\Inertia;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    
    Route::middleware(['auth', 'verified'])->group(function () {
    
    Route::get('dashboard', function (Request $request) {
        return Inertia::render('dashboard', [
            'apiToken' => $request->user()->ensureApiToken(),
        ]);
    })->name('dashboard');

    // Remove the API prefix wrapper. Just standard web routing.
    Route::post('/api-token/regenerate', [ApiTokenController::class, 'regenerate'])
        ->name('api-token.regenerate');
    });
});

require __DIR__.'/settings.php';