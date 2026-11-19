<?php

use App\Support\PostmanCollection;
use Symfony\Component\Yaml\Yaml;

/**
 * Every request in the collection, keyed "METHOD /path" with OpenAPI-style
 * {placeholders}.
 *
 * @param  array<string, mixed>  $collection
 * @return array<string, array<string, mixed>>
 */
function postmanRequests(array $collection): array
{
    $requests = [];

    foreach ($collection['item'] as $folder) {
        foreach ($folder['item'] as $item) {
            $path = '/'.implode('/', $item['request']['url']['path']);
            $requests[$item['request']['method'].' '.preg_replace('/\{\{([^}]+)\}\}/', '{$1}', $path)] = $item['request'];
        }
    }

    return $requests;
}

/**
 * @return array<string, array<string, mixed>>
 */
function openApiOperations(): array
{
    $operations = [];

    foreach (Yaml::parseFile(public_path('openapi.yaml'))['paths'] as $path => $item) {
        foreach (['get', 'post', 'put', 'patch', 'delete'] as $method) {
            if (isset($item[$method])) {
                $operations[strtoupper($method).' '.$path] = [...$item[$method], 'pathParameters' => $item['parameters'] ?? []];
            }
        }
    }

    return $operations;
}

test('the collection has every api endpoint, once', function () {
    $requests = postmanRequests(PostmanCollection::fromOpenApiFile()->toArray('https://pay.example.com/api/v1'));

    expect(array_keys($requests))->toEqualCanonicalizing(array_keys(openApiOperations()))
        ->and($requests)->toHaveCount(24);
});

test('each deployment serves the collection for download, pointed at itself', function () {
    $response = $this->get('/postman-collection.json')
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="'.PostmanCollection::FILENAME.'"');

    $collection = $response->json();
    $variables = collect($collection['variable'])->pluck('value', 'key');

    expect($collection['info']['schema'])->toBe(PostmanCollection::SCHEMA)
        ->and($variables['baseUrl'])->toBe(url('/api/v1'))
        ->and($variables)->toHaveKeys(['apiToken', 'merchant', 'link', 'id'])
        ->and($collection['auth'])->toBe(['type' => 'bearer', 'bearer' => [['key' => 'token', 'value' => '{{apiToken}}', 'type' => 'string']]])
        ->and(collect($collection['item'])->pluck('name')->all())->toBe(['Payments', 'Refunds', 'Payouts', 'Transfers', 'Merchants', 'Pay links', 'FX']);
});

test('requests that move money send a fresh idempotency key', function () {
    $requests = postmanRequests(PostmanCollection::fromOpenApiFile()->toArray('https://pay.example.com/api/v1'));

    foreach (openApiOperations() as $key => $operation) {
        $takesKey = collect($operation['parameters'] ?? [])->contains(fn (array $parameter): bool => ($parameter['$ref'] ?? '') === '#/components/parameters/IdempotencyKey');
        $headers = collect($requests[$key]['header'])->pluck('value', 'key');

        expect($headers->get('Idempotency-Key'))->toBe($takesKey ? '{{$guid}}' : null, $key);
    }

    expect(collect($requests['POST /transfer/request']['header'])->pluck('value', 'key')->get('Idempotency-Key'))->toBe('{{$guid}}');
});

test('example bodies are valid json with every required field', function () {
    $collection = PostmanCollection::fromOpenApiFile()->toArray('https://pay.example.com/api/v1');
    $requests = postmanRequests($collection);

    expect(json_decode($requests['POST /payment/request']['body']['raw'], true))->toBe(['amount' => 2500, 'account_number' => '0976000000', 'country' => 'ZM'])
        ->and(json_decode($requests['POST /transfer/request']['body']['raw'], true))->toBe(['amount' => 2500, 'country' => 'ZM', 'account_number' => '0977123456'])
        ->and($requests['GET /merchants']['url']['raw'])->toBe('{{baseUrl}}/merchants')
        ->and($requests['GET /merchants/{merchant}/links']['url']['raw'])->toBe('{{baseUrl}}/merchants/{{merchant}}/links');

    foreach ($requests as $key => $request) {
        if (! isset($request['body'])) {
            continue;
        }

        $body = json_decode($request['body']['raw'], true, flags: JSON_THROW_ON_ERROR);
        $schema = openApiOperations()[$key]['requestBody']['content']['application/json']['schema'];

        foreach ($schema['required'] ?? [] as $field) {
            expect(array_key_exists($field, $body))->toBeTrue("{$key} needs {$field}");
        }
    }
});

test('the collection can be written to a file at install time', function () {
    $path = storage_path('framework/testing/postman.json');

    $this->artisan('docs:postman', ['--url' => 'https://pay.example.com/api/v1', '--path' => $path])
        ->expectsOutputToContain('Postman collection with 24 requests')
        ->assertSuccessful();

    $collection = json_decode(file_get_contents($path), true);

    expect(collect($collection['variable'])->firstWhere('key', 'baseUrl')['value'])->toBe('https://pay.example.com/api/v1');

    unlink($path);
});

test('the postman collection is documented and linked', function () {
    expect(base_path('prezet/content/api/postman.md'))->toBeFile()
        ->and(file_get_contents(base_path('prezet/content/api/postman.md')))->toContain('(/postman-collection.json)')
        ->and(file_get_contents(base_path('README.md')))->toContain('/postman-collection.json')
        ->and(file_get_contents(base_path('composer.json')))->toContain('@php artisan docs:postman');
});
