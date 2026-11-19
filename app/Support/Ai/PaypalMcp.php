<?php

namespace App\Support\Ai;

use App\Http\Controllers\Providers\PaypalController;
use App\Models\PaymentProvider;
use App\Models\User;
use App\Support\Paypal\PaypalClient;
use Illuminate\Support\Collection;
use Laravel\Mcp\Client;
use Laravel\Mcp\Facades\Mcp;

/**
 * PayPal's Agent Toolkit for an account's agents, through PayPal's MCP
 * server. It authenticates with the account's PayPal provider. Turning MCP off
 * on that provider switches it off entirely; only accounts without a PayPal
 * provider fall back to .env.
 *
 * By default it runs PayPal's official MCP server (@paypal/mcp, built on
 *
 * @paypal/agent-toolkit) locally over stdio. PayPal's hosted server only
 * answers over SSE in the sandbox, which the MCP client doesn't speak.
 */
class PaypalMcp
{
    public const SOURCE_DISABLED = 'disabled';

    public static function enabledProviderFor(?User $user): ?PaymentProvider
    {
        return self::enabledProvider(self::activePaypalProviders($user));
    }

    /**
     * The MCP client to use, or null when PayPal MCP is off or not set up.
     */
    public static function clientFor(?User $user): ?Client
    {
        $providers = self::activePaypalProviders($user);

        if ($provider = self::enabledProvider($providers)) {
            $paypal = PaypalClient::fromConfig($provider->config);

            if (self::usesLocalServer()) {
                // A fresh access token for each run, from the provider's cached OAuth flow.
                return Client::local((string) config('services.paypal.mcp_node', 'node'), [self::localServerPath(), '--tools=all'], [
                    'PAYPAL_ACCESS_TOKEN' => $paypal->accessToken(),
                    'PAYPAL_ENVIRONMENT' => $paypal->environment()->agentToolkitName(),
                ])->withTimeout(60);
            }

            // Tokens come from the provider's cached OAuth flow, so they never go stale.
            return Client::web($paypal->environment()->mcpUrl())
                ->withToken(fn (): string => $paypal->accessToken());
        }

        return ! self::disabledProvider($providers) && self::envConfigured() ? Mcp::client('paypal') : null;
    }

    /**
     * Where the connection comes from, for display.
     *
     * @return array{source: ?string, provider: ?string, environment: ?string}
     */
    public static function statusFor(?User $user): array
    {
        $providers = self::activePaypalProviders($user);

        if ($provider = self::enabledProvider($providers)) {
            return [
                'source' => ResolvedAiProvider::SOURCE_DASHBOARD,
                'provider' => $provider->name,
                'environment' => PaypalClient::fromConfig($provider->config)->environment()->value,
            ];
        }

        if ($disabled = self::disabledProvider($providers)) {
            return ['source' => self::SOURCE_DISABLED, 'provider' => $disabled->name, 'environment' => null];
        }

        return ['source' => self::envConfigured() ? ResolvedAiProvider::SOURCE_ENV : null, 'provider' => null, 'environment' => null];
    }

    /**
     * The account's active PayPal providers, oldest first.
     *
     * @return Collection<int, PaymentProvider>
     */
    private static function activePaypalProviders(?User $user): Collection
    {
        return $user?->paymentProviders()
            ->where('class', PaypalController::class)
            ->where('is_active', true)
            ->oldest('id')
            ->get() ?? collect();
    }

    /**
     * The first provider with MCP enabled (the default) and complete credentials.
     *
     * @param  Collection<int, PaymentProvider>  $providers
     */
    private static function enabledProvider(Collection $providers): ?PaymentProvider
    {
        return $providers->first(fn (PaymentProvider $provider): bool => ($provider->config['mcp'] ?? 'enabled') !== 'disabled'
            && PaypalClient::fromConfig($provider->config ?? []) !== null);
    }

    /**
     * A provider where the account explicitly turned PayPal MCP off.
     *
     * @param  Collection<int, PaymentProvider>  $providers
     */
    private static function disabledProvider(Collection $providers): ?PaymentProvider
    {
        return $providers->first(fn (PaymentProvider $provider): bool => ($provider->config['mcp'] ?? null) === 'disabled');
    }

    /**
     * Whether agents run PayPal's official MCP server locally (the default),
     * which needs @paypal/mcp installed (npm install) and Node.js.
     */
    public static function usesLocalServer(): bool
    {
        return config('services.paypal.mcp_transport', 'local') === 'local' && is_file(self::localServerPath());
    }

    public static function localServerPath(): string
    {
        return (string) config('services.paypal.mcp_server', base_path('node_modules/@paypal/mcp/dist/index.js'));
    }

    private static function envConfigured(): bool
    {
        return filled(config('mcp.clients.paypal.url')) && filled(config('mcp.clients.paypal.token'));
    }
}
