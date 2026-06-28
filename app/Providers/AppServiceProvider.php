<?php

namespace App\Providers;

use App\Support\ProviderCallLogger;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->logProviderHttpCalls();
    }

    /**
     * Persist the switch's outgoing gateway calls (request + response) against
     * the provider currently being invoked, for the per-provider call history.
     */
    protected function logProviderHttpCalls(): void
    {
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
