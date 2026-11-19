<?php

namespace App\Support;

use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

/**
 * Builds a Postman collection (v2.1) from the OpenAPI spec in
 * public/openapi.yaml, so every endpoint can be tried in Postman with one
 * import: a folder per tag, example request bodies, the API token as a
 * collection-wide Bearer token, and the deployment's own address.
 */
class PostmanCollection
{
    public const SCHEMA = 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json';

    /** The file served for download and written by `php artisan docs:postman`. */
    public const FILENAME = 'moneyunify-api.postman_collection.json';

    private const METHODS = ['get', 'post', 'put', 'patch', 'delete'];

    /**
     * @param  array<string, mixed>  $spec  The parsed OpenAPI document.
     */
    public function __construct(private array $spec) {}

    public static function fromOpenApiFile(?string $path = null): self
    {
        return new self(Yaml::parseFile($path ?? public_path('openapi.yaml')));
    }

    /**
     * The collection, with requests pointed at `$baseUrl` (the API root,
     * ending in /api/v1).
     *
     * @return array<string, mixed>
     */
    public function toArray(string $baseUrl): array
    {
        $folders = [];
        $pathVariables = [];

        foreach ($this->spec['paths'] ?? [] as $path => $pathItem) {
            $shared = $pathItem['parameters'] ?? [];

            foreach (self::METHODS as $method) {
                if (! isset($pathItem[$method])) {
                    continue;
                }

                $operation = $pathItem[$method];
                $parameters = array_map(fn (array $parameter): array => $this->resolve($parameter), [...$shared, ...($operation['parameters'] ?? [])]);

                foreach ($parameters as $parameter) {
                    if (($parameter['in'] ?? null) === 'path') {
                        $pathVariables[$parameter['name']] = $parameter['description'] ?? null;
                    }
                }

                $tag = $operation['tags'][0] ?? 'Other';
                $folders[$tag][] = $this->request($method, $path, $operation, $parameters);
            }
        }

        return [
            'info' => [
                '_postman_id' => (string) Str::uuid(),
                'name' => $this->spec['info']['title'] ?? 'MoneyUnify API',
                'description' => trim(($this->spec['info']['description'] ?? '')."\n\nSet the `apiToken` variable to your account's API token (on the dashboard's API Token page) before sending requests."),
                'schema' => self::SCHEMA,
            ],
            'auth' => [
                'type' => 'bearer',
                'bearer' => [['key' => 'token', 'value' => '{{apiToken}}', 'type' => 'string']],
            ],
            'variable' => [
                ['key' => 'baseUrl', 'value' => rtrim($baseUrl, '/'), 'type' => 'string', 'description' => 'Your deployment\'s API root, ending in /api/v1.'],
                ['key' => 'apiToken', 'value' => '', 'type' => 'string', 'description' => 'Your account\'s API token, from the API Token page.'],
                ...array_map(fn (string $name, ?string $description): array => [
                    'key' => $name,
                    'value' => '',
                    'type' => 'string',
                    'description' => $description ?? '',
                ], array_keys($pathVariables), $pathVariables),
            ],
            'item' => array_map(fn (string $tag, array $items): array => [
                'name' => $tag,
                'description' => $this->tagDescription($tag),
                'item' => $items,
            ], array_keys($folders), $folders),
        ];
    }

