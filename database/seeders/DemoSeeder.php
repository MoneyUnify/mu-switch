<?php

namespace Database\Seeders;

use App\Ai\Agents\SendMoneyAgent;
use App\Http\Controllers\Providers\PawapayController;
use App\Http\Controllers\Providers\PaypalController;
use App\Http\Controllers\Providers\SandboxPayoutController;
use App\Models\CrossBorderTransfer;
use App\Models\Merchant;
use App\Models\PaymentProvider;
use App\Models\User;
use App\Support\CrossBorder\CrossBorderTransferService;
use App\Support\Ledger\LedgerRecorder;
use App\Support\Merchants\MerchantRegistry;
use App\Support\Merchants\PaymentLinks;
use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Laravel\Ai\Models\Conversation;

/**
 * A demo account with a week of activity, so the platform isn't empty on
 * first run: PayPal-funded transfers delivered to mobile money, a merchant
 * with a pay link and settled payments, refunds, an assistant conversation,
 * the ledger, fees and treasury balances.
 *
 * Every record is made by the app's real flows (quotes, FX locks, the
 * transfer state machine, the ledger). Only PayPal's replies are simulated,
 * so nothing reaches PayPal and no money moves; payouts go through the
 * sandbox-only Simulated Payouts provider.
 */
class DemoSeeder extends Seeder
{
    public const EMAIL = 'demo@moneyunify.test';

    public const PASSWORD = 'password';

    /** @var array<string, array<string, mixed>> PayPal orders by id, as the simulated PayPal holds them */
    private array $orders = [];

    public function run(CrossBorderTransferService $transfers, LedgerRecorder $ledger, MerchantRegistry $merchants, PaymentLinks $links): void
    {
        if (User::where('email', self::EMAIL)->exists()) {
            $this->command?->warn('The demo account already exists; nothing was added.');

            return;
        }

        // Run the payout and refund jobs inline, simulate PayPal, and email nobody.
        config(['queue.default' => 'sync']);
        Sleep::fake();
        Notification::fake();
        Http::fake(fn (Request $request) => $this->paypal($request));

        $start = now()->subDays(7)->setTime(9, 0);
        Carbon::setTestNow($start);

        try {
            $user = User::create([
                'name' => 'Demo Operator',
                'email' => self::EMAIL,
                'email_verified_at' => now(),
                'password' => Hash::make(self::PASSWORD),
            ]);

            $paypal = PaymentProvider::create([
                'user_id' => $user->id,
                'name' => 'PayPal (demo history)',
                'class' => PaypalController::class,
                // Placeholder keys for the simulated history only; they're removed below.
                'config' => ['client_id' => 'demo', 'client_secret' => 'demo', 'environment' => 'sandbox', 'supported_countries' => ['ZM'], 'fx_rates' => 'moneyunify'],
                'is_active' => true,
            ]);

            $wallets = PaymentProvider::create([
                'user_id' => $user->id,
                'name' => 'Simulated Payouts',
                'class' => SandboxPayoutController::class,
                'config' => ['supported_countries' => ['ZM']],
                'is_active' => true,
            ]);
            $ledger->recordOpeningBalance($wallets, 'ZMW', '50000', 'Demo float');

            $merchant = $merchants->save($user, [
                'name' => 'Kitwe Crafts',
                'email' => 'hello@kitwecrafts.test',
                'country' => 'ZM',
                'wallet_number' => '0977654321',
                'wallet_name' => 'Kitwe Crafts',
                'commission_percent' => 2,
                'is_active' => true,
            ]);
            $basket = $links->create($merchant, ['label' => 'Handwoven basket', 'pay_currencies' => ['USD'], 'settle_currency' => 'ZMW', 'amount' => 450]);

            $this->at($start->copy()->addHours(2), fn () => $this->send($transfers, $user, '2500', '0977123456', 'Mum', 'chipo@example.com', 'Groceries'));
            $this->at($start->copy()->addDay()->addHours(5), fn () => $this->pay($transfers, $merchant, '450', 'james@example.com', $basket->id));
            $this->at($start->copy()->addDays(2)->addHours(3), fn () => $this->conversation($transfers, $user));
            // Simulated Payouts refuses numbers ending in 0000: the sender is refunded through PayPal.
            $this->at($start->copy()->addDays(3)->addHours(4), fn () => $this->send($transfers, $user, '1800', '0977120000', 'Landlord', 'chipo@example.com', 'Rent'));
            $this->at($start->copy()->addDays(4)->addHours(6), fn () => $this->pay($transfers, $merchant, '1200', 'amara@example.com'));
            $this->at($start->copy()->addDays(5)->addHours(2), fn () => $this->send($transfers, $user, '50', '0955112233', 'Grace', 'tomas@example.com', 'School fees', mode: 'source'));
            // Numbers ending in 1111 are accepted, then fail: also refunded.
            $this->at($start->copy()->addDays(6)->addHours(1), fn () => $this->send($transfers, $user, '600', '0966551111', 'Bwalya', null, 'Airtime'));

            // The history stays; the placeholder keys go, so nothing ever calls PayPal with them.
            $paypal->update(['is_active' => false, 'config' => ['environment' => 'sandbox', 'supported_countries' => ['ZM'], 'fx_rates' => 'moneyunify']]);
        } finally {
            Carbon::setTestNow();
        }

        // With a pawaPay sandbox token, new payouts go through pawaPay's real sandbox
        // (its test numbers complete, fail or stay pending); the simulator is paused.
        if (filled(config('services.pawapay.sandbox_token'))) {
            PaymentProvider::create([
                'user_id' => $user->id,
                'name' => 'pawaPay sandbox',
                'class' => PawapayController::class,
                'config' => ['api_token' => config('services.pawapay.sandbox_token'), 'environment' => 'sandbox', 'supported_countries' => ['ZM']],
                'is_active' => true,
            ]);
            $wallets->update(['is_active' => false]);
        }

        if (filled(config('services.paypal.sandbox_client_id')) && filled(config('services.paypal.sandbox_client_secret'))) {
            PaymentProvider::create([
                'user_id' => $user->id,
                'name' => 'PayPal sandbox',
                'class' => PaypalController::class,
                'config' => [
                    'client_id' => config('services.paypal.sandbox_client_id'),
                    'client_secret' => config('services.paypal.sandbox_client_secret'),
                    'environment' => 'sandbox',
                    'supported_countries' => ['ZM'],
                ],
                'is_active' => true,
            ]);
        }
    }

