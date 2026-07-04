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
 * cGrate (Konse Konse 543) driver — mobile-money collections in Zambia.
 *
 * cGrate's Konik web service is SOAP (document/literal, WSDL at
 * /Konik/KonikWs?wsdl) secured with a WS-Security UsernameToken header. The
 * driver builds the envelopes itself and posts them over plain HTTP so the
 * calls stay testable and flow through the switch's provider call-logging —
 * no php-soap extension required. A merchant only configures their cGrate
 * account username and password in the dashboard.
 *
 * `processCustomerPayment` is synchronous: the payer confirms the USSD prompt
 * while the call is held open, so responseCode 0 means the payment COMPLETED
 * (not merely got accepted), and 8 means it is still being processed.
 */
class CgrateController extends Controller implements PaymentProviderInterface
{
    public const PROVIDER_NAME = 'cGrate (Konse Konse 543)';

    /**
     * cGrate operates in Zambia only.
     */
    public const SUPPORTED_COUNTRIES = ['ZM'];

    public const DEFAULT_COUNTRIES = 'ZM';

    /**
     * Default logo shown for this driver (editable in the provider dialog).
     */
    public const DEFAULT_LOGO = '/cgrate.png';

    /**
     * The credential fields the dashboard should collect for this driver.
     */
    public const CONFIG_FIELDS = [
        ['key' => 'username', 'label' => 'API Username', 'type' => 'text'],
        ['key' => 'password', 'label' => 'API Password', 'type' => 'password'],
    ];

    /**
     * cGrate Konik production endpoint (we run providers in production only).
     */
    private const BASE_URL = 'https://543.cgrate.co.zm/Konik/KonikWs';

    /**
     * The Konik service XML namespace (from the WSDL targetNamespace).
     */
    private const KONIK_NS = 'http://konik.cgrate.com';

    /**
     * cGrate response codes the driver maps onto transaction states.
     * 0 = success; 8 = process delay (still pending); the rest are failures.
     */
    private const CODE_SUCCESS = 0;

    private const CODE_PROCESS_DELAY = 8;

    /**
     * Codes meaning "the reference could not be looked up" — a verification
     * problem, not a settled outcome, so the stored status is left untouched.
     */
    private const QUERY_LOOKUP_FAILURES = [105, 106];

    private string $username;

    private string $password;

    public ?PaymentProvider $provider = null;

    /**
     * Inject the configured provider's credentials into the driver.
     */
    public function setProvider(PaymentProvider $provider): ?JsonResponse
    {
        $config = is_string($provider->config) ? json_decode($provider->config, true) : $provider->config;

        $username = $config['username'] ?? null;
        $password = $config['password'] ?? null;

        if (! $username || ! $password) {
            return ApiResponse::error('API Username and Password are required for the cGrate provider', 400);
        }

        $this->username = $username;
        $this->password = $password;
        $this->provider = $provider;

        return null;
    }