    /**
     * @param  array<string, mixed>  $operation
     * @param  list<array<string, mixed>>  $parameters
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $operation, array $parameters): array
    {
        $headers = [['key' => 'Accept', 'value' => 'application/json']];
        $query = [];

        foreach ($parameters as $parameter) {
            if (($parameter['in'] ?? null) === 'header' && $parameter['name'] === 'Idempotency-Key') {
                $headers[] = [
                    'key' => 'Idempotency-Key',
                    'value' => '{{$guid}}',
                    'description' => $parameter['description'] ?? '',
                ];
            }

            if (($parameter['in'] ?? null) === 'query') {
                $query[] = [
                    'key' => $parameter['name'],
                    'value' => (string) $this->example($this->resolve($parameter['schema'] ?? [])),
                    'description' => $parameter['description'] ?? '',
                    'disabled' => ! ($parameter['required'] ?? false),
                ];
            }
        }

        // {merchant} in OpenAPI is {{merchant}} in Postman.
        $postmanPath = preg_replace('/\{([^}]+)\}/', '{{$1}}', $path);
        $enabled = collect($query)->reject(fn (array $q): bool => $q['disabled']);
        $url = [
            'raw' => '{{baseUrl}}'.$postmanPath.($enabled->isNotEmpty() ? '?'.$enabled->map(fn (array $q): string => $q['key'].'='.$q['value'])->implode('&') : ''),
            'host' => ['{{baseUrl}}'],
            'path' => array_values(array_filter(explode('/', $postmanPath))),
        ];

        if ($query) {
            $url['query'] = $query;
        }

        $request = [
            'method' => strtoupper($method),
            'header' => $headers,
            'url' => $url,
            'description' => trim(($operation['description'] ?? $operation['summary'] ?? '')),
        ];

        $schema = $operation['requestBody']['content']['application/json']['schema'] ?? null;

        if ($schema !== null) {
            $request['header'][] = ['key' => 'Content-Type', 'value' => 'application/json'];
            $request['body'] = [
                'mode' => 'raw',
                'raw' => json_encode($this->example($this->resolve($schema)), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                'options' => ['raw' => ['language' => 'json']],
            ];
        }

        return [
            'name' => $operation['summary'] ?? strtoupper($method).' '.$path,
            'request' => $request,
        ];
    }

    /**
     * An example value for a schema: its own example, default or first
     * allowed value, or a placeholder for its type. Objects list their
     * required properties and any property with an example.
     *
     * @param  array<string, mixed>  $schema
     */
    private function example(array $schema, int $depth = 0): mixed
    {
        if (isset($schema['allOf'])) {
            $schema = array_merge_recursive(...array_map(fn (array $part): array => $this->resolve($part), $schema['allOf']));
        }

        foreach (['oneOf', 'anyOf'] as $choice) {
            if (isset($schema[$choice][0])) {
                return $this->example($this->resolve($schema[$choice][0]), $depth);
            }
        }

        if (array_key_exists('examples', $schema) && $schema['examples'] !== []) {
            return $schema['examples'][0];
        }

        if (array_key_exists('example', $schema)) {
            return $schema['example'];
        }

        if (array_key_exists('default', $schema)) {
            return $schema['default'];
        }

        if (isset($schema['enum'][0])) {
            return $schema['enum'][0];
        }

        $type = is_array($schema['type'] ?? null) ? $schema['type'][0] : ($schema['type'] ?? (isset($schema['properties']) ? 'object' : 'string'));

        return match ($type) {
            'object' => $depth > 4 ? (object) [] : $this->objectExample($schema, $depth),
            'array' => [$this->example($this->resolve($schema['items'] ?? []), $depth + 1)],
            'integer' => (int) ($schema['minimum'] ?? 1),
            'number' => $schema['minimum'] ?? 100,
            'boolean' => false,
            default => match ($schema['format'] ?? null) {
                'email' => 'payer@example.com',
                'uri' => 'https://your-app.com/webhooks/moneyunify',
                'date-time' => '2026-01-01T00:00:00Z',
                default => '',
            },
        };
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>|object
     */
    private function objectExample(array $schema, int $depth): array|object
    {
        $required = (array) ($schema['required'] ?? []);
        $example = [];

        foreach ($schema['properties'] ?? [] as $name => $property) {
            // Optional fields only when the endpoint itself gives an example,
            // not one inherited from a shared schema (like a USD currency).
            $hasOwnExample = isset($property['examples']) || isset($property['example']);

            if (in_array($name, $required, true) || $hasOwnExample) {
                $example[$name] = $this->example($this->resolve($property), $depth + 1);
            }
        }

        return $example === [] ? (object) [] : $example;
    }

    /**
     * Follow a local `$ref` (#/components/…) to what it points at.
     *
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function resolve(array $node): array
    {
        $seen = 0;

        while (isset($node['$ref']) && $seen++ < 10) {
            $target = $this->spec;

            foreach (explode('/', ltrim(Str::after($node['$ref'], '#'), '/')) as $segment) {
                $target = $target[str_replace(['~1', '~0'], ['/', '~'], $segment)] ?? [];
            }

            $node = array_merge($target, array_diff_key($node, ['$ref' => true]));
        }

        return $node;
    }

    private function tagDescription(string $tag): string
    {
        foreach ($this->spec['tags'] ?? [] as $definition) {
            if (($definition['name'] ?? null) === $tag) {
                return $definition['description'] ?? '';
            }
        }

        return '';
    }
}