    private function at(CarbonInterface $time, callable $callback): void
    {
        Carbon::setTestNow($time);
        $callback();
    }

    /**
     * A transfer from the Send Money page: paid with PayPal, then paid out.
     */
    private function send(CrossBorderTransferService $transfers, User $user, string $amount, string $number, string $name, ?string $email, string $narration, string $mode = 'target', string $origin = 'dashboard', ?string $conversationId = null): CrossBorderTransfer
    {
        [$transfer] = $transfers->request($user, array_filter([
            'amount' => $amount,
            'mode' => $mode,
            'source_currency' => 'USD',
            'country' => 'ZM',
            'account_number' => $number,
            'recipient_name' => $name,
            'payer_email' => $email,
            'narration' => $narration,
        ]), origin: $origin, conversationId: $conversationId);

        return $this->complete($transfers, $transfer);
    }

    /**
     * A customer paying the merchant, then the merchant settled to its wallet.
     */
    private function pay(CrossBorderTransferService $transfers, Merchant $merchant, string $amount, string $email, ?int $linkId = null): void
    {
        [$transfer] = $transfers->collectForMerchant(
            $merchant,
            ['amount' => $amount, 'payer_email' => $email],
            'pay_link',
            fn (CrossBorderTransfer $transfer): string => route('pay.receipt', $transfer->reference),
            link: $linkId ? $merchant->paymentLinks()->find($linkId) : null,
        );

        $this->complete($transfers, $transfer);
    }

    /**
     * The payer approves on PayPal a few minutes later; the switch captures,
     * pays out, then confirms the payout.
     */
    private function complete(CrossBorderTransferService $transfers, CrossBorderTransfer $transfer): CrossBorderTransfer
    {
        $order = $transfer->fundingTransaction->provider_transaction_id;
        $this->orders[$order]['approved'] = true;

        Carbon::setTestNow(now()->addMinutes(3));
        $transfers->refresh($transfer);
        Carbon::setTestNow(now()->addMinutes(1));
        $transfers->refresh($transfer->refresh());

        return $transfer->refresh();
    }

    /**
     * A Send Money assistant conversation that started a transfer.
     */
    private function conversation(CrossBorderTransferService $transfers, User $user): void
    {
        $id = (string) Str::uuid();
        $row = fn (string $role, string $content, CarbonInterface $at): array => [
            'id' => (string) Str::uuid(), 'conversation_id' => $id, 'participant_type' => Conversation::participantType($user),
            'participant_id' => Conversation::participantKey($user), 'agent' => SendMoneyAgent::class, 'role' => $role, 'content' => $content,
            'attachments' => '[]', 'steps' => '[]', 'usage' => '{}', 'meta' => '{}', 'status' => 'completed', 'created_at' => $at, 'updated_at' => $at,
        ];
        $now = now();

        DB::table('agent_conversations')->insert([
            'id' => $id, 'participant_type' => Conversation::participantType($user), 'participant_id' => Conversation::participantKey($user),
            'title' => 'Send K500 to my brother', 'created_at' => $now, 'updated_at' => $now->copy()->addMinutes(2),
        ]);
        $transfer = $this->send($transfers, $user, '500', '0966554433', 'Mutale', null, 'From the assistant', origin: 'agent', conversationId: $id);
        $pays = 'USD '.$transfer->sourceAmount();
        $rate = rtrim(rtrim((string) $transfer->fx_rate, '0'), '.');

        DB::table('agent_conversation_messages')->insert([
            $row('user', 'Send K500 to my brother on 0966554433', $now),
            $row('assistant', "To send **ZMW 500.00** to 0966554433 in Zambia, the sender pays **{$pays}** with PayPal (1 USD = {$rate} ZMW, locked for 10 minutes).\n\nShall I start it?", $now->copy()->addSeconds(4)),
            $row('user', 'Yes, his name is Mutale', $now->copy()->addMinute()),
            $row('assistant', "Done. Pay **{$pays}** with PayPal to send ZMW 500.00 to Mutale. I'll follow the delivery here.", $now->copy()->addMinute()->addSeconds(5)),
        ]);
    }

