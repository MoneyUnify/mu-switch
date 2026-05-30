<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('mu:fake-providers {email} {--count=3}', function ($email) {
    if (!app()->environment('local', 'development')) {
        $this->error('This command can only be run in development environments.');
        return 1;
    }

    $user = \App\Models\User::where('email', $email)->first();
    if (! $user) {
        $this->error("User with email {$email} not found.");
        return 1;
    }

    $count = (int) $this->option('count');
    $faker = \Faker\Factory::create();

    for ($i = 0; $i < $count; $i++) {
        \App\Models\PaymentProvider::create([
            'user_id' => $user->id,
            'name' => $faker->company(),
            'config' => json_encode(['api_key' => $faker->sha1, 'endpoint' => $faker->url]),
            'class' => 'App\\Services\\FakeProvider',
            'logo_url' => $faker->imageUrl(200, 200, 'business'),
            'is_active' => $faker->boolean(80),
        ]);
    }

    $this->info("Created {$count} payment provider(s) for {$email}.");
})->purpose('Create fake payment providers for a user (development only)');
