<?php

namespace App\Ai\Tools;

use App\Models\CrossBorderTransfer;
use App\Models\User;
use App\Support\CrossBorder\CrossBorderTransferPresenter;
use App\Support\CrossBorder\CrossBorderTransferService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Where one of this account's transfers is, re-checked with the providers.
 */
class GetTransferStatus implements Tool
{
    public function __construct(private readonly User $user) {}

    public function description(): Stringable|string
    {
        return 'Check a transfer by its reference (or the latest one if no reference is given): its status, amounts, providers and timeline.';
    }

    public function handle(Request $request): Stringable|string
    {
        $reference = $request['reference'] ?? null;

        $transfer = CrossBorderTransfer::query()
            ->where('user_id', $this->user->id)
            ->when($reference, fn ($query) => $query->where('reference', $reference))
            ->latest('id')
            ->first();

        if (! $transfer) {
            return (string) json_encode(['error' => 'No such transfer on this account.']);
        }

        $transfer = app(CrossBorderTransferService::class)->refresh($transfer);
        $data = CrossBorderTransferPresenter::toArray($transfer);
        unset($data['fx']['quote_id']);

        return (string) json_encode($data);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'reference' => $schema->string()->description('The transfer reference. Omit for the latest transfer.')->nullable(),
        ];
    }
}
