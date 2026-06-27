<?php

use App\Http\Controllers\Prezet\ImageController;
use App\Http\Controllers\Prezet\IndexController;
use App\Http\Controllers\Prezet\OgimageController;
use App\Http\Controllers\Prezet\SearchController;
use App\Http\Controllers\Prezet\ShowController;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/*
|--------------------------------------------------------------------------
| Prezet Documentation Routes
|--------------------------------------------------------------------------
|
| The documentation site is served under the /docs prefix and rendered with
| Prezet's Blade theme, independent of the Inertia/React application. Route
| names are kept as "prezet.*" so the theme's internal links resolve.
|
*/

Route::withoutMiddleware([
    PreventRequestForgery::class,
    ShareErrorsFromSession::class,
    StartSession::class,
])
    ->group(function () {
        Route::get('docs/search', SearchController::class)->name('prezet.search');

        Route::get('docs/img/{path}', ImageController::class)
            ->name('prezet.image')
            ->where('path', '.*');

        Route::get('/docs/ogimage/{slug}', OgimageController::class)
            ->name('prezet.ogimage')
            ->where('slug', '.*');

        Route::get('docs', IndexController::class)
            ->name('prezet.index');

        Route::get('docs/{slug}', ShowController::class)
            ->name('prezet.show')
            ->where('slug', '.*');
    });
