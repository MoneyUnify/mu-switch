<?php

use App\Models\ApiLog;
use App\Models\PaymentProvider;
use App\Models\User;
use Illuminate\Validation\ValidationException;

test('an unauthenticated api request is logged with its 401 response', function () {
    $this->postJson('/api/v1/payment/request', [
        'amount' => 10,
        'account_number' => '0971000000',
        'country' => 'ZM',
    ])->assertStatus(401);

    $log = ApiLog::latest('id')->first();

    expect($log)->not->toBeNull()
        ->and($log->method)->toBe('POST')
        ->and($log->url)->toContain('/api/v1/payment/request')
        ->and($log->response_status)->toBe(401)
        ->and($log->user_id)->toBeNull()
        ->and($log->duration_ms)->toBeGreaterThanOrEqual(0);
});

test('an authenticated request records the consumer and never persists the bearer token', function () {
    $user = User::factory()->create();
    PaymentProvider::create([
        'user_id' => $user->id,
        'name' => 'Lenco',
        'class' => 'LencoClass',
        'config' => ['api_key' => 'secret', 'supported_countries' => ['ZM']],
        'is_active' => false, // inactive => switch returns a 400 without external calls
    ]);

    $this->withToken($user->api_token)
        ->postJson('/api/v1/payment/request', [
            'amount' => 25,
            'account_number' => '0971000000',
            'country' => 'ZM',
        ])->assertStatus(400);

    $log = ApiLog::latest('id')->first();

    expect($log->user_id)->toBe($user->id)
        ->and($log->response_status)->toBe(400)
        ->and($log->request_body['account_number'])->toBe('0971000000');

    // The Authorization header must be redacted, not stored verbatim.
    $authHeader = collect($log->request_headers)
        ->mapWithKeys(fn ($v, $k) => [strtolower($k) => $v])
        ->get('authorization');

    expect($authHeader)->toBe(['[REDACTED]'])
        ->and(json_encode($log->request_headers))->not->toContain($user->api_token);
});

test('a request that fails midway captures the exception class, message and trace', function () {
    $user = User::factory()->create();

    // Missing required fields => ValidationException is thrown inside the controller.
    $this->withToken($user->api_token)
        ->postJson('/api/v1/payment/request', [])
        ->assertStatus(422);

    $log = ApiLog::latest('id')->first();

    expect($log->response_status)->toBe(422)
        ->and($log->exception_class)->toBe(ValidationException::class)
        ->and($log->exception_message)->not->toBeNull()
        ->and($log->exception_trace)->toContain('#0 ');
});

test('a successful api response body is persisted', function () {
    $user = User::factory()->create();

    $this->withToken($user->api_token)
        ->postJson('/api/v1/payment/request', [
            'amount' => 25,
            'account_number' => '0971000000',
            'country' => 'ZM',
        ])->assertStatus(400); // no providers configured

    $log = ApiLog::latest('id')->first();

    expect($log->response_body)->toContain('Providers not configured');
});
