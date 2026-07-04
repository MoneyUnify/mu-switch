<?php

namespace App\Providers;

use App\Support\ProviderCallLogger;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Prezet\Prezet\Actions\GetHeadings;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Use our heading extractor so the docs "On this page" ids match the
        // in-body anchor ids (otherwise punctuated headings don't scroll).
        $this->app->bind(
            GetHeadings::class,
            \App\Support\Prezet\GetHeadings::class,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureHttpTimeouts();
        $this->logProviderHttpCalls();
    }

    /**
     * Bound only how long we wait to *establish a connection* to a gateway, so
     * an unreachable host (DNS failure, refused, dead endpoint) fails fast and
     * the switch can safely fall back — a connection that never opened means the
     * customer was never charged.
     *
     * We intentionally do NOT cap the response (read) time: once a request has
     * reached a gateway it may already have initiated the collection, so cutting
     * it off and re-routing could bill the customer twice. A slow-but-connected
     * provider is waited on; the verify endpoint reconciles the final outcome.
     */
    protected function configureHttpTimeouts(): void
    {
        Http::globalOptions([
            'connect_timeout' => 8,
            'timeout' => 0,
        ]);
    }

    /**
     * Persist the switch's outgoing gateway calls (request + response) against
     * the provider currently being invoked, for the per-provider call history.
     */
    protected function logProviderHttpCalls(): void
    {
        Event::listen(RequestSending::class, [ProviderCallLogger::class, 'recordRequest']);
        Event::listen(ResponseReceived::class, [ProviderCallLogger::class, 'recordResponse']);
        Event::listen(ConnectionFailed::class, [ProviderCallLogger::class, 'recordConnectionFailure']);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
