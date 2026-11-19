<?php

use App\Ai\Agents\SendMoneyAgent;
use App\Ai\Tools\GetTransferStatus;
use App\Ai\Tools\ListCorridors;
use App\Ai\Tools\QuoteTransfer;
use App\Ai\Tools\StartTransfer;
use App\Http\Controllers\Providers\SandboxPayoutController;
use App\Models\CrossBorderTransfer;
use App\Models\PaymentProvider;
use App\Models\User;
use App\Support\CrossBorder\TransferIntent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Tools\Request as ToolRequest;

beforeEach(function () {
    Sleep::fake();
    Cache::flush();
    config(['ai.default' => 'anthropic', 'ai.providers.anthropic.key' => 'test-key']);

    $this->user = User::factory()->create();
    // PayPal MCP is off here, so the agent's tools never reach PayPal's MCP server.
    paypalProvider($this->user, ['mcp' => 'disabled']);
    PaymentProvider::create([
        'user_id' => $this->user->id,
        'name' => 'Simulated Payouts',
        'class' => SandboxPayoutController::class,
        'config' => ['supported_countries' => ['ZM']],
        'is_active' => true,
    ]);

    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'A21-paypal-token', 'expires_in' => 32400]),
        // Each PayPal order gets its own id, as in PayPal.
        '*/v2/checkout/orders' => fn () => Http::response(paypalOrder('ORDER-'.Str::upper(Str::random(8))), 201),
    ]);
});

function agentConversation(User $user): string
{
    $id = (string) Str::uuid();

    DB::table('agent_conversations')->insert([
        'id' => $id,
        'participant_type' => $user->getMorphClass(),
        'participant_id' => $user->id,
        'title' => 'Send money',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

test('the agent has the transfer tools, and starting a transfer always needs approval', function () {
    $tools = collect((new SendMoneyAgent($this->user))->tools())->map(fn ($tool) => class_basename($tool))->all();

    expect($tools)->toBe(['ListCorridors', 'QuoteTransfer', 'StartTransfer', 'GetTransferStatus']);

    $approval = (new StartTransfer($this->user))->shouldRequestApproval(new ToolRequest([
        'amount' => 2500, 'mode' => 'target', 'country' => 'ZM', 'account_number' => '0977123456',
    ]));

    expect($approval?->reason)->toBe('Send ZMW 2500.00 to 0977123456 in Zambia. The sender pays USD 92.30 with PayPal.');
});

test('the corridor and quote tools describe what can be sent without creating anything', function () {
    $corridors = json_decode((string) (new ListCorridors($this->user))->handle(new ToolRequest([])), true);

    expect($corridors['pay_out_to'])->toBe([['country' => 'ZM', 'name' => 'Zambia', 'currency' => 'ZMW']])
        ->and($corridors['sender_can_pay_in'])->toContain('USD');

    $quote = json_decode((string) (new QuoteTransfer($this->user))->handle(new ToolRequest(['amount' => 2500, 'mode' => 'target', 'country' => 'ZM'])), true);

    expect($quote['sender_pays'])->toBe(['amount' => '92.30', 'currency' => 'USD'])
        ->and($quote)->not->toHaveKey('fx_quote_id')
        ->and(CrossBorderTransfer::count())->toBe(0);
});

test('an approved start creates the transfer for this conversation and returns the paypal link', function () {
    $result = json_decode((string) (new StartTransfer($this->user, 'conv-1'))->handle(new ToolRequest([
        'amount' => 2500, 'mode' => 'target', 'country' => 'ZM', 'account_number' => '0977123456', 'recipient_name' => 'Mum',
    ])), true);

    $transfer = CrossBorderTransfer::sole();

    expect($result['checkout_url'])->toStartWith('https://www.sandbox.paypal.com/checkoutnow?token=ORDER-')
        ->and($result['reference'])->toBe($transfer->reference)
        ->and($transfer->origin)->toBe('agent')
        ->and($transfer->conversation_id)->toBe('conv-1');
});

test('spending limits are enforced in code, whatever the agent asks for', function () {
    config(['moneyunify_paypal.limits.per_transaction_max_usd' => 50, 'moneyunify_paypal.limits.daily_max_usd' => 120]);
    $start = fn (string $amount) => json_decode((string) (new StartTransfer($this->user))->handle(new ToolRequest([
        'amount' => $amount, 'mode' => 'source', 'country' => 'ZM', 'account_number' => '0977123456',
    ])), true);

    expect($start('60')['error'])->toBe('This transfer is about USD 60.00, over the USD 50 limit per transfer.');

    $start('45');
    $start('45');

    expect($start('45')['error'])->toBe('This transfer would pass the USD 120 daily limit (USD 30.00 left today).')
        ->and(CrossBorderTransfer::count())->toBe(2);
});

test('the status tool only sees the account\'s own transfers', function () {
    (new StartTransfer($this->user))->handle(new ToolRequest(['amount' => 100, 'mode' => 'source', 'country' => 'ZM', 'account_number' => '0977123456']));
    $reference = CrossBorderTransfer::sole()->reference;

    $own = json_decode((string) (new GetTransferStatus($this->user))->handle(new ToolRequest(['reference' => $reference])), true);
    $other = json_decode((string) (new GetTransferStatus(User::factory()->create()))->handle(new ToolRequest(['reference' => $reference])), true);

    expect($own['reference'])->toBe($reference)
        ->and($own['fx'])->not->toHaveKey('quote_id')
        ->and($other)->toBe(['error' => 'No such transfer on this account.']);
});

test('the assistant replies and surfaces transfers waiting for approval', function () {
    SendMoneyAgent::fake([
        AgentResponse::fakeWithPendingApprovals([
            new PendingApproval('call-1', 'StartTransfer', ['amount' => 2500, 'country' => 'ZM'], 'Send money to 0977123456 in Zambia: the recipient gets 2500 ZMW.'),
        ]),
    ]);

    $this->actingAs($this->user)
        ->postJson(route('send.assistant.message'), ['message' => 'Send K2,500 to mum on 0977123456'])
        ->assertOk()
        ->assertJsonPath('data.pending.0.id', 'call-1')
        ->assertJsonPath('data.pending.0.reason', 'Send money to 0977123456 in Zambia: the recipient gets 2500 ZMW.');

    SendMoneyAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'K2,500'));
});

test('a decision continues only the person\'s own conversation', function () {
    SendMoneyAgent::fake(['Done. The sender can pay at the PayPal link.']);
    $conversation = agentConversation($this->user);

    $stranger = User::factory()->create();
    $this->actingAs($stranger)
        ->postJson(route('send.assistant.decide'), ['conversation_id' => $conversation, 'decisions' => ['call-1' => 'approve']])
        ->assertStatus(404);

    $this->actingAs($this->user)
        ->postJson(route('send.assistant.decide'), ['conversation_id' => $conversation, 'decisions' => ['call-1' => 'approve']])
        ->assertOk()
        ->assertJsonPath('data.reply', 'Done. The sender can pay at the PayPal link.');

    $this->actingAs($this->user)
        ->postJson(route('send.assistant.decide'), ['conversation_id' => $conversation, 'decisions' => ['call-1' => 'maybe']])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'The selected decisions.call-1 is invalid.');
});

