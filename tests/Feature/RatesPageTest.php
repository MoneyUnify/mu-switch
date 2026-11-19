<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('rates page redirects unauthenticated guests to login', function () {
    $this->get('/rates')->assertRedirect('/login');
});

test('rates page renders successfully for authenticated users with inertia props', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/rates')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('rates/index')
            ->has('baseCurrency')
            ->has('rates')
            ->has('marginPercent')
            ->has('quoteTtlMinutes')
            ->has('supportedCurrencies')
        );
});

test('rates web quote endpoint returns json quote for interactive calculator', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/rates/quote', [
            'from_currency' => 'USD',
            'to_currency' => 'ZMW',
            'amount' => 100,
            'mode' => 'source',
        ])
        ->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.source_currency', 'USD')
        ->assertJsonPath('data.target_currency', 'ZMW')
        ->assertJsonPath('data.source_amount', '100.00');
});

test('rates web quote rejects invalid amounts', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/rates/quote', [
            'from_currency' => 'USD',
            'to_currency' => 'ZMW',
            'amount' => -10,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('amount');
});

test('rates page retains active quote in session across subsequent page visits and reloads', function () {
    $user = User::factory()->create();

    // 1. User generates a quote via the interactive calculator
    $response = $this->actingAs($user)
        ->postJson('/rates/quote', [
            'from_currency' => 'USD',
            'to_currency' => 'KES',
            'amount' => 250,
            'mode' => 'source',
        ])
        ->assertOk();

    $quoteId = $response->json('data.id');

    // 2. User reloads or navigates back to /rates
    $this->actingAs($user)
        ->get('/rates')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('rates/index')
            ->where('activeQuote.id', $quoteId)
            ->where('activeQuote.source_currency', 'USD')
            ->where('activeQuote.target_currency', 'KES')
            ->where('activeQuote.source_amount', '250.00')
        );
});
