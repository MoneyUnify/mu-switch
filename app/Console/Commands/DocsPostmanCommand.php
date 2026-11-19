<?php

namespace App\Console\Commands;

use App\Support\PostmanCollection;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

#[Signature('docs:postman {--url= : The API root to point requests at (defaults to APP_URL/api/v1)} {--path= : Where to write the collection (defaults to public/postman)}')]
#[Description('Generate the Postman collection for every API endpoint, from the OpenAPI spec.')]
class DocsPostmanCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $baseUrl = $this->option('url') ?: rtrim((string) config('app.url'), '/').'/api/v1';
        $path = $this->option('path') ?: public_path('postman/'.PostmanCollection::FILENAME);

        $collection = PostmanCollection::fromOpenApiFile()->toArray($baseUrl);

        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($collection, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        $requests = collect($collection['item'])->sum(fn (array $folder): int => count($folder['item']));

        $this->components->info("Postman collection with {$requests} requests written to ".str_replace(base_path().'/', '', $path).'.');

        return self::SUCCESS;
    }
}
