<?php

use App\Models\User;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
    $this->user = User::factory()->create(['api_token' => 'test-api-token-fx']);
});

test('api fx quote requires valid authentication', function () {
    $this->postJson('/api/v1/fx/quote', [
        'from_currency' => 'USD',
        'to_currency' => 'ZMW',
        'amount' => 100,
    ])->assertUnauthorized();
});

test('api returns a locked fx quote by source amount', function () {
    $response = $this->withToken('test-api-token-fx')
        ->postJson('/api/v1/fx/quote', [
            'from_currency' => 'USD',
            'to_currency' => 'ZMW',
            'amount' => 100,
        ])
        ->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.source_currency', 'USD')
        ->assertJsonPath('data.target_currency', 'ZMW')
        ->assertJsonPath('data.source_amount', '100.00')
        ->assertJsonPath('data.is_fx', true)
        ->assertJsonPath('data.is_expired', false);

    $quoteId = $response->json('data.id');
    expect($quoteId)->not->toBeEmpty();

    // Verify quote can be looked up by ID
    $this->withToken('test-api-token-fx')
        ->getJson("/api/v1/fx/quote/{$quoteId}")
        ->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.id', $quoteId)
        ->assertJsonPath('data.source_amount', '100.00');
});

test('api returns a quote by target amount', function () {
    $this->withToken('test-api-token-fx')
        ->postJson('/api/v1/fx/quote', [
            'from_currency' => 'USD',
            'to_currency' => 'ZMW',
            'amount' => 2500,
            'mode' => 'target',
        ])
        ->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.target_amount', '2500.00')
        ->assertJsonPath('data.target_currency', 'ZMW');
});

test('api rejects unsupported currency pairs with 400', function () {
    $this->withToken('test-api-token-fx')
        ->postJson('/api/v1/fx/quote', [
            'from_currency' => 'USD',
            'to_currency' => 'XYZ',
            'amount' => 100,
        ])
        ->assertStatus(400)
        ->assertJsonPath('status', 'error');
});

test('api lists all exchange rates against base currency', function () {
    $this->withToken('test-api-token-fx')
        ->getJson('/api/v1/fx/rates?base=USD')
        ->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.base_currency', 'USD')
        ->assertJsonStructure([
            'status',
            'message',
            'data' => [
                'base_currency',
                'rates' => [
                    'ZMW' => [
                        'currency',
                        'base_rate',
                        'effective_rate',
                        'decimals',
                    ],
                ],
            ],
        ]);
});

test('api returns 404 for nonexistent quote id', function () {
    $this->withToken('test-api-token-fx')
        ->getJson('/api/v1/fx/quote/non-existent-uuid')
        ->assertNotFound();
});

test('another account cannot read a quote by its id', function () {
    $quoteId = $this->withToken('test-api-token-fx')
        ->postJson('/api/v1/fx/quote', ['from_currency' => 'USD', 'to_currency' => 'ZMW', 'amount' => 100])
        ->assertOk()
        ->assertJsonMissingPath('data.owner_id')
        ->json('data.id');

    User::factory()->create(['api_token' => 'other-api-token-fx']);

    $this->withToken('other-api-token-fx')
        ->getJson("/api/v1/fx/quote/{$quoteId}")
        ->assertNotFound()
        ->assertJsonPath('status', 'error');
});
