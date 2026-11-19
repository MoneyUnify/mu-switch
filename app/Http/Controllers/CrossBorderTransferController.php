<?php

namespace App\Http\Controllers;

use App\ApiResponse;
use App\Exceptions\CrossBorderException;
use App\Models\CrossBorderTransfer;
use App\Support\CrossBorder\CrossBorderTransferPresenter;
use App\Support\CrossBorder\CrossBorderTransferService;
use App\Support\Market;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

/**
 * Cross-border transfers: the sender pays through PayPal, and the recipient
 * is paid on mobile money in their own currency at a locked FX rate.
 */
class CrossBorderTransferController extends Controller
{
    public function __construct(private readonly CrossBorderTransferService $transfers) {}

    /**
     * Price a transfer without starting it.
     */
    public function quote(Request $request): JsonResponse
    {
        $input = $request->validate($this->quoteRules());

        try {
            $quote = $this->transfers->quote($request->user(), $input);
        } catch (CrossBorderException $e) {
            return ApiResponse::error($e->getMessage(), $e->status);
        }

        return ApiResponse::success('Transfer quoted', $quote->toArray());
    }

    /**
     * Start a transfer and return the PayPal checkout link for the sender.
     */
    public function request(Request $request): JsonResponse
    {
        $input = $request->validate([
            ...$this->quoteRules(),
            'account_number' => 'required|string|max:50',
            'recipient_name' => 'nullable|string|max:255',
            'recipient_email' => 'nullable|email',
            'network' => 'nullable|string|max:50',
            'narration' => 'nullable|string|max:100',
            'payer_email' => 'nullable|email',
            'pay_with' => 'nullable|in:paypal,card',
            'return_url' => 'nullable|url',
        ]);

        $user = $request->user();
        $key = $request->header('Idempotency-Key');

        if ($key !== null && (strlen($key) < 1 || strlen($key) > 255)) {
            return ApiResponse::error('The Idempotency-Key header must be 1 to 255 characters', 422);
        }

        if ($key && ($existing = $this->findByKey($user->id, $key))) {
            return $this->respond($existing, 'Transfer already requested with this Idempotency-Key', replayed: true);
        }

        $lock = $key ? Cache::lock("cross-border:{$user->id}:".sha1($key), 120) : null;

        if ($lock && ! $lock->get()) {
            return ApiResponse::error('A transfer with this Idempotency-Key is already being processed', 409);
        }

        try {
            if ($key && ($existing = $this->findByKey($user->id, $key))) {
                return $this->respond($existing, 'Transfer already requested with this Idempotency-Key', replayed: true);
            }

            [$transfer, $checkoutUrl] = $this->transfers->request($user, $input, $key);
        } catch (CrossBorderException $e) {
            return ApiResponse::error($e->getMessage(), $e->status);
        } finally {
            $lock?->release();
        }

        return $this->respond($transfer, 'Transfer started. Send the sender to checkout_url to pay with PayPal.', $checkoutUrl);
    }

    /**
     * Re-check a transfer's legs and return where it is.
     */
    public function verify(Request $request): JsonResponse
    {
        $validated = $request->validate(['reference' => 'required|string']);

        $transfer = CrossBorderTransfer::query()
            ->where('user_id', $request->user()->id)
            ->where('reference', $validated['reference'])
            ->first();

        if (! $transfer) {
            return ApiResponse::error('Transfer not found', 404);
        }

        return $this->respond($this->transfers->refresh($transfer), $transfer->status->label());
    }

    /**
     * @return array<string, mixed>
     */
    private function quoteRules(): array
    {
        return [
            'amount' => 'required|numeric|gt:0',
            // target (default): the recipient gets exactly `amount`. source: the sender pays `amount`.
            'mode' => 'nullable|string|in:source,target',
            'source_currency' => 'nullable|string|size:3|alpha',
            'country' => ['required', 'string', 'size:2', Rule::in(Market::codes())],
            'currency' => 'nullable|string|size:3|alpha',
            'fx_quote_id' => 'nullable|string|max:64',
        ];
    }

    private function findByKey(int $userId, string $key): ?CrossBorderTransfer
    {
        return CrossBorderTransfer::query()->where('user_id', $userId)->where('idempotency_key', $key)->first();
    }

    private function respond(CrossBorderTransfer $transfer, string $message, ?string $checkoutUrl = null, bool $replayed = false): JsonResponse
    {
        $data = CrossBorderTransferPresenter::toArray($transfer);
        unset($data['is_final'], $data['created_at']);

        $data = [
            'reference' => $data['reference'],
            'status' => $data['status'],
            'status_label' => $data['status_label'],
            'checkout_url' => $checkoutUrl,
            'replayed' => $replayed ?: null,
            ...$data,
        ];

        return ApiResponse::status($transfer->status->value, $message, array_filter($data, fn ($value): bool => $value !== null));
    }
}
