<?php

namespace App\Mcp\Tools\Merchant;

use App\Http\Controllers\PaymentLinkController;
use App\Mcp\Tools\MerchantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('set_pay_link_active')]
#[Title('Enable or disable a pay link')]
#[Description('Turn a pay link off (is_active false) or back on.')]
#[IsIdempotent]
class SetPayLinkActive extends MerchantTool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->forMerchant([PaymentLinkController::class, 'update'], $request->all(['is_active']), ['link' => (string) $request->get('link')], null);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'link' => $schema->string()->description('The pay link\'s code.')->required(),
            'is_active' => $schema->boolean()->description('true to take payments, false to stop.')->required(),
        ];
    }
}
