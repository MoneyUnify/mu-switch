<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Balances change as money moves, but re-check floats regularly as a safety net.
Schedule::command('treasury:check-floats')->everyFiveMinutes()->withoutOverlapping();

// Payout providers rarely call back, so move in-flight transfers forward every minute.
Schedule::command('cross-border:refresh')->everyMinute()->withoutOverlapping();

// Keep PayPal's exchange rates cached, so FX quotes never wait on PayPal.
Schedule::command('paypal-fx:warm')->everyThirtyMinutes()->withoutOverlapping();
