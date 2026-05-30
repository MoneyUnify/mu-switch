<?php

use App\Models\User;

test('dashboard api token endpoint returns the persisted user api token', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->getJson(route('api-token.show'));

    $response->assertOk()
        ->assertJson([
            'token' => $user->refresh()->api_token,
        ]);
});

test('api token persists after logout', function () {
    $user = User::factory()->create();
    $apiToken = $user->api_token;

    $this->actingAs($user)->post(route('logout'));

    expect($user->refresh()->api_token)->toBe($apiToken);
});

test('api token can be manually regenerated from the dashboard endpoint', function () {
    $user = User::factory()->create();
    $apiToken = $user->api_token;

    $response = $this->actingAs($user)->postJson(route('api-token.regenerate'));

    $response->assertOk();

    $newApiToken = $user->refresh()->api_token;

    expect($newApiToken)->not->toBe($apiToken);
    $response->assertJson([
        'token' => $newApiToken,
    ]);
});
