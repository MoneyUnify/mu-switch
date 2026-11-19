<?php

use Illuminate\Support\Facades\Route;

test('links stay https behind a load balancer that ends https', function () {
    Route::get('/_proxy-check', fn () => ['url' => url('/pay'), 'secure' => request()->secure()]);

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
        ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'moneyunify.onrender.com', 'X-Forwarded-Port' => '443'])
        ->getJson('/_proxy-check')
        ->assertOk()
        ->assertJson(['url' => 'https://moneyunify.onrender.com/pay', 'secure' => true]);
});
