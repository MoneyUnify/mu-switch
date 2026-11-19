<?php

namespace App\Providers;

use App\Support\Fx\Contracts\FxRateProviderInterface;
use App\Support\Fx\FxQuoter;
use App\Support\Fx\Providers\StaticFxRateProvider;
use App\Support\Paypal\PaypalFxRates;
use App\Support\ProviderCallLogger;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
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

        $this->app->singleton(
            FxRateProviderInterface::class,
            fn () => new StaticFxRateProvider(config('moneyunify_paypal.fx', []))
        );

        // Quotes use PayPal's FX for accounts that have it, MoneyUnify's rates otherwise.
        $this->app->singleton(FxQuoter::class, fn ($app) => new FxQuoter(
            $app->make(FxRateProviderInterface::class),
            $app->make(PaypalFxRates::class),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureHttpTimeouts();
        $this->logProviderHttpCalls();
        $this->configureMcpRateLimit();
    }

    /**
     * Each MCP client (the operator's token, or one merchant's key) gets its
     * own 120 calls a minute. Keyed by the user alone, every merchant of an
     * operator would share the operator's limit.
     */
    protected function configureMcpRateLimit(): void
    {
        RateLimiter::for('mcp', fn (Request $request): Limit => Limit::perMinute(120)
            ->by(($request->route()?->getName() ?? 'mcp').'|'.hash('sha256', (string) $request->bearerToken())));
    }

    /**
     * Connecting to a gateway fails fast, so an unreachable host (DNS failure,
     * refused, dead endpoint) lets the switch fall back safely: a connection
     * that never opened means the customer was never charged.
     *
     * Once connected, we wait generously (3 minutes by default) for an answer:
     * the gateway may already have initiated the collection, so cutting it off
     * early and re-routing could bill the customer twice. The wait is still
     * bounded, so a gateway that never answers can't hold a worker forever;
     * the verify endpoint reconciles whatever is left unresolved.
     */
    protected function configureHttpTimeouts(): void
    {
        $connectTimeout = (int) config('services.gateways.connect_timeout');
        $timeout = (int) config('services.gateways.timeout');

        $options = [
            'connect_timeout' => $connectTimeout,
            'timeout' => $timeout,
        ];

        if (env('HTTP_VERIFY_SSL') !== null) {
            $options['verify'] = (bool) filter_var(env('HTTP_VERIFY_SSL'), FILTER_VALIDATE_BOOLEAN);
        } elseif ($this->app->isLocal()) {
            $options['verify'] = false;
        }

        Http::globalOptions($options);

        // PHP's own execution limit would otherwise expire first and kill a web
        // request mid-call, leaving the payment's outcome unknown. Give every
        // outgoing call room for the whole wait. Console processes (queue
        // workers) have no limit and must not be given one.
        if (! $this->app->runningInConsole()) {
            Http::globalRequestMiddleware(function ($request) use ($connectTimeout, $timeout) {
                if (function_exists('set_time_limit')) {
                    @set_time_limit($connectTimeout + $timeout + 30);
                }

                return $request;
            });
        }
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