    /**
     * PayPal's sandbox, simulated: orders are approved by the "payer", then
     * captured, and captures can be refunded.
     */
    private function paypal(Request $request): mixed
    {
        $url = $request->url();
        $path = (string) parse_url($url, PHP_URL_PATH);

        if (str_ends_with($path, '/v1/oauth2/token')) {
            return Http::response(['access_token' => 'demo-token', 'expires_in' => 32400]);
        }

        if ($request->method() === 'POST' && $path === '/v2/checkout/orders') {
            $id = 'DEMO'.Str::upper(Str::random(13));
            $this->orders[$id] = [
                'unit' => $request['purchase_units'][0],
                'email' => data_get($request->data(), 'payment_source.paypal.email_address'),
                'approved' => false,
                'capture' => null,
                'refunds' => [],
            ];

            return Http::response($this->order($id), 201);
        }

        if (preg_match('#^/v2/checkout/orders/([A-Z0-9]+)(/capture)?$#', $path, $match) && isset($this->orders[$match[1]])) {
            $id = $match[1];

            if (($match[2] ?? '') === '/capture' && $this->orders[$id]['approved'] && $this->orders[$id]['capture'] === null) {
                $this->orders[$id]['capture'] = ['id' => 'DEMOCAP'.Str::upper(Str::random(10)), 'create_time' => now()->toIso8601ZuluString()];

                return Http::response($this->order($id), 201);
            }

            return Http::response($this->order($id));
        }

        if ($request->method() === 'POST' && preg_match('#^/v2/payments/captures/([A-Z0-9]+)/refund$#', $path, $match)) {
            foreach ($this->orders as $id => $order) {
                if (($order['capture']['id'] ?? null) === $match[1]) {
                    $refund = ['id' => 'DEMOREF'.Str::upper(Str::random(10)), 'status' => 'COMPLETED', 'amount' => $request['amount'] ?? $order['unit']['amount'], 'create_time' => now()->toIso8601ZuluString()];
                    $this->orders[$id]['refunds'][] = $refund;

                    return Http::response($refund, 201);
                }
            }
        }

        if (preg_match('#^/v2/payments/refunds/([A-Z0-9]+)$#', $path, $match)) {
            foreach ($this->orders as $order) {
                foreach ($order['refunds'] as $refund) {
                    if ($refund['id'] === $match[1]) {
                        return Http::response($refund);
                    }
                }
            }
        }

        return Http::response(['name' => 'RESOURCE_NOT_FOUND', 'message' => 'Not part of the demo'], 404);
    }

    /**
     * An Orders v2 body for the order's current state.
     *
     * @return array<string, mixed>
     */
    private function order(string $id): array
    {
        $order = $this->orders[$id];
        $unit = $order['unit'];
        $email = $order['email'] ?? 'payer@example.com';
        $value = (float) $unit['amount']['value'];
        $fee = round($value * 0.0349 + 0.49, 2);

        if ($order['capture']) {
            $unit['payments'] = array_filter([
                'captures' => [[
                    'id' => $order['capture']['id'],
                    'status' => $order['refunds'] ? 'REFUNDED' : 'COMPLETED',
                    'amount' => $unit['amount'],
                    'create_time' => $order['capture']['create_time'],
                    'seller_protection' => ['status' => 'ELIGIBLE'],
                    'seller_receivable_breakdown' => [
                        'gross_amount' => $unit['amount'],
                        'paypal_fee' => ['currency_code' => $unit['amount']['currency_code'], 'value' => number_format($fee, 2, '.', '')],
                        'net_amount' => ['currency_code' => $unit['amount']['currency_code'], 'value' => number_format($value - $fee, 2, '.', '')],
                    ],
                ]],
                'refunds' => $order['refunds'],
            ]);
        }

        [$given] = explode('@', $email);

        return [
            'id' => $id,
            'status' => $order['capture'] ? 'COMPLETED' : ($order['approved'] ? 'APPROVED' : 'PAYER_ACTION_REQUIRED'),
            'payment_source' => ['paypal' => [
                'email_address' => $email,
                'account_status' => 'VERIFIED',
                'name' => ['given_name' => Str::title($given), 'surname' => 'Demo'],
                'address' => ['country_code' => 'GB'],
            ]],
            'purchase_units' => [$unit],
            'links' => [
                ['rel' => 'self', 'href' => "https://api-m.sandbox.paypal.com/v2/checkout/orders/{$id}"],
                ['rel' => 'payer-action', 'href' => "https://www.sandbox.paypal.com/checkoutnow?token={$id}"],
            ],
        ];
    }
}
