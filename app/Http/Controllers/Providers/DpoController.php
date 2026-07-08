<?php

namespace App\Http\Controllers\Providers;

use App\ApiResponse;
use App\Contracts\PaymentProviderInterface;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\PaymentProvider;
use App\Models\Transaction;
use App\Support\Market;
use Illuminate\Http\Client\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * DPO Pay (DPO Group / Network) driver — direct mobile-money push to pay.
 *
 * DPO's v6 XML API does a server-to-server mobile-money charge with no hosted
 * redirect: create a transaction token (`createToken`), then charge it
 * (`ChargeTokenMobile`) which sends the STK/USSD prompt straight to the payer's
 * handset; status is confirmed with `verifyToken`. The driver builds the XML
 * envelopes itself (no php-soap/ext needed) so calls stay testable and flow
 * through the switch's provider call-logging.
 *
 * @see https://docs.dpopay.com/api/index.html
 */
class DpoController extends Controller implements PaymentProviderInterface
{
    public const PROVIDER_NAME = 'DPO Pay';

    /**
     * DPO's mobile-money markets, mapped to the lowercase country name DPO's
     * `MNOcountry` field expects. This is the single place to add a market.
     *
     * @var array<string, string>
     */
    private const COUNTRY_NAMES = [
        'KE' => 'kenya',
        'TZ' => 'tanzania',
        'UG' => 'uganda',
        'RW' => 'rwanda',
        'ZM' => 'zambia',
        'GH' => 'ghana',
        'MW' => 'malawi',
        'NG' => 'nigeria',
        'MZ' => 'mozambique',
    ];

    /**
     * Markets this driver can serve (selectable in the dashboard).
     */
    public const SUPPORTED_COUNTRIES = ['KE', 'TZ', 'UG', 'RW', 'ZM', 'GH', 'MW', 'NG', 'MZ'];

    public const DEFAULT_COUNTRIES = 'ZM';

    /**
     * Default logo shown for this driver (editable in the provider dialog).
     */
    public const DEFAULT_LOGO = '/dpo.png';

    /**
     * The credential fields the dashboard should collect for this driver.
     */
    public const CONFIG_FIELDS = [
        ['key' => 'company_token', 'label' => 'Company Token', 'type' => 'password'],
        ['key' => 'service_type', 'label' => 'Service Type', 'type' => 'text'],
    ];

    /**
     * DPO routes the prompt to a specific mobile-money operator by its MNO code
     * (e.g. AirtelZM, MPESA), assigned per operator — there is no derivable
     * global standard. So one provider can serve many markets: a code is
     * collected for each ticked market, and the switch picks the one for the
     * request's country.
     */
    public const MARKET_FIELD = [
        'key' => 'mno_codes',
        'label' => 'Mobile Operator (MNO) code',
        'placeholder' => 'e.g. AirtelZM',
    ];

    /**
     * DPO Pay production host / v6 XML API.
     */
    private const BASE_URL = 'https://secure.3gdirectpay.com/API/v6/';

    private string $companyToken;

    private string $serviceType;

    /**
     * Map of country code => DPO MNO (operator) code.
     *
     * @var array<string, string>
     */
    private array $mnoCodes = [];

    public ?PaymentProvider $provider = null;

    /**
     * Inject the configured provider's credentials into the driver.
     */
    public function setProvider(PaymentProvider $provider): ?JsonResponse
    {
        $config = is_string($provider->config) ? json_decode($provider->config, true) : $provider->config;

        $companyToken = $config['company_token'] ?? null;
        $serviceType = $config['service_type'] ?? null;

        if (! $companyToken || ! $serviceType) {
            return ApiResponse::error('Company Token and Service Type are required for the DPO provider', 400);
        }

        $this->companyToken = $companyToken;
        $this->serviceType = (string) $serviceType;
        $this->mnoCodes = is_array($config['mno_codes'] ?? null) ? $config['mno_codes'] : [];
        $this->provider = $provider;

        return null;
    }

