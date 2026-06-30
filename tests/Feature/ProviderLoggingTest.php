<?php

use App\Http\Controllers\Providers\AirtelController;
use App\Http\Controllers\Providers\MtnController;
use App\Models\PaymentProvider;
use App\Models\ProviderLog;
use App\Models\User;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as Psr7Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;

beforeEach(fn () => Cache::flush());

function loggedAirtelProvider(User $user): PaymentProvider
{
    return PaymentProvider::create([
        'user_id' => $user->id,
        'name' => 'Airtel Money',
        'class' => AirtelController::class,
        'config' => ['client_id' => 'cid', 'client_secret' => 'csecret', 'supported_countries' => ['ZM']],
        'is_active' => true,
    ]);
}

test('outgoing provider calls are logged against the provider', function () {
    $user = User::factory()->create(['api_token' => 'log-airtel']);
    $provider = loggedAirtelProvider($user);

    Http::fake([
        '*/auth/oauth2/token' => Http::response(['access_token' => 'tok-123', 'expires_in' => 3600], 200),
        '*/merchant/v1/payments/' => Http::response([
            'data' => ['transaction' => ['id' => 'AIRTEL-1', 'status' => 'TIP']],
            'status' => ['success' => true],
        ], 200),
    ]);

    $this->withToken('log-airtel')
        ->postJson('/api/v1/payment/request', ['amount' => 30, 'account_number' => '0977123456', 'country' => 'ZM'])
        ->assertOk();

    // Both the auth (token) call and the collection call are logged for this provider.
    $logs = ProviderLog::where('payment_provider_id', $provider->id)->get();
    expect($logs)->toHaveCount(2);
    expect($logs->pluck('url')->implode(' '))->toContain('/auth/oauth2/token')->toContain('/merchant/v1/payments');

    $collection = $logs->first(fn ($log) => str_contains($log->url, '/merchant/v1/payments'));
    expect($collection->response_status)->toBe(200);
    expect($collection->method)->toBe('POST');
    expect($collection->user_id)->toBe($user->id);
});

test('a provider call is logged at initiation, before its response is recorded', function () {
    $user = User::factory()->create(['api_token' => 'log-initiated']);
    loggedAirtelProvider($user);

    $initiatedRowSeen = false;

    Http::fake([
        // This closure runs after the request has been sent (RequestSending has
        // fired) but before its ResponseReceived — so an initiated row must exist.
        '*/auth/oauth2/token' => function () use (&$initiatedRowSeen) {
            $initiatedRowSeen = ProviderLog::whereNull('response_status')->where('failed', false)->exists();

            return Http::response(['access_token' => 'tok-123', 'expires_in' => 3600], 200);
        },
        '*/merchant/v1/payments/' => Http::response([
            'data' => ['transaction' => ['id' => 'AIRTEL-1', 'status' => 'TIP']],
            'status' => ['success' => true],
        ], 200),
    ]);

    $this->withToken('log-initiated')
        ->postJson('/api/v1/payment/request', ['amount' => 30, 'account_number' => '0977123456', 'country' => 'ZM'])
        ->assertOk();

    // The call was written before its response came back...
    expect($initiatedRowSeen)->toBeTrue();
    // ...and was then updated in place (no duplicate, no dangling initiated rows).
    expect(ProviderLog::count())->toBe(2);
    expect(ProviderLog::whereNull('response_status')->where('failed', false)->count())->toBe(0);
});

test('sensitive credentials are redacted in provider logs', function () {
    $user = User::factory()->create(['api_token' => 'log-redact']);
    loggedAirtelProvider($user);

    Http::fake([
        '*/auth/oauth2/token' => Http::response(['access_token' => 'tok-123', 'expires_in' => 3600], 200),
        '*/merchant/v1/payments/' => Http::response(['data' => ['transaction' => ['id' => 'X', 'status' => 'TIP']], 'status' => ['success' => true]], 200),
    ]);

    $this->withToken('log-redact')
        ->postJson('/api/v1/payment/request', ['amount' => 30, 'account_number' => '0977123456', 'country' => 'ZM'])
        ->assertOk();

    // The OAuth token request body carries the client_secret — it must be redacted.
    $authLog = ProviderLog::where('url', 'like', '%/auth/oauth2/token')->first();
    expect($authLog->request_body)->not->toContain('csecret');
    expect($authLog->request_body)->toContain('[REDACTED]');

    // The Bearer token header on the collection call must be redacted too.
    $collection = ProviderLog::where('url', 'like', '%/merchant/v1/payments%')->first();
    expect(json_encode($collection->request_headers))->not->toContain('tok-123');
});

