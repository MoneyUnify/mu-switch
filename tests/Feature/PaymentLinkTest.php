<?php

use App\Http\Controllers\Providers\SandboxPayoutController;
use App\Models\CrossBorderTransfer;
use App\Models\Merchant;
use App\Models\PaymentLink;
use App\Models\PaymentProvider;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Sleep::fake();
    Cache::flush();

    $this->aggregator = User::factory()->create(['api_token' => 'links-token']);
    paypalProvider($this->aggregator);
    PaymentProvider::create([
        'user_id' => $this->aggregator->id,
        'name' => 'Simulated Payouts',
        'class' => SandboxPayoutController::class,
        'config' => ['supported_countries' => ['ZM']],
        'is_active' => true,
    ]);
    $this->merchant = Merchant::factory()->for($this->aggregator)->create(['name' => 'Kitwe Crafts', 'commission_percent' => 2]);
});

function fakeLinkPaypal(): void
{
    $count = 0;

    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'A21-paypal-token', 'expires_in' => 32400]),
        '*/v2/checkout/orders' => function () use (&$count) {
            return Http::response(paypalOrder('ORDER-'.(++$count)), 201);
        },
    ]);
}

function createLink(array $body = [])
{
    return test()->actingAs(test()->aggregator)->postJson(route('merchants.links.store', test()->merchant->id), [
        'label' => 'Diaspora orders',
        'pay_currencies' => ['USD', 'EUR'],
        'settle_currency' => 'ZMW',
        ...$body,
    ]);
}

test('a merchant can have many saved links, each with its own currencies and price', function () {
    $first = createLink(['amount' => '250', 'reference' => 'INV-42'])
        ->assertCreated()
        ->assertJsonPath('data.pay_currencies', ['EUR', 'USD'])
        ->assertJsonPath('data.settle_currency', 'ZMW')
        ->assertJsonPath('data.amount', '250.00')
        ->assertJsonPath('data.reference', 'INV-42')
        ->json('data');

    expect($first['url'])->toBe(route('pay.link', $first['code']));

    createLink(['label' => 'Open amount'])->assertCreated()->assertJsonPath('data.amount', null);

    $this->getJson(route('merchants.links.index', $this->merchant->id))
        ->assertOk()
        ->assertJsonCount(2, 'data.links')
        ->assertJsonPath('data.links.0.label', 'Open amount');

    $this->get(route('merchants.index'))
        ->assertInertia(fn (Assert $page) => $page->where('merchants.data.0.links_count', 2));
});

test('choosing every currency, or none, means any currency', function () {
    $all = $this->actingAs($this->aggregator)->get(route('merchants.index'))->inertiaProps('payCurrencies');

    createLink(['pay_currencies' => $all])->assertJsonPath('data.pay_currencies', null);
    createLink(['pay_currencies' => []])->assertJsonPath('data.pay_currencies', null);
});

test('links only accept currencies the providers support, and exact amounts', function () {
    createLink(['pay_currencies' => ['ZMW']])->assertUnprocessable()->assertJsonValidationErrors('pay_currencies.0');
    createLink(['settle_currency' => 'USD'])->assertUnprocessable()->assertJsonValidationErrors('settle_currency');
    createLink(['amount' => '12.345'])->assertUnprocessable()->assertJsonValidationErrors('amount');
});

