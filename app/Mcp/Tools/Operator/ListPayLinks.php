<?php

namespace App\Mcp\Tools\Operator;

use App\Http\Controllers\PaymentLinkController;
use App\Mcp\Tools\OperatorTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_pay_links')]
#[Title('List pay links')]
#[Description('A merchant\'s saved pay links.')]
#[IsReadOnly]
class ListPayLinks extends OperatorTool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->forward([PaymentLinkController::class, 'index'], [], $this->account($request), ['merchant' => (string) $request->get('merchant')], null);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'merchant' => $schema->string()->description('The merchant\'s id or slug.')->required(),
        ];
    }
}
