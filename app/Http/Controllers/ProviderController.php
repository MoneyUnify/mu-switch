<?php

namespace App\Http\Controllers;

use App\Contracts\PaymentProviderInterface;
use App\Enums\TransactionStatus;
use App\Models\PaymentProvider;
use App\Models\ProviderLog;
use App\Models\Transaction;
use App\Support\Market;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProviderController extends Controller
{
    /**
     * Display a listing of the user's payment providers.
     */
    public function index(Request $request): Response
    {
        $drivers = [];
        $files = glob(app_path('Http/Controllers/Providers/*.php'));

        if ($files) {
            foreach ($files as $file) {
                $className = 'App\\Http\\Controllers\\Providers\\'.pathinfo($file, PATHINFO_FILENAME);
                if (class_exists($className) && is_subclass_of($className, PaymentProviderInterface::class)) {
                    $name = defined($className.'::PROVIDER_NAME') ? $className::PROVIDER_NAME : pathinfo($file, PATHINFO_FILENAME);
                    if (! defined($className.'::PROVIDER_NAME') && str_ends_with($name, 'Controller')) {
                        $name = substr($name, 0, -10);
                    }

                    $supported = $this->supportedCountriesFor($className);
                    $defaults = defined($className.'::DEFAULT_COUNTRIES')
                        ? array_map('trim', explode(',', $className::DEFAULT_COUNTRIES))
                        : $supported;

                    $drivers[] = [
                        'name' => $name,
                        'class' => $className,
                        // The markets this driver can serve, with name + currency, for the country checkboxes.
                        'supported_country_options' => Market::options($supported),
                        // Pre-ticked markets when adding the provider.
                        'default_countries' => array_values(array_intersect($defaults, $supported)),
                        // Default (hard-coded) logo for this driver, editable in the dialog.
                        'default_logo' => defined($className.'::DEFAULT_LOGO') ? $className::DEFAULT_LOGO : null,
                        // The credential inputs the dashboard should render for this driver.
                        'config_fields' => $this->configFieldsFor($className),
                        // Optional per-market input (e.g. an operator code for each ticked country).
                        'market_field' => $this->marketFieldFor($className),
                    ];
                }
            }
        }

        $user = $request->user();
        $accounts = $this->accountSummaries($user->paymentProviders()->pluck('id'));

        return Inertia::render('providers/index', [
            'providers' => $this->providersForDisplay($user, $accounts),
            'availableDrivers' => $drivers,
        ]);
    }

    /**
     * Per-provider, per-currency money summary of successful transactions:
     * inflow (collected), outflow (fees) and net.
     *
     * @return Collection<int, list<array{currency: string, count: int, inflow: float, outflow: float, net: float}>>
     */
    private function accountSummaries(Collection $providerIds): Collection
    {
        return Transaction::query()
            ->whereIn('payment_provider_id', $providerIds)
            ->where('status', TransactionStatus::SUCCESS->value)
            ->selectRaw('payment_provider_id, currency')
            ->selectRaw('count(*) as count')
            ->selectRaw('coalesce(sum(amount), 0) as inflow')
            ->selectRaw('coalesce(sum(coalesce(collection_fee, 0) + coalesce(settlement_fee, 0)), 0) as outflow')
            ->selectRaw('coalesce(sum(coalesce(net_amount, amount)), 0) as net')
            ->groupBy('payment_provider_id', 'currency')
            ->orderByDesc('inflow')
            ->get()
            ->groupBy('payment_provider_id')
            ->map(fn (Collection $rows): array => $rows->map(fn ($row): array => [
                'currency' => (string) $row->currency,
                'count' => (int) $row->count,
                'inflow' => round((float) $row->inflow, 2),
                'outflow' => round((float) $row->outflow, 2),
                'net' => round((float) $row->net, 2),
            ])->values()->all());
    }

    /**
     * Show the outgoing call history (requests + responses) for a provider.
     */
    public function logs(Request $request, PaymentProvider $provider): Response
    {
        if ($provider->user_id !== $request->user()->id) {
            abort(403);
        }

        $logs = $provider->providerLogs()
            ->latest('id')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (ProviderLog $log): array => [
                'id' => $log->id,
                'method' => $log->method,
                'url' => $log->url,
                'host' => $log->host,
                'path' => parse_url((string) $log->url, PHP_URL_PATH) ?: $log->url,
                'status' => $log->response_status,
                'durationMs' => $log->duration_ms,
                'failed' => $log->failed,
                'errorMessage' => $log->error_message,
                'requestHeaders' => $log->request_headers,
                'requestBody' => $log->request_body,
                'responseBody' => $log->response_body,
                'createdAtHuman' => $log->created_at?->diffForHumans(),
                'createdAt' => $log->created_at?->format('Y-m-d H:i:s T'),
            ]);

        return Inertia::render('providers/logs', [
            'provider' => [
                'id' => $provider->id,
                'name' => $provider->name,
                'class' => $provider->class,
                'logo_url' => $provider->logo_url,
                'is_active' => $provider->is_active,
            ],
            'logs' => $logs,
            'stats' => [
                'total' => $provider->providerLogs()->count(),
                'successful' => $provider->providerLogs()->whereBetween('response_status', [200, 299])->count(),
                'failed' => $provider->providerLogs()->where('failed', true)->count(),
            ],
        ]);
    }

    /**
     * Store a newly created payment provider.
     */
    public function store(Request $request): RedirectResponse
    {
        $fields = $this->configFieldsFor((string) $request->input('class'));

        $supported = $this->supportedCountriesFor((string) $request->input('class'));

        $rules = [
            'name' => 'required|string|unique:payment_providers,name',
            'class' => 'required|string',
            'supported_countries' => 'required|array|min:1',
            'supported_countries.*' => ['string', Rule::in($supported)],
            'is_active' => 'required|boolean',
            'logo_url' => 'nullable|string',
        ];
        foreach ($fields as $field) {
            $rules['config.'.$field['key']] = 'required|'.($field['rules'] ?? 'string');
        }

        $validated = $request->validate($rules);

        $config = $validated['config'];
        $config['supported_countries'] = array_values($validated['supported_countries']);
        $this->applyMarketValues($config, $request, $validated['class'], $validated['supported_countries']);

        $request->user()->paymentProviders()->create([
            'name' => $validated['name'],
            'class' => $validated['class'],
            'config' => $config,
            'is_active' => $validated['is_active'],
            'logo_url' => $validated['logo_url'] ?? null,
        ]);

        return redirect()->route('providers.index');
    }

    /**
     * Update the specified payment provider.
     */
    public function update(Request $request, PaymentProvider $provider): RedirectResponse
    {
        if ($provider->user_id !== $request->user()->id) {
            abort(403);
        }

        $fields = $this->configFieldsFor((string) $request->input('class'));
        $supported = $this->supportedCountriesFor((string) $request->input('class'));

        $rules = [
            'name' => 'required|string|unique:payment_providers,name,'.$provider->id,
            'class' => 'required|string',
            'supported_countries' => 'required|array|min:1',
            'supported_countries.*' => ['string', Rule::in($supported)],
            'is_active' => 'required|boolean',
            'logo_url' => 'nullable|string',
        ];
        foreach ($fields as $field) {
            // Credentials are optional on update — leave blank to keep the current value.
            $rules['config.'.$field['key']] = 'nullable|'.($field['rules'] ?? 'string');
        }

        $validated = $request->validate($rules);

        $config = is_string($provider->config) ? json_decode($provider->config, true) : ($provider->config ?? []);

        foreach ($fields as $field) {
            $value = $validated['config'][$field['key']] ?? null;
            if (! empty($value)) {
                $config[$field['key']] = $value;
            }
        }

        $config['supported_countries'] = array_values($validated['supported_countries']);
        $this->applyMarketValues($config, $request, $validated['class'], $validated['supported_countries']);

        $provider->update([
            'name' => $validated['name'],
            'class' => $validated['class'],
            'config' => $config,
            'is_active' => $validated['is_active'],
            'logo_url' => $validated['logo_url'] ?? null,
        ]);

        return redirect()->route('providers.index');
    }

    /**
     * The optional per-market field a driver collects for each ticked country
     * (e.g. Ting's operator payment-option code), or null.
     *
     * @return array{key: string, label: string, placeholder?: string}|null
     */
    private function marketFieldFor(string $className): ?array
    {
        return $className !== '' && defined($className.'::MARKET_FIELD') ? $className::MARKET_FIELD : null;
    }

    /**
     * Persist the per-market values (one per ticked country) into the config,
     * requiring a value for every ticked market when the driver declares one.
     *
     * @param  array<string, mixed>  $config
     * @param  list<string>  $supportedCountries
     */
    private function applyMarketValues(array &$config, Request $request, string $class, array $supportedCountries): void
    {
        $marketField = $this->marketFieldFor($class);
        if (! $marketField) {
            return;
        }

        $values = [];
        foreach ($supportedCountries as $country) {
            $value = trim((string) $request->input("market_values.{$country}", ''));
            if ($value === '') {
                throw ValidationException::withMessages([
                    "market_values.{$country}" => "A {$marketField['label']} is required for {$country}.",
                ]);
            }
            $values[$country] = $value;
        }

        $config[$marketField['key']] = $values;
    }

    /**
     * The credential fields a driver requires, defaulting to a single API key.
     *
     * @return list<array{key: string, label: string, type: string}>
     */
    private function configFieldsFor(string $className): array
    {
        if ($className !== '' && defined($className.'::CONFIG_FIELDS')) {
            return $className::CONFIG_FIELDS;
        }

        return [['key' => 'api_key', 'label' => 'API Key / Token', 'type' => 'password']];
    }

    /**
     * The markets a driver can serve, from its SUPPORTED_COUNTRIES (falling back
     * to DEFAULT_COUNTRIES, then the platform's known markets).
     *
     * @return list<string>
     */
    private function supportedCountriesFor(string $className): array
    {
        if ($className !== '' && defined($className.'::SUPPORTED_COUNTRIES')) {
            return $className::SUPPORTED_COUNTRIES;
        }

        if ($className !== '' && defined($className.'::DEFAULT_COUNTRIES')) {
            return array_map('trim', explode(',', $className::DEFAULT_COUNTRIES));
        }

        return Market::codes();
    }

    /**
     * The user's providers with credential secrets stripped from `config`
     * (only the non-sensitive `supported_countries` is sent to the browser),
     * each annotated with its per-currency account summary.
     *
     * @param  Collection<int, list<array<string, mixed>>>  $accounts
     * @return Collection<int, PaymentProvider>
     */
    private function providersForDisplay($user, Collection $accounts)
    {
        return $user->paymentProviders()->get()->map(function (PaymentProvider $provider) use ($accounts): PaymentProvider {
            $config = is_string($provider->config) ? json_decode($provider->config, true) : ($provider->config ?? []);

            // Keep non-secret config (so the edit form can pre-fill it) but never
            // send password-type credentials to the browser.
            $safe = ['supported_countries' => $config['supported_countries'] ?? []];

            foreach ($this->configFieldsFor($provider->class) as $field) {
                if (($field['type'] ?? '') !== 'password') {
                    $safe[$field['key']] = $config[$field['key']] ?? null;
                }
            }

            // Per-market values (e.g. operator codes) are not secret — send them
            // so the edit form can pre-fill each market's input.
            if ($marketField = $this->marketFieldFor($provider->class)) {
                $safe[$marketField['key']] = $config[$marketField['key']] ?? [];
            }

            $provider->setAttribute('config', $safe);
            $provider->setAttribute('accounts', $accounts[$provider->id] ?? []);

            return $provider;
        });
    }

    /**
     * Remove the specified payment provider.
     */
    public function destroy(Request $request, PaymentProvider $provider): RedirectResponse
    {
        if ($provider->user_id !== $request->user()->id) {
            abort(403);
        }

        $provider->delete();

        return redirect()->route('providers.index');
    }
}