test('the assistant needs an ai provider', function () {
    config(['ai.providers.anthropic.key' => null]);
    SendMoneyAgent::fake();

    $this->actingAs($this->user)
        ->postJson(route('send.assistant.message'), ['message' => 'hi'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Add an AI provider on the AI & Agents page to use the assistant.');

    SendMoneyAgent::assertNeverPrompted();
});

// Grounding and guardrails

test('the agent can only send money: no refund or other money-moving tools', function () {
    $names = collect((new SendMoneyAgent($this->user))->tools())->map(fn ($tool) => class_basename($tool))->all();

    expect($names)->toBe(SendMoneyAgent::TOOLS)
        ->and(collect($names)->filter(fn ($name) => preg_match('/refund|reverse|cancel|payout/i', $name)))->toBeEmpty();
});

test('quotes and transfers must match the amount and number the person gave', function () {
    $intent = TransferIntent::fromMessages(['sent 3 kwacha to 0971943638', 'try again']);

    $wrong = json_decode((string) (new QuoteTransfer($this->user, $intent))->handle(new ToolRequest(['amount' => 20, 'mode' => 'source', 'country' => 'ZM', 'source_currency' => 'USD'])), true);
    expect($wrong['error'])->toBe('The user asked for 3 ZMW, not 20. Quote exactly what they asked for.');

    $right = json_decode((string) (new QuoteTransfer($this->user, $intent))->handle(new ToolRequest(['amount' => 3, 'mode' => 'target', 'country' => 'ZM'])), true);
    expect($right['recipient_gets'])->toBe(['amount' => '3.00', 'currency' => 'ZMW']);

    $start = new StartTransfer($this->user, null, $intent);
    $mismatched = ['amount' => 20, 'mode' => 'source', 'country' => 'ZM', 'source_currency' => 'USD', 'account_number' => '260971943638'];

    expect($start->shouldRequestApproval(new ToolRequest($mismatched)))->toBeNull()
        ->and(json_decode((string) $start->handle(new ToolRequest($mismatched)), true)['error'])->toContain('asked for 3 ZMW')
        ->and(CrossBorderTransfer::count())->toBe(0);

    $approval = $start->shouldRequestApproval(new ToolRequest(['amount' => 3, 'mode' => 'target', 'country' => 'ZM', 'account_number' => '0971943638']));
    expect($approval->reason)->toBe('Send ZMW 3.00 to 0971943638 in Zambia. The sender pays USD 0.12 with PayPal.');
});

test('a message whose turn failed is carried into the next turn', function () {
    $calls = 0;
    SendMoneyAgent::fake(function () use (&$calls) {
        return ++$calls === 1 ? throw new RuntimeException('Provider overloaded') : 'Got it.';
    });
    $this->actingAs($this->user);

    $this->postJson(route('send.assistant.message'), ['message' => 'sent 3 kwacha to 0971943638'])
        ->assertStatus(502)
        ->assertJsonStructure(['data' => ['conversation_id']]);
    $this->postJson(route('send.assistant.message'), ['message' => 'try again'])->assertOk();

    SendMoneyAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'sent 3 kwacha to 0971943638') && str_contains($prompt->prompt, 'try again'));

    // Once answered, it's not repeated.
    $this->postJson(route('send.assistant.message'), ['message' => 'thanks'])->assertOk();
    SendMoneyAgent::assertPrompted(fn ($prompt) => $prompt->prompt === 'thanks');
});

