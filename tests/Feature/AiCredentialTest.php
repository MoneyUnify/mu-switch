<?php

use App\Ai\Agents\ConnectionCheck;
use App\Enums\AiProvider;
use App\Models\AiCredential;
use App\Models\User;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Exceptions\ProviderOverloadedException;

beforeEach(function () {
    config([
        'ai.default' => 'anthropic',
        'ai.providers.anthropic.key' => 'env-anthropic-key',
        'ai.providers.openai.key' => null,
    ]);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function aiCredential(User $user, string $provider, array $attributes = []): AiCredential
{
    return $user->aiCredentials()->create([
        'provider' => $provider,
        'priority' => (int) $user->aiCredentials()->max('priority') + 1,
        ...$attributes,
    ]);
}

test('guests cannot open the ai page', function () {
    $this->get(route('ai.index'))->assertRedirect(route('login'));
});

test('the page lists providers in failover order with their key source', function () {
    $user = User::factory()->create();
    aiCredential($user, 'openai', ['model' => 'gpt-test', 'api_key' => 'sk-account-key-1234']);
    aiCredential($user, 'anthropic');
    aiCredential($user, 'gemini');

    $this->actingAs($user)
        ->get(route('ai.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('ai/index')
            ->has('providers', count(AiProvider::cases()))
            ->where('providers.2.value', 'gemini')
            ->where('providers.2.defaultModel', 'gemini-3.8-flash')
            ->where('providers.2.models.1.id', 'gemini-3-flash-preview')
            ->where('credentials.0.provider', 'openai')
            ->where('credentials.2.defaultModel', 'gemini-3.8-flash')
            ->where('credentials.0.maskedKey', '••••••••1234')
            ->where('credentials.0.keySource', 'dashboard')
            ->where('credentials.1.keySource', 'env')
            ->where('credentials.2.keySource', null)
            ->where('envDefault.provider', 'anthropic')
            ->where('paypalMcp.source', null));
});

test('a provider can be added with an encrypted key', function () {
    $user = User::factory()->create();
    aiCredential($user, 'anthropic');

    $this->actingAs($user)
        ->post(route('ai.credentials.store'), ['provider' => 'openai', 'model' => 'gpt-test', 'api_key' => 'sk-account-key-1234'])
        ->assertRedirect(route('ai.index'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'OpenAI added.']);

    $credential = $user->aiCredentials()->where('provider', 'openai')->sole();

    expect($credential->priority)->toBe(2)
        ->and($credential->api_key)->toBe('sk-account-key-1234')
        ->and(DB::table('ai_credentials')->where('id', $credential->id)->value('api_key'))->not->toContain('sk-account-key-1234');
});

test('each provider can only be added once', function () {
    $user = User::factory()->create();
    aiCredential($user, 'openai');

    $this->actingAs($user)
        ->post(route('ai.credentials.store'), ['provider' => 'openai'])
        ->assertSessionHasErrors(['provider' => 'This provider is already in your list.']);
});

test('unknown providers and short keys are rejected', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('ai.credentials.store'), ['provider' => 'skynet', 'api_key' => 'short'])
        ->assertSessionHasErrors(['provider', 'api_key']);
});

test('updating with a blank key keeps the saved one', function () {
    $user = User::factory()->create();
    $credential = aiCredential($user, 'openai', ['api_key' => 'sk-account-key-1234']);

    $this->actingAs($user)
        ->put(route('ai.credentials.update', $credential), ['model' => 'gpt-new', 'api_key' => ''])
        ->assertRedirect(route('ai.index'));

    $credential->refresh();

    expect($credential->model)->toBe('gpt-new')
        ->and($credential->api_key)->toBe('sk-account-key-1234');
});

test('a saved key can be removed and a provider deleted', function () {
    $user = User::factory()->create();
    $credential = aiCredential($user, 'openai', ['api_key' => 'sk-account-key-1234']);

    $this->actingAs($user)->delete(route('ai.credentials.key.destroy', $credential))->assertRedirect(route('ai.index'));
    expect($credential->refresh()->api_key)->toBeNull();

    $this->actingAs($user)->delete(route('ai.credentials.destroy', $credential))->assertRedirect(route('ai.index'));
    expect($user->aiCredentials()->exists())->toBeFalse();
});

test('the failover order can be changed', function () {
    $user = User::factory()->create();
    $first = aiCredential($user, 'anthropic');
    $second = aiCredential($user, 'openai');

    $this->actingAs($user)
        ->put(route('ai.credentials.reorder'), ['ids' => [$second->id, $first->id]])
        ->assertRedirect(route('ai.index'));

    expect($user->aiCredentials()->pluck('provider')->map->value->all())->toBe(['openai', 'anthropic']);
});

test('reordering must list exactly the account\'s own providers', function () {
    $user = User::factory()->create();
    $mine = aiCredential($user, 'anthropic');
    aiCredential($user, 'openai');
    $theirs = aiCredential(User::factory()->create(), 'gemini');

    $this->actingAs($user)
        ->put(route('ai.credentials.reorder'), ['ids' => [$mine->id, $theirs->id]])
        ->assertSessionHasErrors('ids.1');

    $this->actingAs($user)
        ->put(route('ai.credentials.reorder'), ['ids' => [$mine->id]])
        ->assertSessionHasErrors('ids');
});

test('another account\'s provider cannot be touched', function () {
    $credential = aiCredential(User::factory()->create(), 'openai', ['api_key' => 'sk-account-key-1234']);
    $intruder = User::factory()->create();

    $this->actingAs($intruder)->put(route('ai.credentials.update', $credential), ['model' => 'x'])->assertForbidden();
    $this->actingAs($intruder)->delete(route('ai.credentials.destroy', $credential))->assertForbidden();
    $this->actingAs($intruder)->delete(route('ai.credentials.key.destroy', $credential))->assertForbidden();
    $this->actingAs($intruder)->post(route('ai.credentials.test', $credential))->assertForbidden();

    expect($credential->refresh()->api_key)->toBe('sk-account-key-1234');
});

test('testing a provider prompts it alone with its own credentials', function () {
    ConnectionCheck::fake(['OK'])->preventStrayPrompts();

    $user = User::factory()->create();
    aiCredential($user, 'anthropic');
    $openai = aiCredential($user, 'openai', ['model' => 'gpt-test', 'api_key' => 'sk-account-key-1234']);

    $this->actingAs($user)
        ->post(route('ai.credentials.test', $openai))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Connected to OpenAI using gpt-test.']);

    ConnectionCheck::assertPrompted(fn ($prompt) => $prompt->model === 'gpt-test');
});

test('a failing provider test is reported, not thrown', function () {
    ConnectionCheck::fake(fn () => throw new RuntimeException('invalid x-api-key'));

    $user = User::factory()->create();
    $credential = aiCredential($user, 'anthropic');

    $this->actingAs($user)
        ->post(route('ai.credentials.test', $credential))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Could not connect to Anthropic (Claude) using claude-sonnet-5-5: invalid x-api-key']);
});

test('a provider without any key is not tested', function () {
    ConnectionCheck::fake();

    $user = User::factory()->create();
    $credential = aiCredential($user, 'openai');

    $this->actingAs($user)
        ->post(route('ai.credentials.test', $credential))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'No API key is set for OpenAI. Add one here or set OPENAI_API_KEY in .env.']);

    ConnectionCheck::assertNeverPrompted();
});

