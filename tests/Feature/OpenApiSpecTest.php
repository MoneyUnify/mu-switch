<?php

use App\Enums\CrossBorderStatus;
use App\Http\Controllers\Providers\SandboxPayoutController;
use App\Models\PaymentProvider;
use App\Models\User;
use App\Support\Market;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Sleep;
use Symfony\Component\Yaml\Yaml;

/**
 * The OpenAPI spec, parsed.
 *
 * @return array<string, mixed>
 */
function openApi(): array
{
    static $spec;

    return $spec ??= Yaml::parseFile(public_path('openapi.yaml'));
}

/**
 * A schema's properties, following $ref and allOf.
 *
 * @param  array<string, mixed>  $schema
 * @return array<string, array<string, mixed>>
 */
function openApiProperties(array $schema): array
{
    if (isset($schema['$ref'])) {
        return openApiProperties(data_get(openApi(), str_replace('/', '.', substr($schema['$ref'], 2))));
    }

    $properties = $schema['properties'] ?? [];

    foreach ($schema['allOf'] ?? [] as $part) {
        $properties = [...$properties, ...openApiProperties($part)];
    }

    return $properties;
}

/**
 * The documented properties of an operation's success `data`.
 *
 * @return array<string, array<string, mixed>>
 */
function openApiData(string $method, string $path, string $status = '200'): array
{
    $schema = openApi()['paths'][$path][strtolower($method)]['responses'][$status]['content']['application/json']['schema'];

    return openApiProperties(openApiProperties($schema)['data'] ?? []);
}

test('the spec documents exactly the api\'s routes', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/'))
        ->flatMap(fn ($route) => collect($route->methods())->reject(fn ($method) => $method === 'HEAD')
            ->map(fn ($method) => $method.' /'.substr($route->uri(), strlen('api/v1/'))))
        ->sort()->values()->all();

    $documented = collect(openApi()['paths'])
        ->flatMap(fn (array $operations, string $path) => collect(array_keys($operations))
            ->reject(fn ($key) => $key === 'parameters')
            ->map(fn ($method) => strtoupper($method).' '.$path))
        ->sort()->values()->all();

    expect($documented)->toBe($routes);
});

test('the spec\'s enums match the code', function () {
    $schemas = openApi()['components']['schemas'];

    expect($schemas['Country']['enum'])->toBe(Market::codes())
        ->and($schemas['TransferStatus']['enum'])->toBe(array_column(CrossBorderStatus::cases(), 'value'));
});

test('every reference in the spec resolves', function () {
    $refs = [];
    $spec = openApi();
    array_walk_recursive($spec, function ($value, $key) use (&$refs) {
        if ($key === '$ref') {
            $refs[] = $value;
        }
    });

    expect($refs)->not->toBeEmpty();

    foreach (array_unique($refs) as $ref) {
        expect(data_get(openApi(), str_replace('/', '.', substr($ref, 2))))->not->toBeNull("{$ref} doesn't resolve");
    }
});

test('the spec is valid openapi 3.1 and is served publicly', function () {
    expect(openApi()['openapi'])->toBe('3.1.0')
        ->and(openApi()['info']['license']['identifier'])->toBe('Apache-2.0')
        ->and(openApi()['components']['securitySchemes']['bearerAuth']['scheme'])->toBe('bearer');

    expect(is_file(public_path('openapi.yaml')))->toBeTrue();
});

describe('responses carry only documented fields', function () {
    beforeEach(function () {
        Sleep::fake();
        Cache::flush();
        $this->user = User::factory()->create(['api_token' => 'spec-token']);
        paypalProvider($this->user);
        PaymentProvider::create(['user_id' => $this->user->id, 'name' => 'Simulated Payouts', 'class' => SandboxPayoutController::class, 'config' => ['supported_countries' => ['ZM']], 'is_active' => true]);

        Http::fake([
            '*/v1/oauth2/token' => Http::response(['access_token' => 'A21-paypal-token', 'expires_in' => 32400]),
            '*/v2/checkout/orders' => Http::response(paypalOrder(), 201),
        ]);
    });

    test('transfers', function () {
        $quote = $this->withToken('spec-token')->postJson('/api/v1/transfer/quote', ['amount' => 2500, 'country' => 'ZM'])->assertOk()->json('data');
        expect(array_diff(array_keys($quote), array_keys(openApiData('POST', '/transfer/quote'))))->toBe([]);

        $transfer = $this->withToken('spec-token')->postJson('/api/v1/transfer/request', ['amount' => 2500, 'country' => 'ZM', 'account_number' => '0977123456'])->assertOk()->json('data');
        expect(array_diff(array_keys($transfer), array_keys(openApiData('POST', '/transfer/request'))))->toBe([]);
    });

    test('merchants and pay links', function () {
        $merchant = $this->withToken('spec-token')->postJson('/api/v1/merchants', ['name' => 'Kitwe Crafts', 'country' => 'ZM', 'wallet_number' => '0977123456'])->assertCreated()->json('data');
        expect(array_diff(array_keys($merchant), array_keys(openApiData('POST', '/merchants', '201'))))->toBe([]);

        $shown = $this->withToken('spec-token')->getJson("/api/v1/merchants/{$merchant['id']}")->assertOk()->json('data');
        expect(array_diff(array_keys($shown), array_keys(openApiData('GET', '/merchants/{merchant}'))))->toBe([]);

        $link = $this->withToken('spec-token')->postJson("/api/v1/merchants/{$merchant['id']}/links", ['label' => 'Basket', 'amount' => 450])->assertCreated()->json('data');
        expect(array_diff(array_keys($link), array_keys(openApiData('POST', '/merchants/{merchant}/links', '201'))))->toBe([]);

        $charge = $this->withToken('spec-token')->postJson("/api/v1/merchants/{$merchant['id']}/charge", ['amount' => 450])->assertOk()->json('data');
        expect(array_diff(array_keys($charge), array_keys(openApiData('POST', '/merchants/{merchant}/charge'))))->toBe([]);
    });

    test('fx', function () {
        $quote = $this->withToken('spec-token')->postJson('/api/v1/fx/quote', ['from_currency' => 'USD', 'to_currency' => 'ZMW', 'amount' => 100])->assertOk()->json('data');
        expect(array_diff(array_keys($quote), array_keys(openApiData('POST', '/fx/quote'))))->toBe([]);

        $rates = $this->withToken('spec-token')->getJson('/api/v1/fx/rates')->assertOk()->json('data');
        $rateFields = array_keys(openApiProperties(openApiData('GET', '/fx/rates')['rates']['additionalProperties']));
        expect(array_diff(array_keys($rates), array_keys(openApiData('GET', '/fx/rates'))))->toBe([])
            ->and(array_diff(array_keys($rates['rates']['ZMW']), $rateFields))->toBe([]);
    });
});