test('a link\'s fixed price and currencies are enforced, whatever the page sends', function () {
    fakeLinkPaypal();
    $link = PaymentLink::factory()->for($this->merchant)->fixed(25000)->create(['pay_currencies' => ['EUR', 'USD']]);

    $this->get(route('pay.link', $link->code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('link.fixed_amount', true)
            ->where('payCurrencies', ['EUR', 'USD'])
            ->where('prefill.amount', '250.00'));

    $this->postJson(route('pay.link.quote', $link->code), ['amount' => 1, 'source_currency' => 'USD'])
        ->assertOk()
        ->assertJsonPath('data.merchant_price', ['amount' => '250.00', 'currency' => 'ZMW']);

    $this->postJson(route('pay.link.quote', $link->code), ['amount' => 250, 'source_currency' => 'GBP'])
        ->assertUnprocessable()->assertJsonValidationErrors('source_currency');

    $this->post(route('pay.link.store', $link->code), ['amount' => 1, 'source_currency' => 'EUR'])->assertRedirect();

    $payment = CrossBorderTransfer::sole();
    expect($payment->payment_link_id)->toBe($link->id)
        ->and($payment->source_currency)->toBe('EUR')
        ->and($payment->target_amount_minor + $payment->commission_minor)->toBe(25000);
});

test('a disabled link takes no payments until it is enabled again', function () {
    fakeLinkPaypal();
    $code = createLink()->json('data.code');

    $this->patchJson(route('merchants.links.update', [$this->merchant->id, $code]), ['is_active' => false])
        ->assertOk()
        ->assertJsonPath('message', 'Payment link disabled')
        ->assertJsonPath('data.is_active', false);

    $this->get(route('pay.link', $code))->assertInertia(fn (Assert $page) => $page->where('merchant.is_active', false));
    $this->postJson(route('pay.link.quote', $code), ['amount' => 100])->assertStatus(422)->assertJsonPath('message', 'This payment link is no longer active.');
    $this->post(route('pay.link.store', $code), ['amount' => 100])->assertSessionHasErrors('amount');
    expect(CrossBorderTransfer::count())->toBe(0);

    $this->patchJson(route('merchants.links.update', [$this->merchant->id, $code]), ['is_active' => true])
        ->assertJsonPath('data.is_active', true);
    $this->post(route('pay.link.store', $code), ['amount' => 100])->assertRedirect();
    expect(CrossBorderTransfer::count())->toBe(1);
});

test('a link that never took a payment is deleted outright', function () {
    $code = createLink()->json('data.code');

    $this->deleteJson(route('merchants.links.destroy', [$this->merchant->id, $code]))
        ->assertOk()
        ->assertJsonPath('data.outcome', 'deleted');

    expect(PaymentLink::withTrashed()->where('code', $code)->exists())->toBeFalse();
    $this->get(route('pay.link', $code))->assertNotFound();
});

test('a link that took payments is archived, keeping their history', function () {
    fakeLinkPaypal();
    $code = createLink()->json('data.code');
    $this->post(route('pay.link.store', $code), ['amount' => 100]);
    $payment = CrossBorderTransfer::sole();

    $this->actingAs($this->aggregator)
        ->deleteJson(route('merchants.links.destroy', [$this->merchant->id, $code]))
        ->assertOk()
        ->assertJsonPath('data.outcome', 'archived')
        ->assertJsonPath('message', 'Payment link archived. It no longer works, and its payments keep their history.');

    $archived = PaymentLink::withTrashed()->where('code', $code)->sole();
    expect($archived->trashed())->toBeTrue()
        ->and($archived->is_active)->toBeFalse()
        ->and($payment->refresh()->payment_link_id)->toBe($archived->id)
        ->and($payment->paymentLink->code)->toBe($code);

    $this->get(route('pay.link', $code))->assertNotFound();
    $this->getJson(route('merchants.links.index', $this->merchant->id))->assertJsonCount(0, 'data.links');
});

test('links are managed only by the merchant\'s aggregator, also through the api', function () {
    $code = createLink()->json('data.code');
    $other = User::factory()->create(['api_token' => 'other-links-token']);

    $this->actingAs($other)->getJson(route('merchants.links.index', $this->merchant->id))->assertNotFound();
    $this->actingAs($other)->deleteJson(route('merchants.links.destroy', [$this->merchant->id, $code]))->assertNotFound();

    $this->withToken('links-token')->getJson("/api/v1/merchants/{$this->merchant->slug}/links")
        ->assertOk()->assertJsonPath('data.links.0.code', $code);
    $this->withToken('links-token')->postJson("/api/v1/merchants/{$this->merchant->slug}/links", ['pay_currencies' => ['USD'], 'amount' => 99])
        ->assertCreated()->assertJsonPath('data.amount', '99.00');
    $this->withToken('other-links-token')->getJson("/api/v1/merchants/{$this->merchant->slug}/links")->assertNotFound();
});