    /**
     * Initiate (and synchronously settle) a customer payment with cGrate.
     */
    public function requestPayment(Request $request): JsonResponse
    {
        $country = strtoupper($request['country']);
        $currency = Market::currency($country);
        $msisdn = $this->normaliseMsisdn($request['account_number'], $country);

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
            // Optional: where the switch posts the final result once verified.
            'callback_url' => $request->input('callback_url'),
        ]);
        $transaction->paymentProvider()->associate($this->provider);
        $transaction->save();

        $response = $this->soapCall('processCustomerPayment', [
            'transactionAmount' => number_format((float) $request['amount'], 2, '.', ''),
            'customerMobile' => $msisdn,
            'paymentReference' => $reference,
        ]);

        $result = $this->parseResponse($response);

        if ($result === null) {
            $transaction->update([
                'status' => TransactionStatus::FAILED,
                'provider_response' => ['body' => mb_substr($response->body(), 0, 5000)],
            ]);

            return ApiResponse::error($this->faultMessage($response) ?? 'cGrate payment request failed', 502);
        }

        // 0 = the payer approved and the payment settled; 8 = still processing.
        $status = match ($result['responseCode']) {
            self::CODE_SUCCESS => TransactionStatus::SUCCESS,
            self::CODE_PROCESS_DELAY => TransactionStatus::PENDING,
            default => TransactionStatus::FAILED,
        };

        $transaction->update([
            'status' => $status,
            'provider_transaction_id' => $result['paymentID'] ?? $reference,
            'provider_response' => $result,
        ]);

        if ($status === TransactionStatus::FAILED) {
            return ApiResponse::error($result['responseMessage'] ?: 'cGrate payment request failed', 422);
        }

        return ApiResponse::success(
            $status === TransactionStatus::SUCCESS
                ? 'Payment completed successfully'
                : 'Payment request initiated successfully',
            [
                'transaction_id' => $transaction->transaction_id,
                'reference' => $transaction->provider_transaction_id,
                'status' => $status->value,
            ],
        );
    }

    /**
     * Re-check a transaction with cGrate and persist its latest status.
     *
     * cGrate looks a payment up by the paymentReference we sent, which is our
     * own transaction_id.
     */
    public function verifyPayment(Transaction $transaction): JsonResponse
    {
        $response = $this->soapCall('queryCustomerPayment', [
            'paymentReference' => $transaction->transaction_id,
        ]);

        $result = $this->parseResponse($response);

        if ($result === null) {
            return ApiResponse::error($this->faultMessage($response) ?? 'Unable to verify the transaction with cGrate', 502);
        }

        // A lookup failure is a verification error, not a settled outcome.
        if (in_array($result['responseCode'], self::QUERY_LOOKUP_FAILURES, true)) {
            return ApiResponse::error($result['responseMessage'] ?: 'cGrate could not find the transaction', 404);
        }

        $status = match ($result['responseCode']) {
            self::CODE_SUCCESS => TransactionStatus::SUCCESS,
            self::CODE_PROCESS_DELAY => TransactionStatus::PENDING,
            default => TransactionStatus::FAILED,
        };

        $transaction->update([
            'status' => $status,
            'provider_response' => $result,
            'provider_transaction_id' => $result['paymentID'] ?? $transaction->provider_transaction_id,
        ]);

        return ApiResponse::status($status->value, self::statusMessage($status), [
            'transaction_id' => $transaction->transaction_id,
            'reference' => $transaction->provider_transaction_id,
            'status' => $status->value,
            'provider_status' => (string) $result['responseCode'],
            'amount' => (float) $transaction->amount,
            'currency' => $transaction->currency,
        ]);
    }

    /**
     * POST a document/literal SOAP 1.1 request to the Konik service.
     *
     * @param  array<string, string>  $fields  Operation child elements, in order.
     */
    private function soapCall(string $operation, array $fields): Response
    {
        $children = '';
        foreach ($fields as $name => $value) {
            $children .= '<'.$name.'>'.htmlspecialchars((string) $value, ENT_XML1).'</'.$name.'>';
        }

        $envelope = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:kon="'.self::KONIK_NS.'">'
            .'<soapenv:Header>'.$this->securityHeader().'</soapenv:Header>'
            .'<soapenv:Body>'
            .'<kon:'.$operation.'>'.$children.'</kon:'.$operation.'>'
            .'</soapenv:Body>'
            .'</soapenv:Envelope>';

        return Http::withHeaders([
            'Content-Type' => 'text/xml; charset=utf-8',
            'SOAPAction' => '""',
        ])->withBody($envelope, 'text/xml')->post(self::BASE_URL);
    }

    /**
     * The WS-Security UsernameToken header (PasswordText profile).
     */
    private function securityHeader(): string
    {
        $wsse = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd';
        $passwordType = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-username-token-profile-1.0#PasswordText';

        return '<wsse:Security soapenv:mustUnderstand="1" xmlns:wsse="'.$wsse.'">'
            .'<wsse:UsernameToken>'
            .'<wsse:Username>'.htmlspecialchars($this->username, ENT_XML1).'</wsse:Username>'
            .'<wsse:Password Type="'.$passwordType.'">'.htmlspecialchars($this->password, ENT_XML1).'</wsse:Password>'
            .'</wsse:UsernameToken>'
            .'</wsse:Security>';
    }

    /**
     * Parse a Konik response envelope into its return fields, or null when the
     * body is a fault / not a recognisable response.
     *
     * @return array{responseCode: int, responseMessage: string, paymentID: ?string}|null
     */
    private function parseResponse(Response $response): ?array
    {
        $body = $response->body();

        if (! preg_match('/<responseCode>\s*(-?\d+)\s*<\/responseCode>/i', $body, $code)) {
            return null;
        }

        preg_match('/<responseMessage>\s*(.*?)\s*<\/responseMessage>/is', $body, $message);
        preg_match('/<paymentID>\s*(.*?)\s*<\/paymentID>/is', $body, $paymentId);

        return [
            'responseCode' => (int) $code[1],
            'responseMessage' => html_entity_decode(trim($message[1] ?? '')),
            'paymentID' => isset($paymentId[1]) && trim($paymentId[1]) !== '' ? html_entity_decode(trim($paymentId[1])) : null,
        ];
    }

    /**
     * Extract a SOAP fault message from an error envelope, if present.
     */
    private function faultMessage(Response $response): ?string
    {
        if (preg_match('/<faultstring[^>]*>\s*(.*?)\s*<\/faultstring>/is', $response->body(), $fault)) {
            return html_entity_decode(trim($fault[1]));
        }

        return null;
    }

    /**
     * A unique payment reference in the format cGrate expects (unique per
     * transaction, alphanumeric with dashes).
     */
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
     * Normalise a phone number to the 12-digit international MSISDN cGrate
     * expects (e.g. "260977123456").
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
