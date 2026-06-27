<?php

namespace App\Http\Controllers;

use App\Contracts\PaymentProviderInterface;
use App\Models\PaymentProvider;
use App\Support\Market;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
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
                    ];
                }
            }
        }

        return Inertia::render('providers/index', [
            'providers' => $this->providersForDisplay($request->user()),
            'availableDrivers' => $drivers,
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
     * (only the non-sensitive `supported_countries` is sent to the browser).
     *
     * @return Collection<int, PaymentProvider>
     */
    private function providersForDisplay($user)
    {
        return $user->paymentProviders()->get()->map(function (PaymentProvider $provider): PaymentProvider {
            $config = is_string($provider->config) ? json_decode($provider->config, true) : ($provider->config ?? []);

            // Keep non-secret config (so the edit form can pre-fill it) but never
            // send password-type credentials to the browser.
            $safe = ['supported_countries' => $config['supported_countries'] ?? []];

            foreach ($this->configFieldsFor($provider->class) as $field) {
                if (($field['type'] ?? '') !== 'password') {
                    $safe[$field['key']] = $config[$field['key']] ?? null;
                }
            }

            $provider->setAttribute('config', $safe);

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
