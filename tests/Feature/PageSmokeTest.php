<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->create();
});

test('welcome page renders successfully', function () {
    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('welcome'));
});

test('dashboard page renders successfully', function () {
    $this->actingAs($this->user)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('dashboard'));
});

test('providers page renders successfully', function () {
    $this->actingAs($this->user)
        ->get('/providers')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('providers/index'));
});

test('payments page renders successfully', function () {
    $this->actingAs($this->user)
        ->get('/payments')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('payments/index'));
});

test('logs page renders successfully', function () {
    $this->actingAs($this->user)
        ->get('/logs')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('logs/index'));
});

test('treasury page renders successfully', function () {
    $this->actingAs($this->user)
        ->get('/treasury')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('treasury/index'));
});

test('fee policy page renders successfully', function () {
    $this->actingAs($this->user)
        ->get('/fee-policy')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('fee-policy'));
});

test('exchange rates page renders successfully', function () {
    $this->actingAs($this->user)
        ->get('/rates')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('rates/index'));
});

test('ai credentials page renders successfully', function () {
    $this->actingAs($this->user)
        ->get('/ai')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('ai/index'));
});

test('api token endpoint returns user token json', function () {
    $this->actingAs($this->user)
        ->getJson('/api-token')
        ->assertOk()
        ->assertJsonStructure(['token']);
});

test('settings profile page renders successfully', function () {
    $this->actingAs($this->user)
        ->get('/settings/profile')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('settings/profile'));
});

test('settings security page renders successfully with password confirmation', function () {
    $this->actingAs($this->user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get('/settings/security')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('settings/security'));
});

test('settings appearance page renders successfully', function () {
    $this->actingAs($this->user)
        ->get('/settings/appearance')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('settings/appearance'));
});
