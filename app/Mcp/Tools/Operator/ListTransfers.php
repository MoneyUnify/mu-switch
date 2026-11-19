<?php

namespace App\Mcp\Tools\Operator;

use App\Enums\CrossBorderStatus;
use App\Mcp\Tools\OperatorTool;
use App\Models\CrossBorderTransfer;
use App\Support\CrossBorder\CrossBorderTransferPresenter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_transfers')]
#[Title('List transfers')]
#[Description('The operator\'s cross-border transfers and merchant payments, newest first, optionally only those in one status.')]
#[IsReadOnly]
class ListTransfers extends OperatorTool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $account = $this->account($request);

        if (! $account) {
            return Response::error('Not signed in.');
        }

        $input = $request->validate([
            'status' => ['nullable', 'string', 'in:'.implode(',', array_column(CrossBorderStatus::cases(), 'value'))],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $transfers = CrossBorderTransfer::query()
            ->where('user_id', $account->id)
            ->when($input['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->latest('id')
            ->limit($input['limit'] ?? 10)
            ->get();

        return Response::structured([
            'transfers' => $transfers->map(fn (CrossBorderTransfer $transfer): array => CrossBorderTransferPresenter::toArray($transfer))->all(),
            'count' => $transfers->count(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->description('Only transfers in this status.')->enum(array_column(CrossBorderStatus::cases(), 'value')),
            'limit' => $schema->integer()->description('How many, 1 to 50. Default 10.'),
        ];
    }
}
