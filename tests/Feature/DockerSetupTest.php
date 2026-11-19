<?php

use Symfony\Component\Yaml\Yaml;

test('docker compose runs the app with its own postgres and a demo account', function () {
    $compose = Yaml::parseFile(base_path('compose.yaml'));
    $app = $compose['services']['app'];

    expect($app['environment'])->toMatchArray([
        'APP_ENV' => 'local',
        'DB_CONNECTION' => 'pgsql',
        'DB_HOST' => 'db',
        'DB_DATABASE' => $compose['services']['db']['environment']['POSTGRES_DB'],
        'DB_PASSWORD' => $compose['services']['db']['environment']['POSTGRES_PASSWORD'],
        'QUEUE_CONNECTION' => 'database',
    ])
        // The app waits for the database, and keeps its generated key between restarts.
        ->and($app['depends_on']['db']['condition'])->toBe('service_healthy')
        ->and($app['volumes'])->toContain('app-storage:/var/www/html/storage/app')
        // Local keys are optional and never committed or baked into the image.
        ->and($app['env_file'][0])->toBe(['path' => '.env.docker', 'required' => false])
        ->and(file_get_contents(base_path('.gitignore')))->toContain('/.env.docker')
        ->and(file_get_contents(base_path('.dockerignore')))->toContain('.env.*');
});

test('the container makes an app key when none is set, and keeps it', function () {
    expect(file_get_contents(base_path('Dockerfile')))
        ->toContain('if [ -z "$APP_KEY" ]; then')
        ->toContain('php artisan key:generate --show > storage/app/.app-key');
});

test('the docker guide is linked from the readme', function () {
    expect(base_path('prezet/content/docker.md'))->toBeFile()
        ->and(file_get_contents(base_path('README.md')))->toContain('[Run with Docker](prezet/content/docker.md)');
});

test('production docker runs without demo data, behind https, and refuses to start without its secrets', function () {
    $compose = Yaml::parseFile(base_path('compose.prod.yaml'));
    $app = $compose['services']['app'];

    expect($app['environment'])->toMatchArray([
        'APP_ENV' => 'production',
        'APP_DEBUG' => 'false',
        'SEED_DEMO_DATA' => 'false',
        'DB_HOST' => 'db',
    ])
        ->and($app['environment']['APP_KEY'])->toBe('${APP_KEY:?Set APP_KEY in .env.production}')
        ->and($app['environment']['DB_PASSWORD'])->toStartWith('${DB_PASSWORD:?')
        ->and($app['env_file'][0])->toBe(['path' => '.env.production', 'required' => true])
        ->and($app['restart'])->toBe('unless-stopped')
        // Only Caddy is published; it serves the domain over https.
        ->and($app)->not->toHaveKey('ports')
        ->and($compose['services']['db'])->not->toHaveKey('ports')
        ->and($compose['services']['caddy']['ports'])->toBe(['80:80', '443:443'])
        ->and($compose['services']['caddy']['command'])->toContain('reverse-proxy --from "${APP_DOMAIN:?')
        ->and(file_get_contents(base_path('.gitignore')))->toContain('.env.production');
});

test('the production example settings cover every required secret', function () {
    $example = file_get_contents(base_path('.env.production.example'));

    foreach (['APP_DOMAIN=', 'APP_URL=https://', 'APP_KEY=', 'DB_PASSWORD=', 'MAIL_HOST=', 'SESSION_SECURE_COOKIE=true'] as $setting) {
        expect($example)->toContain($setting);
    }

    expect(base_path('prezet/content/docker-production.md'))->toBeFile()
        ->and(file_get_contents(base_path('README.md')))->toContain('[Self-Host with Docker](prezet/content/docker-production.md)');
});