test('a connection failure to a gateway is logged as failed', function () {
    $user = User::factory()->create(['api_token' => 'log-conn-fail']);
    $provider = PaymentProvider::create([
        'user_id' => $user->id,
        'name' => 'MTN MoMo',
        'class' => MtnController::class,
        'config' => ['subscription_key' => 'sk', 'api_user' => 'au', 'api_key' => 'ak', 'supported_countries' => ['ZM']],
        'is_active' => true,
    ]);

    Http::fake([
        '*/collection/token/' => fn () => throw new ConnectException(
            'unreachable',
            new Psr7Request('POST', 'https://proxy.momoapi.mtn.com/collection/token/'),
        ),
    ]);

    $this->withToken('log-conn-fail')
        ->postJson('/api/v1/payment/request', ['amount' => 30, 'account_number' => '0977123456', 'country' => 'ZM'])
        ->assertStatus(502);

    $log = ProviderLog::where('payment_provider_id', $provider->id)->first();
    expect($log)->not->toBeNull();
    expect($log->failed)->toBeTrue();
    expect($log->error_message)->toContain('Connection failed');
});

test('outgoing calls outside a provider context are not logged', function () {
    // No ProviderCallContext is active here, so this must not create a log row.
    Http::fake(['*' => Http::response(['ok' => true], 200)]);

    Http::get('https://example.com/whatever');

    expect(ProviderLog::count())->toBe(0);
});

test('the provider logs page lists that provider call history scoped to the owner', function () {
    $user = User::factory()->create();
    $provider = loggedAirtelProvider($user);

    ProviderLog::create([
        'payment_provider_id' => $provider->id,
        'user_id' => $user->id,
        'method' => 'POST',
        'url' => 'https://openapi.airtel.africa/merchant/v1/payments/',
        'host' => 'openapi.airtel.africa',
        'response_status' => 200,
        'duration_ms' => 42,
        'failed' => false,
    ]);

    $this->actingAs($user)
        ->get(route('providers.logs', $provider))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('providers/logs')
            ->where('provider.id', $provider->id)
            ->where('stats.total', 1)
            ->where('stats.successful', 1)
            ->where('stats.failed', 0)
            ->has('logs.data', 1)
            ->where('logs.data.0.status', 200)
        );
});

test('the api logs page traces the MNO calls a request triggered', function () {
    $user = User::factory()->create(['api_token' => 'log-trace']);
    loggedAirtelProvider($user);

    Http::fake([
        '*/auth/oauth2/token' => Http::response(['access_token' => 'tok-123', 'expires_in' => 3600], 200),
        '*/merchant/v1/payments/' => Http::response([
            'data' => ['transaction' => ['id' => 'AIRTEL-1', 'status' => 'TIP']],
            'status' => ['success' => true],
        ], 200),
    ]);

    // Make a payment so the inbound API request spawns two MNO calls.
    $this->withToken('log-trace')
        ->postJson('/api/v1/payment/request', ['amount' => 30, 'account_number' => '0977123456', 'country' => 'ZM'])
        ->assertOk();

    // The /logs page exposes those MNO calls under the matching API log.
    $this->actingAs($user)
        ->get(route('logs.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('logs.data.0.path', '/api/v1/payment/request')
            ->has('logs.data.0.mnoCalls', 2)
            ->where('logs.data.0.mnoCalls.0.provider', 'Airtel Money')
            ->where('logs.data.0.mnoCalls.1.status', 200)
        );
});

test('a user cannot view another account provider logs', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $provider = loggedAirtelProvider($owner);

    $this->actingAs($intruder)
        ->get(route('providers.logs', $provider))
        ->assertForbidden();
});