    /**
     * Create a token then charge it — sending the mobile-money push to the payer.
     */
    public function requestPayment(Request $request): JsonResponse
    {
        $country = strtoupper($request['country']);
        $mnoCountry = self::COUNTRY_NAMES[$country] ?? null;
        if (! $mnoCountry) {
            return ApiResponse::error("DPO does not support mobile money in {$country}", 422);
        }

        $currency = Market::currency($country);
        $msisdn = $this->normaliseMsisdn($request['account_number'], $country);

        // The operator the push is routed to, chosen by the request country.
        $mno = trim((string) $request->input('mno')) ?: trim((string) ($this->mnoCodes[$country] ?? ''));
        if ($mno === '') {
            return ApiResponse::error("No DPO mobile operator (MNO) is configured for {$country}", 422);
        }

        $customer = $this->resolveCustomer($msisdn, $country);
        $reference = $this->newReference();

        $transaction = new Transaction([
            'transaction_id' => $reference,
            'customer_id' => $customer->id,
            'amount' => $request['amount'],
            'currency' => $currency,
            'country' => $country,
            'status' => TransactionStatus::PENDING,
            'direction' => 'credit',
            'provider_transaction_id' => $reference,
            'callback_url' => $request->input('callback_url'),
        ]);
        $transaction->paymentProvider()->associate($this->provider);
        $transaction->save();

        // 1. Create a transaction token.
        $tokenResponse = $this->postXml($this->createTokenXml($reference, (float) $request['amount'], $currency, $country));
        $tokenResult = $this->tag($tokenResponse, 'Result');
        $transToken = $this->tag($tokenResponse, 'TransToken');

        if ($tokenResult !== '000' || ! $transToken) {
            return $this->failTransaction($transaction, $tokenResponse, 'DPO could not create the transaction');
        }

        $transaction->update(['provider_transaction_id' => $transToken]);

        // 2. Charge the token — this pushes the STK/USSD prompt to the payer.
        $chargeResponse = $this->postXml($this->chargeMobileXml($transToken, $msisdn, $mno, $mnoCountry));
        $chargeResult = $this->tag($chargeResponse, 'Result');

        // "000"/"130" — the charge request was accepted and the prompt is on its way.
        if (! in_array($chargeResult, ['000', '130'], true)) {
            return $this->failTransaction($transaction, $chargeResponse, 'DPO mobile money charge failed');
        }

        $transaction->update([
            'status' => TransactionStatus::PENDING,
            'provider_response' => ['create' => $tokenResponse->body(), 'charge' => $chargeResponse->body()],
        ]);

        return ApiResponse::success('Payment request initiated successfully', [
            'transaction_id' => $transaction->transaction_id,
            'reference' => $transaction->provider_transaction_id,
            'status' => $transaction->status->value,
        ]);
    }

    /**
     * Re-check a transaction with DPO and persist its latest status.
     */
    public function verifyPayment(Transaction $transaction): JsonResponse
    {
        $response = $this->postXml($this->verifyTokenXml($transaction->provider_transaction_id));
        $result = $this->tag($response, 'Result');

        if ($result === null) {
            return ApiResponse::error($this->tag($response, 'ResultExplanation') ?? 'Unable to verify the transaction with DPO', 502);
        }

        $status = self::mapStatus($result);

        $transaction->update([
            'status' => $status,
            'provider_response' => ['verify' => $response->body()],
        ]);

        return ApiResponse::status($status->value, self::statusMessage($status), [
            'transaction_id' => $transaction->transaction_id,
            'reference' => $transaction->provider_transaction_id,
            'status' => $status->value,
            'provider_status' => $result,
            'amount' => (float) $transaction->amount,
            'currency' => $transaction->currency,
        ]);
    }

    /**
     * Mark the transaction failed and return an error carrying DPO's explanation.
     */
    private function failTransaction(Transaction $transaction, Response $response, string $fallback): JsonResponse
    {
        $transaction->update([
            'status' => TransactionStatus::FAILED,
            'provider_response' => ['error' => $response->body()],
        ]);

        return ApiResponse::error($this->tag($response, 'ResultExplanation') ?: $fallback, 422);
    }

    /**
     * POST an XML envelope to the DPO v6 API.
     */
    private function postXml(string $xml): Response
    {
        return Http::withHeaders(['Content-Type' => 'application/xml'])
            ->withBody($xml, 'application/xml')
            ->post(self::BASE_URL);
    }

