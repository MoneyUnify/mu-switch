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
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('delete_pay_link')]
#[Title('Delete a pay link')]
#[Description('Delete a pay link. One that already took payments is archived instead, so its history stays.')]
#[IsDestructive]
class DeletePayLink extends MerchantTool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->forMerchant([PaymentLinkController::class, 'destroy'], [], ['link' => (string) $request->get('link')], null);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'link' => $schema->string()->description('The pay link\'s code.')->required(),
        ];
    }
}
