<?php

use App\Http\Controllers\AiCredentialController;
use App\Http\Controllers\ApiLogController;
use App\Http\Controllers\ApiTokenController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FeePolicyController;
use App\Http\Controllers\FloatRuleController;
use App\Http\Controllers\MerchantController;
use App\Http\Controllers\MerchantPayController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PaymentLinkController;
use App\Http\Controllers\PaypalCheckoutController;
use App\Http\Controllers\ProviderController;
use App\Http\Controllers\RatesController;
use App\Http\Controllers\RebalanceSuggestionController;
use App\Http\Controllers\SendMoneyAssistantController;
use App\Http\Controllers\SendMoneyController;
use App\Http\Controllers\TransferController;
use App\Http\Controllers\TreasuryController;
use App\Http\Controllers\TreasuryTransferController;
use App\Http\Middleware\LogApiRequests;
use App\Support\Coverage;
use App\Support\PostmanCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Inertia\Inertia;

Route::get('/', fn () => Inertia::render('welcome', [
    'countries' => Coverage::reach(),
    'githubUrl' => 'https://github.com/moneyUnify/mu-switch',
    'stats' => Coverage::stats(),
    'docsOnly' => config('app.docs_only'),
]))->name('home');

Route::inertia('privacy', 'legal/privacy', ['docsOnly' => config('app.docs_only')])->name('privacy');

// Every API endpoint as a Postman collection, built from the OpenAPI spec and
// pointed at this deployment.
Route::get('postman-collection.json', fn () => response()->json(
    PostmanCollection::fromOpenApiFile()->toArray(url('/api/v1')),
    options: JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
)->header('Content-Disposition', 'attachment; filename="'.PostmanCollection::FILENAME.'"'))->name('postman-collection');
Route::inertia('terms', 'legal/terms', ['docsOnly' => config('app.docs_only')])->name('terms');

