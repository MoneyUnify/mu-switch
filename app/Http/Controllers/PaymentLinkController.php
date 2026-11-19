<?php

namespace App\Http\Controllers;

use App\ApiResponse;
use App\Models\Merchant;
use App\Models\PaymentLink;
use App\Support\Merchants\PaymentLinks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A merchant's pay links, managed from the dashboard and the API: list,
 * create, change, disable and delete.
 */
class PaymentLinkController extends Controller
{
    public function __construct(private readonly PaymentLinks $links) {}

    public function index(Request $request, string $merchant): JsonResponse
    {
        $model = $this->merchant($request, $merchant);

        return ApiResponse::success('Payment links', [
            'links' => $model->paymentLinks()->get()->map(fn (PaymentLink $link): array => PaymentLinks::present($link))->all(),
        ]);
    }

    public function store(Request $request, string $merchant): JsonResponse
    {
        $model = $this->merchant($request, $merchant);
        $link = $this->links->create($model, $request->validate($this->links->rules($model)));

        return ApiResponse::success('Payment link created', PaymentLinks::present($link), 201);
    }

    public function update(Request $request, string $merchant, string $link): JsonResponse
    {
        $model = $this->merchant($request, $merchant);
        $found = $this->link($model, $link);
        $updated = $this->links->update($found, $request->validate($this->links->rules($model, updating: true)));

        return ApiResponse::success($updated->is_active ? 'Payment link enabled' : 'Payment link disabled', PaymentLinks::present($updated));
    }

    public function destroy(Request $request, string $merchant, string $link): JsonResponse
    {
        $outcome = $this->links->delete($this->link($this->merchant($request, $merchant), $link));

        return ApiResponse::success($outcome === 'archived'
            ? 'Payment link archived. It no longer works, and its payments keep their history.'
            : 'Payment link deleted.', ['outcome' => $outcome]);
    }

    /**
     * The account's merchant, by id or slug.
     */
    private function merchant(Request $request, string $merchant): Merchant
    {
        return $request->user()->merchants()
            ->where(fn ($query) => $query->where('slug', $merchant)->when(ctype_digit($merchant), fn ($q) => $q->orWhere('id', (int) $merchant)))
            ->firstOr(fn () => abort(404, 'Merchant not found'));
    }

    private function link(Merchant $merchant, string $link): PaymentLink
    {
        return $merchant->paymentLinks()
            ->where(fn ($query) => $query->where('code', $link)->when(ctype_digit($link), fn ($q) => $q->orWhere('id', (int) $link)))
            ->firstOr(fn () => abort(404, 'Payment link not found'));
    }
}
