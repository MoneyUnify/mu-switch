<?php

namespace App\Mcp\Tools\Merchant;

use App\Http\Controllers\CrossBorderTransferController;
use App\Mcp\Tools\MerchantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('check_payment')]
#[Title('Check a payment')]
#[Description('One of your payments: moves it on if it can (captured, settling, settled or refunded) and returns its status and timeline.')]
#[IsIdempotent]
class CheckPayment extends MerchantTool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $merchant = $this->merchant();
        $reference = (string) $request->get('reference', '');

        // Only the merchant's own payments: another merchant's look the same as none.
        if (! $merchant || ! $merchant->settlements()->where('reference', $reference)->exists()) {
            return Response::error('Payment not found.');
        }

        return $this->forward([CrossBorderTransferController::class, 'verify'], ['reference' => $reference], $merchant->user);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'reference' => $schema->string()->description('The payment reference from create_payment or list_payments.')->required(),
        ];
    }
}
