<?php

use Symfony\Component\Yaml\Yaml;

test('the server-wide ai fallback defaults to gemini, and any provider can replace it', function () {
    // The fallback when AI_PROVIDER isn't set.
    expect(file_get_contents(config_path('ai.php')))->toContain("env('AI_PROVIDER', 'gemini')")
        ->and(file_get_contents(base_path('.env.example')))->toContain("AI_PROVIDER=gemini\nGEMINI_API_KEY=");

    $env = collect(Yaml::parseFile(base_path('render.yaml'))['services'][0]['envVars'])->keyBy('key');

    expect($env['AI_PROVIDER']['value'])->toBe('gemini')
        ->and($env['GEMINI_API_KEY']['sync'])->toBeFalse()
        ->and($env)->not->toHaveKey('ANTHROPIC_API_KEY');
});
