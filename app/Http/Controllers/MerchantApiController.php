<?php

namespace App\Http\Controllers;

use App\ApiResponse;
use App\Exceptions\CrossBorderException;
use App\Models\CrossBorderTransfer;
use App\Models\Merchant;
use App\Support\CrossBorder\CrossBorderTransferPresenter;
use App\Support\CrossBorder\CrossBorderTransferService;
use App\Support\Currency;
use App\Support\Merchants\MerchantCurrencies;
use App\Support\Merchants\MerchantPresenter;
use App\Support\Merchants\MerchantRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

/**
 * The merchant API for aggregators and platforms: onboard merchants, charge
 * customers through PayPal on a merchant's behalf, and follow each
 * settlement to the merchant's mobile-money wallet.
 */
class MerchantApiController extends Controller
{
    public function __construct(
        private readonly MerchantRegistry $registry,
        private readonly CrossBorderTransferService $transfers,
        private readonly MerchantCurrencies $currencies,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $merchants = $request->user()->merchants()->latest('id')->paginate(min(100, max(1, $request->integer('per_page', 25))));

        return ApiResponse::success('Merchants', [
            'merchants' => collect($merchants->items())->map(fn (Merchant $merchant): array => MerchantPresenter::merchant($merchant, withStats: true))->all(),
            'total' => $merchants->total(),
            'page' => $merchants->currentPage(),
            'last_page' => $merchants->lastPage(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $merchant = $this->registry->save($request->user(), $request->validate(MerchantRegistry::rules()));

        return ApiResponse::success('Merchant created', MerchantPresenter::merchant($merchant), 201);
    }

    public function show(Request $request, string $merchant): JsonResponse
    {
        $model = $this->find($request, $merchant);

        return $model ? ApiResponse::success('Merchant', MerchantPresenter::merchant($model, withStats: true)) : ApiResponse::error('Merchant not found', 404);
    }

    public function update(Request $request, string $merchant): JsonResponse
    {
        $model = $this->find($request, $merchant);

        if (! $model) {
            return ApiResponse::error('Merchant not found', 404);
        }

        $model = $this->registry->save($request->user(), $request->validate(MerchantRegistry::rules(updating: true)), $model);

        return ApiResponse::success('Merchant updated', MerchantPresenter::merchant($model));
    }

    /**
     * Quote a payment to the merchant without starting it.
     */
    public function quote(Request $request, string $merchant): JsonResponse
    {
        $model = $this->find($request, $merchant);

        if (! $model) {
            return ApiResponse::error('Merchant not found', 404);
        }

        $input = $request->validate($this->paymentRules($model));

        try {
            $result = $this->transfers->quoteForMerchant($model, $input);
        } catch (CrossBorderException $e) {
            return ApiResponse::error($e->getMessage(), $e->status);
        }

        return ApiResponse::success('Payment quoted', MerchantPresenter::quote($model, $result['quote'], $result['commission_minor'], $result['net_minor']));
    }

    /**
     * Charge a customer through PayPal for the merchant. The merchant is
     * settled to its wallet as soon as PayPal confirms the payment.
     */
    public function charge(Request $request, string $merchant): JsonResponse
    {
        $model = $this->find($request, $merchant);

        if (! $model) {
            return ApiResponse::error('Merchant not found', 404);
        }

        $input = $request->validate([
            ...$this->paymentRules($model),
            'payer_email' => 'nullable|email',
            'pay_with' => 'nullable|in:paypal,card',
            'reference' => 'nullable|string|max:100',
            'return_url' => 'nullable|url',
        ]);

        $key = $request->header('Idempotency-Key');

        if ($key !== null && (strlen($key) < 1 || strlen($key) > 255)) {
            return ApiResponse::error('The Idempotency-Key header must be 1 to 255 characters', 422);
        }

        if ($key && ($existing = $this->byKey($request, $key))) {
            return $this->charged($existing, null, 'Payment already started with this Idempotency-Key');
        }

        $lock = $key ? Cache::lock("merchant-charge:{$request->user()->id}:".sha1($key), 120) : null;

        if ($lock && ! $lock->get()) {
            return ApiResponse::error('A payment with this Idempotency-Key is already being processed', 409);
        }

        try {
            if ($key && ($existing = $this->byKey($request, $key))) {
                return $this->charged($existing, null, 'Payment already started with this Idempotency-Key');
            }

            [$transfer, $checkoutUrl] = $this->transfers->collectForMerchant($model, [
                ...$input,
                'narration' => $input['reference'] ?? null,
            ], 'merchant_api', isset($input['return_url']) ? null : fn (CrossBorderTransfer $transfer): string => route('pay.receipt', $transfer->reference), $key);
        } catch (CrossBorderException $e) {
            return ApiResponse::error($e->getMessage(), $e->status);
        } finally {
            $lock?->release();
        }

        return $this->charged($transfer, $checkoutUrl, 'Payment started. Send the customer to checkout_url to pay with PayPal.');
    }

    /**
     * A merchant's payments and their settlement status.
     */
    public function settlements(Request $request, string $merchant): JsonResponse
    {
        $model = $this->find($request, $merchant);

        if (! $model) {
            return ApiResponse::error('Merchant not found', 404);
        }

        $page = $model->settlements()->latest('id')->paginate(min(100, max(1, $request->integer('per_page', 25))));

        return ApiResponse::success('Settlements', [
            'settlements' => collect($page->items())->map(fn (CrossBorderTransfer $transfer): array => $this->settlement($transfer))->all(),
            'total' => $page->total(),
            'page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentRules(Merchant $merchant): array
    {
        return [
            'amount' => 'required|numeric|gt:0',
            'mode' => 'nullable|in:source,target',
            'source_currency' => ['nullable', 'string', Rule::in($this->currencies->payCurrencies($merchant->user))],
            'settle_currency' => ['nullable', 'string', Rule::in($this->currencies->settlementCurrencies($merchant->user, $merchant->country))],
        ];
    }

    private function find(Request $request, string $merchant): ?Merchant
    {
        return $request->user()->merchants()
            ->where(fn ($query) => $query->where('slug', $merchant)->when(ctype_digit($merchant), fn ($q) => $q->orWhere('id', (int) $merchant)))
            ->first();
    }

    private function byKey(Request $request, string $key): ?CrossBorderTransfer
    {
        return CrossBorderTransfer::query()->where('user_id', $request->user()->id)->where('idempotency_key', $key)->whereNotNull('merchant_id')->first();
    }

    private function charged(CrossBorderTransfer $transfer, ?string $checkoutUrl, string $message): JsonResponse
    {
        return ApiResponse::status($transfer->status->value, $message, array_filter([
            'checkout_url' => $checkoutUrl,
            'receipt_url' => route('pay.receipt', $transfer->reference),
            ...$this->settlement($transfer),
        ], fn ($value): bool => $value !== null));
    }

    /**
     * @return array<string, mixed>
     */
    private function settlement(CrossBorderTransfer $transfer): array
    {
        $data = CrossBorderTransferPresenter::toArray($transfer);

        return [
            ...$data,
            'merchant_id' => $transfer->merchant_id,
            'commission' => ['amount' => (string) Currency::fromMinorUnits($transfer->commission_minor, $transfer->target_currency), 'currency' => $transfer->target_currency],
            'merchant_receives' => $data['recipient_gets'],
        ];
    }
}
