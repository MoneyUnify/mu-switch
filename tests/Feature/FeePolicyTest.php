<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests cannot view the fee policy page', function () {
    $this->get(route('fee-policy.show'))->assertRedirect(route('login'));
});

test('the fee policy page shows the transparent default', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('fee-policy.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('fee-policy')->where('policy', 'transparent'));
});

test('the fee policy can be updated to cost aware', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->put(route('fee-policy.update'), ['policy' => 'cost_aware'])
        ->assertRedirect();

    expect($user->refresh()->feePolicy())->toBe('cost_aware');
});

test('an invalid fee policy is rejected', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->put(route('fee-policy.update'), ['policy' => 'nonsense'])
        ->assertSessionHasErrors('policy');

    expect($user->refresh()->feePolicy())->toBe('transparent');
});