/**
 * The SDK's overloaded error, wrapping the provider's own 503 response.
 */
function overloaded(): ProviderOverloadedException
{
    $response = new Response(new Psr7Response(503, ['Content-Type' => 'application/json'], json_encode([
        'error' => ['message' => 'gemini-3.8-flash is currently experiencing high demand.', 'code' => 'service_unavailable'],
    ])));

    return ProviderOverloadedException::forProvider('account_'.str_repeat('a', 64), 503, new RequestException($response));
}

test('a busy model is retried once before the test fails', function () {
    Sleep::fake();
    $calls = 0;
    ConnectionCheck::fake(function () use (&$calls) {
        return ++$calls === 1 ? throw overloaded() : 'OK';
    });

    $user = User::factory()->create();
    $credential = aiCredential($user, 'gemini', ['api_key' => 'gm-account-key-1234']);

    $this->actingAs($user)
        ->post(route('ai.credentials.test', $credential))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Connected to Google Gemini using gemini-3.8-flash.']);

    ConnectionCheck::assertPromptedTimes(2);
    Sleep::assertSleptTimes(1);
});

test('a still-busy model reports the provider\'s own error without internal names', function () {
    Sleep::fake();
    ConnectionCheck::fake(fn () => throw overloaded());

    $user = User::factory()->create();
    $credential = aiCredential($user, 'gemini', ['api_key' => 'gm-account-key-1234']);

    $this->actingAs($user)
        ->post(route('ai.credentials.test', $credential))
        ->assertInertiaFlash('toast', [
            'type' => 'error',
            'message' => 'Could not connect to Google Gemini using gemini-3.8-flash: 503: gemini-3.8-flash is currently experiencing high demand. Try again shortly, or choose a different model.',
        ]);
});

test('a listed or custom model can be chosen, and the test uses it', function () {
    ConnectionCheck::fake(['OK']);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('ai.credentials.store'), ['provider' => 'gemini', 'model' => 'gemini-3-flash-preview', 'api_key' => 'gm-account-key-1234'])
        ->assertSessionHasNoErrors();

    $credential = $user->aiCredentials()->sole();
    expect($credential->model)->toBe('gemini-3-flash-preview');

    $this->actingAs($user)
        ->put(route('ai.credentials.update', $credential), ['model' => 'gemini-2.5-pro-custom'])
        ->assertSessionHasNoErrors();
    expect($credential->fresh()->model)->toBe('gemini-2.5-pro-custom');

    $this->actingAs($user)
        ->post(route('ai.credentials.test', $credential))
        ->assertInertiaFlash('toast.message', 'Connected to Google Gemini using gemini-2.5-pro-custom.');

    ConnectionCheck::assertPrompted(fn ($prompt) => $prompt->model === 'gemini-2.5-pro-custom');
});

test('model ids must look like model ids', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('ai.credentials.store'), ['provider' => 'gemini', 'model' => 'gemini flash; drop table'])
        ->assertSessionHasErrors('model');
});