    private function createTokenXml(string $reference, float $amount, string $currency, string $country): string
    {
        return $this->envelope('createToken',
            '<Transaction>'
            .'<PaymentAmount>'.number_format($amount, 2, '.', '').'</PaymentAmount>'
            .'<PaymentCurrency>'.$this->x($currency).'</PaymentCurrency>'
            .'<CompanyRef>'.$this->x($reference).'</CompanyRef>'
            .'<RedirectURL>'.$this->x($this->appUrl()).'</RedirectURL>'
            .'<BackURL>'.$this->x($this->appUrl()).'</BackURL>'
            .'<CompanyRefUnique>1</CompanyRefUnique>'
            .'<PTL>24</PTL>'
            .'<DefaultPaymentCountry>'.$this->x(Market::alpha3($country) ?? '').'</DefaultPaymentCountry>'
            .'</Transaction>'
            .'<Services><Service>'
            .'<ServiceType>'.$this->x($this->serviceType).'</ServiceType>'
            .'<ServiceDescription>Payment collection</ServiceDescription>'
            .'<ServiceDate>'.now()->format('Y/m/d H:i').'</ServiceDate>'
            .'</Service></Services>',
        );
    }

    private function chargeMobileXml(string $transToken, string $msisdn, string $mno, string $mnoCountry): string
    {
        return $this->envelope('ChargeTokenMobile',
            '<TransactionToken>'.$this->x($transToken).'</TransactionToken>'
            .'<PhoneNumber>'.$this->x($msisdn).'</PhoneNumber>'
            .'<MNO>'.$this->x($mno).'</MNO>'
            .'<MNOcountry>'.$this->x($mnoCountry).'</MNOcountry>',
        );
    }

    private function verifyTokenXml(string $transToken): string
    {
        return $this->envelope('verifyToken', '<TransactionToken>'.$this->x($transToken).'</TransactionToken>');
    }

    /**
     * Wrap a request body in the DPO API3G envelope with the company token.
     */
    private function envelope(string $request, string $body): string
    {
        return '<?xml version="1.0" encoding="utf-8"?>'
            .'<API3G>'
            .'<CompanyToken>'.$this->x($this->companyToken).'</CompanyToken>'
            .'<Request>'.$request.'</Request>'
            .$body
            .'</API3G>';
    }

    /**
     * Read a top-level tag value from a DPO XML response.
     */
    private function tag(Response $response, string $tag): ?string
    {
        if (preg_match('/<'.$tag.'>\s*(.*?)\s*<\/'.$tag.'>/is', $response->body(), $matches)) {
            return html_entity_decode(trim($matches[1]));
        }

        return null;
    }

    /**
     * XML-escape a value.
     */
    private function x(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1);
    }

    private function appUrl(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    private function newReference(): string
    {
        return 'MU-'.now()->format('YmdHis').'-'.Str::upper(Str::random(8));
    }

    /**
     * Find or create the payer's customer record idempotently.
     */
    private function resolveCustomer(string $msisdn, string $country): Customer
    {
        return DB::transaction(function () use ($msisdn, $country): Customer {
            $customer = Customer::firstOrCreate(
                ['email' => $msisdn.'@moneyunify.local'],
                ['name' => 'Customer '.$msisdn],
            );

            CustomerAccount::updateOrCreate(
                ['number' => $msisdn, 'country' => $country],
                ['customer_id' => $customer->id, 'name' => $customer->name],
            );

            return $customer;
        });
    }

    /**
     * Normalise a phone number to the international MSISDN DPO expects
     * (country code, no leading zero, no '+', e.g. "260977123456").
     */
    private function normaliseMsisdn(string $number, string $country): string
    {
        $digits = preg_replace('/\D/', '', $number) ?? '';
        $digits = ltrim($digits, '0');

        $callingCode = Market::callingCode($country);

        if (! $callingCode || str_starts_with($digits, $callingCode)) {
            return $digits;
        }

        return $callingCode.$digits;
    }

    /**
     * Map a DPO verifyToken result code onto our internal TransactionStatus.
     * 000 = paid; 904 = cancelled / 999 = declined; 900 = not paid yet.
     */
    private static function mapStatus(string $result): TransactionStatus
    {
        return match ($result) {
            '000' => TransactionStatus::SUCCESS,
            '904', '999' => TransactionStatus::FAILED,
            default => TransactionStatus::PENDING,
        };
    }

    /**
     * A human-readable message that matches the transaction outcome.
     */
    private static function statusMessage(TransactionStatus $status): string
    {
        return match ($status) {
            TransactionStatus::SUCCESS => 'Transaction completed successfully',
            TransactionStatus::FAILED => 'Transaction failed',
            default => 'Transaction is still pending',
        };
    }
}
