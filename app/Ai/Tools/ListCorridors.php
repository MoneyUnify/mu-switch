<?php

namespace App\Ai\Tools;

use App\Contracts\PayoutProviderInterface;
use App\Contracts\RefundsPayments;
use App\Models\PaymentProvider;
use App\Models\User;
use App\Support\Fx\FxQuoter;
use App\Support\Market;
use App\Support\ProviderRouter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Where this account can send money, and which currencies senders can pay in.
 */
class ListCorridors implements Tool
{
    public function __construct(private readonly User $user) {}

    public function description(): Stringable|string
    {
        return 'List the countries this account can pay out to (with their currency) and the currencies a sender can pay in with PayPal. Call it before quoting if unsure.';
    }

    public function handle(Request $request): Stringable|string
    {
        $providers = $this->user->paymentProviders()->where('is_active', true)->get();
        $payout = $providers->filter(fn (PaymentProvider $p): bool => class_exists($p->class) && ProviderRouter::supports($p, PayoutProviderInterface::class));
        $funding = $providers->filter(fn (PaymentProvider $p): bool => class_exists($p->class) && ProviderRouter::supports($p, RefundsPayments::class));
        $convertible = app(FxQuoter::class)->getRateProvider()->supportedCurrencies();

        $countries = $payout
            ->flatMap(fn (PaymentProvider $p): array => (array) ($p->config['supported_countries'] ?? (defined($p->class.'::SUPPORTED_COUNTRIES') ? $p->class::SUPPORTED_COUNTRIES : [])))
            ->map(fn ($code): string => strtoupper((string) $code))
            ->filter(fn (string $code): bool => Market::isKnown($code))
            ->unique()
            ->map(fn (string $code): array => ['country' => $code, 'name' => Market::name($code), 'currency' => Market::currency($code)])
            ->values();

        $currencies = $funding
            ->flatMap(fn (PaymentProvider $p): array => defined($p->class.'::SUPPORTED_CURRENCIES') ? $p->class::SUPPORTED_CURRENCIES : [])
            ->intersect($convertible)
            ->unique()
            ->values();

        return (string) json_encode(['pay_out_to' => $countries, 'sender_can_pay_in' => $currencies]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