if (! config('app.docs_only_routes')) {
    Route::get('/up', fn (Request $request) => $request->expectsJson()
        ? response()->json(['status' => 'up'])
        // The page shows an error when given one; a healthy app passes none.
        : response(View::file(base_path('vendor/laravel/framework/src/Illuminate/Foundation/resources/health-up.blade.php'), ['exception' => null])));

    Route::middleware(['auth', 'verified'])->group(function () {
        Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

        Route::get('/api-token', [ApiTokenController::class, 'show'])
            ->name('api-token.show');

        Route::post('/api-token/regenerate', [ApiTokenController::class, 'regenerate'])
            ->name('api-token.regenerate');

        Route::get('logs', [ApiLogController::class, 'index'])->name('logs.index');

        Route::get('providers', [ProviderController::class, 'index'])->name('providers.index');
        Route::get('providers/{provider}/logs', [ProviderController::class, 'logs'])->name('providers.logs');
        Route::post('providers', [ProviderController::class, 'store'])->name('providers.store');
        Route::put('providers/{provider}', [ProviderController::class, 'update'])->name('providers.update');
        Route::delete('providers/{provider}', [ProviderController::class, 'destroy'])->name('providers.destroy');

        Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');

        Route::get('merchants', [MerchantController::class, 'index'])->name('merchants.index');
        Route::post('merchants', [MerchantController::class, 'store'])->name('merchants.store');
        Route::put('merchants/{merchant:id}', [MerchantController::class, 'update'])->name('merchants.update');
        Route::post('merchants/{merchant:id}/mcp-key', [MerchantController::class, 'issueMcpKey'])->middleware('throttle:10,1')->name('merchants.mcp-key.store');
        Route::delete('merchants/{merchant:id}/mcp-key', [MerchantController::class, 'revokeMcpKey'])->name('merchants.mcp-key.destroy');
        Route::prefix('merchants/{merchant}/links')->name('merchants.links.')->controller(PaymentLinkController::class)->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('/', 'store')->name('store');
            Route::patch('{link}', 'update')->name('update');
            Route::delete('{link}', 'destroy')->name('destroy');
        });
        Route::post('payments/{transaction:transaction_id}/refund', [PaymentController::class, 'refund'])->middleware('throttle:20,1')->name('payments.refund');
        Route::get('transfers', [TransferController::class, 'index'])->name('transfers.index');
        Route::post('transfers/transactions/{transaction:transaction_id}/verify', [TransferController::class, 'verifyTransaction'])->middleware(LogApiRequests::class)->name('transfers.transactions.verify');

        Route::get('fee-policy', [FeePolicyController::class, 'show'])->name('fee-policy.show');
        Route::put('fee-policy', [FeePolicyController::class, 'update'])->name('fee-policy.update');

        Route::get('send', [SendMoneyController::class, 'index'])->name('send.index');
        Route::post('send/quote', [SendMoneyController::class, 'quote'])->middleware(['throttle:60,1', LogApiRequests::class])->name('send.quote');
        Route::post('send', [SendMoneyController::class, 'store'])->middleware(LogApiRequests::class)->name('send.store');
        Route::post('send/{transfer:reference}/verify', [SendMoneyController::class, 'verify'])->middleware(LogApiRequests::class)->name('send.verify');
        Route::post('send/assistant', [SendMoneyAssistantController::class, 'message'])->middleware(['throttle:20,1', LogApiRequests::class])->name('send.assistant.message');
        Route::post('send/assistant/decide', [SendMoneyAssistantController::class, 'decide'])->middleware(['throttle:20,1', LogApiRequests::class])->name('send.assistant.decide');
        Route::get('send/assistant/conversations', [SendMoneyAssistantController::class, 'conversations'])->name('send.assistant.conversations');
        Route::get('send/assistant/conversations/{conversation}', [SendMoneyAssistantController::class, 'show'])->name('send.assistant.conversation');

        Route::get('rates', [RatesController::class, 'index'])->name('rates.index');
        Route::post('rates/quote', [RatesController::class, 'quote'])->middleware(LogApiRequests::class)->name('rates.quote');
        Route::post('rates/paypal-fx/check', [RatesController::class, 'checkPaypalFx'])->middleware('throttle:10,1')->name('rates.paypal-fx.check');

        Route::get('treasury', [TreasuryController::class, 'index'])->name('treasury.index');
        Route::put('treasury/float-rules', [FloatRuleController::class, 'upsert'])->name('treasury.float-rules.upsert');
        Route::delete('treasury/float-rules/{floatRule}', [FloatRuleController::class, 'destroy'])->name('treasury.float-rules.destroy');
        Route::post('treasury/suggestions', [RebalanceSuggestionController::class, 'store'])->middleware('throttle:10,1')->name('treasury.suggestions.store');
        Route::prefix('treasury/transfers')->name('treasury.transfers.')->controller(TreasuryTransferController::class)->middleware(LogApiRequests::class)->group(function () {
            Route::post('/', 'store')->name('store');
            Route::post('{transfer}/approve', 'approve')->name('approve');
            Route::post('{transfer}/send', 'send')->name('send');
            Route::post('{transfer}/settle', 'settle')->name('settle');
            Route::post('{transfer}/fail', 'fail')->name('fail');
            Route::post('{transfer}/cancel', 'cancel')->name('cancel');
        });

        Route::get('ai', [AiCredentialController::class, 'index'])->name('ai.index');
        Route::prefix('ai/credentials')->name('ai.credentials.')->controller(AiCredentialController::class)->group(function () {
            Route::post('/', 'store')->name('store');
            Route::put('order', 'reorder')->name('reorder');
            Route::put('{credential}', 'update')->name('update');
            Route::delete('{credential}', 'destroy')->name('destroy');
            Route::delete('{credential}/key', 'destroyKey')->name('key.destroy');
            Route::post('{credential}/test', 'test')->middleware('throttle:6,1')->name('test');
        });
    });

    // A merchant's public pay page: customers pay with PayPal, the merchant is
    // settled to mobile money. Receipts are found by their unguessable reference.
    Route::get('pay/receipt/{transfer:reference}', [MerchantPayController::class, 'receipt'])->name('pay.receipt');
    Route::post('pay/receipt/{transfer:reference}/capture', [MerchantPayController::class, 'capture'])->middleware(['throttle:20,1', LogApiRequests::class])->name('pay.capture');
    Route::post('pay/l/{link}/order', [MerchantPayController::class, 'orderLink'])->middleware(['throttle:10,1', LogApiRequests::class])->name('pay.link.order');
    Route::get('pay/l/{link}', [MerchantPayController::class, 'showLink'])->name('pay.link');
    Route::post('pay/l/{link}/quote', [MerchantPayController::class, 'quoteLink'])->middleware('throttle:60,1')->name('pay.link.quote');
    Route::post('pay/l/{link}', [MerchantPayController::class, 'storeLink'])->middleware(['throttle:10,1', LogApiRequests::class])->name('pay.link.store');
    Route::get('pay/{merchant}', [MerchantPayController::class, 'show'])->name('pay.show');
    Route::post('pay/{merchant}/quote', [MerchantPayController::class, 'quote'])->middleware('throttle:60,1')->name('pay.quote');
    Route::post('pay/{merchant}/order', [MerchantPayController::class, 'order'])->middleware(['throttle:10,1', LogApiRequests::class])->name('pay.order');
    Route::post('pay/{merchant}', [MerchantPayController::class, 'store'])->middleware(['throttle:10,1', LogApiRequests::class])->name('pay.store');

    // A PayPal payment's verified receipt (permanent signed link).
    Route::get('receipts/paypal/{transaction:transaction_id}', [PaypalCheckoutController::class, 'receipt'])
        ->middleware('throttle:60,1')
        ->name('paypal.receipt');

    // Where PayPal returns the payer after checkout (signed links).
    Route::prefix('checkout/paypal/{transaction:transaction_id}')
        ->controller(PaypalCheckoutController::class)
        ->name('paypal.checkout.')
        ->middleware(LogApiRequests::class)
        ->group(function () {
            Route::get('return', 'approved')->name('return');
            Route::get('cancel', 'cancelled')->name('cancel');
        });

    require __DIR__.'/settings.php';
}

require __DIR__.'/prezet.php';