test('recent conversations show who sent what, and only to their owner', function () {
    $conversation = agentConversation($this->user);
    DB::table('agent_conversation_messages')->insert([
        ['id' => (string) Str::uuid(), 'conversation_id' => $conversation, 'participant_type' => $this->user->getMorphClass(), 'participant_id' => $this->user->id, 'agent' => SendMoneyAgent::class, 'role' => 'user', 'content' => 'Send K2,500 to 0977123456', 'attachments' => '[]', 'steps' => '[]', 'usage' => '{}', 'meta' => '{}', 'status' => 'completed', 'created_at' => now()->subMinute(), 'updated_at' => now()->subMinute()],
        ['id' => (string) Str::uuid(), 'conversation_id' => $conversation, 'participant_type' => $this->user->getMorphClass(), 'participant_id' => $this->user->id, 'agent' => SendMoneyAgent::class, 'role' => 'assistant', 'content' => 'That is USD 92.30.', 'attachments' => '[]', 'steps' => '[]', 'usage' => '{}', 'meta' => '{}', 'status' => 'completed', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $this->actingAs($this->user)->get(route('send.index'))
        ->assertInertia(fn ($page) => $page
            ->where('conversations.data.0.id', $conversation)
            ->where('conversations.data.0.title', 'Send money')
            ->where('conversations.data.0.first_message', 'Send K2,500 to 0977123456')
            ->where('conversations.data.0.by.email', $this->user->email)
            ->where('conversations.data.0.messages', 2)
            ->where('conversations.per_page', 10));

    $this->getJson(route('send.assistant.conversation', $conversation))
        ->assertOk()
        ->assertJsonPath('data.messages.0.text', 'Send K2,500 to 0977123456')
        ->assertJsonPath('data.messages.1.role', 'assistant')
        ->assertJsonPath('data.pending', []);

    $this->actingAs(User::factory()->create())->getJson(route('send.assistant.conversation', $conversation))->assertNotFound();
});

test('a transfer started after approval comes back with its pay link, also when the conversation is reopened', function () {
    $conversation = agentConversation($this->user);

    // The approved tool runs inside the resumed turn, so the reply carries no tool result.
    SendMoneyAgent::fake(function () {
        (new StartTransfer($this->user))->handle(new ToolRequest([
            'amount' => 3, 'mode' => 'target', 'country' => 'ZM', 'account_number' => '0971943638',
        ]));

        return 'Great! To finish, please pay at this PayPal link.';
    });

    $response = $this->actingAs($this->user)
        ->postJson(route('send.assistant.decide'), ['conversation_id' => $conversation, 'decisions' => ['call-1' => 'approve']])
        ->assertOk();

    $transfer = CrossBorderTransfer::sole();

    expect($response->json('data.transfer.reference'))->toBe($transfer->reference)
        ->and($response->json('data.transfer.checkout_url'))->toStartWith('https://www.sandbox.paypal.com/checkoutnow?token=ORDER-')
        ->and($transfer->refresh()->conversation_id)->toBe($conversation);

    $this->getJson(route('send.assistant.conversation', $conversation))
        ->assertJsonPath('data.transfers.0.reference', $transfer->reference)
        ->assertJsonPath('data.transfers.0.checkout_url', $response->json('data.transfer.checkout_url'));
});

test('recent conversations are listed ten at a time', function () {
    foreach (range(1, 12) as $minutes) {
        $id = agentConversation($this->user);
        DB::table('agent_conversations')->where('id', $id)->update(['title' => "Conversation {$minutes}", 'updated_at' => now()->subMinutes($minutes)]);
    }

    $this->actingAs($this->user)->get(route('send.index'))
        ->assertInertia(fn ($page) => $page
            ->has('conversations.data', 10)
            ->where('conversations.data.0.title', 'Conversation 1')
            ->where('conversations.total', 12)
            ->where('conversations.last_page', 2));

    $this->get(route('send.index', ['conversations_page' => 2]))
        ->assertInertia(fn ($page) => $page
            ->has('conversations.data', 2)
            ->where('conversations.data.1.title', 'Conversation 12'));
});
