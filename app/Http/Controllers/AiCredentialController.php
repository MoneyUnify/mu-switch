<?php

namespace App\Http\Controllers;

use App\Ai\Agents\ConnectionCheck;
use App\Enums\AiProvider;
use App\Http\Requests\StoreAiCredentialRequest;
use App\Http\Requests\UpdateAiCredentialRequest;
use App\Models\AiCredential;
use App\Support\Ai\AiProviderChain;
use App\Support\Ai\PaypalMcp;
use App\Support\Ai\ResolvedAiProvider;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Throwable;

/**
 * An account's AI providers: the keys its agents use, in failover order.
 */
class AiCredentialController extends Controller
{
    /**
     * Show the account's AI providers and PayPal MCP status.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $envDefault = AiProviderChain::envDefault();

        return Inertia::render('ai/index', [
            'providers' => AiProvider::options(),
            'credentials' => $user->aiCredentials()->get()->map(function (AiCredential $credential): array {
                $resolved = ResolvedAiProvider::resolve($credential->provider->value, $credential->model, $credential->api_key);

                return [
                    'id' => $credential->id,
                    'provider' => $credential->provider->value,
                    'label' => $credential->provider->label(),
                    'model' => $credential->model,
                    'defaultModel' => $credential->provider->defaultModel(),
                    'maskedKey' => $credential->api_key ? ResolvedAiProvider::mask($credential->api_key) : null,
                    'keySource' => $resolved->keySource,
                    'envKey' => $credential->provider->envKey(),
                ];
            })->values(),
            'envDefault' => [
                'provider' => $envDefault->provider,
                'label' => AiProvider::tryFrom($envDefault->provider)?->label() ?? $envDefault->provider,
                'hasKey' => $envDefault->hasKey(),
            ],
            'paypalMcp' => PaypalMcp::statusFor($user),
            'moneyunifyMcp' => ['operator' => route('mcp.operator'), 'merchant' => route('mcp.merchant')],
        ]);
    }

    /**
     * Add a provider to the end of the failover order.
     */
    public function store(StoreAiCredentialRequest $request): RedirectResponse
    {
        $user = $request->user();

        $user->aiCredentials()->create([
            ...$request->safe()->only(['provider', 'model', 'api_key']),
            'priority' => (int) $user->aiCredentials()->max('priority') + 1,
        ]);

        return $this->done(__(':provider added.', ['provider' => AiProvider::from($request->validated('provider'))->label()]));
    }

    /**
     * Change a provider's model or key. A blank key keeps the saved one.
     */
    public function update(UpdateAiCredentialRequest $request, AiCredential $credential): RedirectResponse
    {
        $this->authorizeOwner($request, $credential);

        $credential->model = $request->validated('model') ?: null;

        if (filled($request->validated('api_key'))) {
            $credential->api_key = $request->validated('api_key');
        }

        $credential->save();

        return $this->done(__(':provider updated.', ['provider' => $credential->provider->label()]));
    }

    /**
     * Remove a provider from the failover order.
     */
    public function destroy(Request $request, AiCredential $credential): RedirectResponse
    {
        $this->authorizeOwner($request, $credential);

        $credential->delete();

        return $this->done(__(':provider removed.', ['provider' => $credential->provider->label()]));
    }

    /**
     * Forget a provider's saved key so it uses the .env key.
     */
    public function destroyKey(Request $request, AiCredential $credential): RedirectResponse
    {
        $this->authorizeOwner($request, $credential);

        $credential->update(['api_key' => null]);

        return $this->done(__('Saved key removed. :provider now uses the .env key.', ['provider' => $credential->provider->label()]));
    }

    /**
     * Save a new failover order.
     */
    public function reorder(Request $request): RedirectResponse
    {
        $user = $request->user();
        $ids = $user->aiCredentials()->pluck('id');

        $validated = $request->validate([
            'ids' => ['required', 'array', 'size:'.$ids->count()],
            'ids.*' => ['integer', 'distinct', Rule::in($ids->all())],
        ]);

        DB::transaction(function () use ($user, $validated): void {
            foreach ($validated['ids'] as $index => $id) {
                $user->aiCredentials()->whereKey($id)->update(['priority' => $index + 1]);
            }
        });

        return $this->done(__('Failover order saved.'));
    }

    /**
     * Send a tiny prompt through one provider to prove its key works.
     */
    public function test(Request $request, AiCredential $credential): RedirectResponse
    {
        $this->authorizeOwner($request, $credential);

        $label = $credential->provider->label();
        $resolved = ResolvedAiProvider::resolve($credential->provider->value, $credential->model, $credential->api_key);

        if (! $resolved->hasKey()) {
            return $this->done(__('No API key is set for :provider. Add one here or set :env in .env.', [
                'provider' => $label,
                'env' => $credential->provider->envKey(),
            ]), 'error');
        }

        $model = $credential->model ?: $credential->provider->defaultModel();
        $check = fn () => (new ConnectionCheck)->prompt('Connection check', provider: [$resolved->providerName() => $resolved->model]);

        try {
            try {
                $check();
            } catch (ProviderOverloadedException|RateLimitedException) {
                // A busy model often recovers within seconds: try once more.
                Sleep::for(2)->seconds();
                $check();
            }
        } catch (Throwable $e) {
            return $this->done(__('Could not connect to :provider using :model: :reason', [
                'provider' => $label,
                'model' => $model,
                'reason' => $this->failureReason($e),
            ]), 'error');
        }

        return $this->done(__('Connected to :provider using :model.', ['provider' => $label, 'model' => $model]));
    }

    /**
     * The provider's own error, e.g. "503: the model is experiencing high
     * demand", rather than the SDK's message naming an internal provider.
     */
    private function failureReason(Throwable $e): string
    {
        $request = $e instanceof RequestException ? $e : $e->getPrevious();

        if ($request instanceof RequestException && $request->response !== null) {
            $message = $request->response->json('error.message') ?? $request->response->json('message');

            $reason = $request->response->status().(is_string($message) && $message !== '' ? ': '.$message : '');

            if ($e instanceof ProviderOverloadedException || $e instanceof RateLimitedException) {
                $reason .= ' '.__('Try again shortly, or choose a different model.');
            }

            return Str::limit($reason, 220);
        }

        return Str::limit((string) preg_replace('/\[account_[0-9a-f]+\]/', '', $e->getMessage()), 220);
    }

    private function authorizeOwner(Request $request, AiCredential $credential): void
    {
        abort_unless($credential->user_id === $request->user()->id, 403);
    }

    private function done(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return to_route('ai.index');
    }
}
