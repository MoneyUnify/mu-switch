<?php

namespace App\Http\Controllers;

use App\Contracts\PaymentProviderInterface;
use App\Models\PaymentProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

                    $defaultCountries = defined($className.'::DEFAULT_COUNTRIES') ? $className::DEFAULT_COUNTRIES : 'ZM,MW';

                    $drivers[] = [
                        'name' => $name,
                        'class' => $className,
                        'default_countries' => $defaultCountries,
                    ];
                }
            }
        }

        return Inertia::render('providers/index', [
            'providers' => $request->user()->paymentProviders()->get(),
            'availableDrivers' => $drivers,
        ]);
    }

    /**
     * Store a newly created payment provider.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|unique:payment_providers,name',
            'class' => 'required|string',
            'api_key' => 'required|string',
            'supported_countries' => 'required|string',
            'is_active' => 'required|boolean',
            'logo_url' => 'nullable|string',
        ]);

        $request->user()->paymentProviders()->create([
            'name' => $validated['name'],
            'class' => $validated['class'],
            'config' => [
                'api_key' => $validated['api_key'],
                'supported_countries' => array_map('trim', explode(',', $validated['supported_countries'])),
            ],
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

        $validated = $request->validate([
            'name' => 'required|string|unique:payment_providers,name,'.$provider->id,
            'class' => 'required|string',
            'api_key' => 'nullable|string',
            'supported_countries' => 'required|string',
            'is_active' => 'required|boolean',
            'logo_url' => 'nullable|string',
        ]);

        $config = $provider->config;
        if (is_string($config)) {
            $config = json_decode($config, true);
        }
        $config = $config ?? [];

        if (! empty($validated['api_key'])) {
            $config['api_key'] = $validated['api_key'];
        }

        $config['supported_countries'] = array_map('trim', explode(',', $validated['supported_countries']));

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
