<?php

namespace App\Http\Controllers;

use App\Ai\Agents\SendMoneyAgent;
use App\ApiResponse;
use App\Models\CrossBorderTransfer;
use App\Models\User;
use App\Support\Ai\AiProviderChain;
use App\Support\CrossBorder\CrossBorderTransferPresenter;
use App\Support\CrossBorder\TransferIntent;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\ResolvesPendingApprovals;
use Laravel\Ai\Contracts\VerifiesConversationOwnership;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\ToolResult;
use Throwable;

/**
 * The Send Money assistant: chat with the agent, and approve or reject the
 * transfers it wants to start.
 */
class SendMoneyAssistantController extends Controller
{
    /**
     * Prefixes a turn that carries over a message the assistant couldn't answer.
     */
    private const CARRIED_OVER = 'My earlier message was not answered because of an error:';

    public function message(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message' => 'required|string|max:2000',
            'conversation_id' => 'nullable|string|max:36',
        ]);

        return $this->run($request->user(), $validated['conversation_id'] ?? null, $validated['message']);
    }

    /**
     * Approve or reject the transfers the agent is waiting on.
     */
    public function decide(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'conversation_id' => 'required|string|max:36',
            'decisions' => 'required|array|min:1',
            'decisions.*' => 'required|in:approve,reject',
        ]);

        $decisions = Decisions::from(collect($validated['decisions'])
            ->map(fn (string $choice): Decision => $choice === 'approve' ? Decision::approve() : Decision::reject('The person declined this transfer.'))
            ->all());

        return $this->run($request->user(), $validated['conversation_id'], $decisions);
    }

    private function run(User $user, ?string $conversationId, string|Decisions $input): JsonResponse
    {
        if (! AiProviderChain::for($user)->canRun()) {
            return ApiResponse::error(__('Add an AI provider on the AI & Agents page to use the assistant.'), 422);
        }

        if ($conversationId !== null && ! $this->owns($user, $conversationId)) {
            return ApiResponse::error(__('Conversation not found.'), 404);
        }

        // A message whose turn failed (e.g. the AI provider was busy) is never
        // lost: it's carried into the next turn, so "try again" keeps its meaning.
        $unanswered = Cache::get($this->unansweredKey($user), []);
        $prompt = is_string($input) && $unanswered !== []
            ? self::CARRIED_OVER.' "'.implode('" "', $unanswered)."\"\n\n".$input
            : $input;

        $intent = TransferIntent::fromMessages([
            ...$this->userMessages($conversationId),
            ...$unanswered,
            ...(is_string($input) ? [$input] : []),
        ]);

        $agent = (new SendMoneyAgent($user, $intent))->usingCredentialsOf($user)->continueOrStart($conversationId, $user);

        $startedAt = now()->subSecond();

        try {
            $response = $agent->prompt($prompt);
        } catch (Throwable $e) {
            report($e);

            if (is_string($input)) {
                Cache::put($this->unansweredKey($user), array_slice([...$unanswered, $input], -3), now()->addMinutes(30));
            }

            // Stay in the same conversation, even if this turn started it.
            return ApiResponse::error(__('The assistant could not respond: :reason', ['reason' => Str::limit($e->getMessage(), 160)]), 502, [
                'conversation_id' => $conversationId ?? $this->conversationStartedSince($user, $startedAt),
            ]);
        }

        Cache::forget($this->unansweredKey($user));

        $conversationId = $response->conversationId ?? $conversationId;
        $started = $this->startedTransfer($response) ?? $this->startedSince($user, $conversationId, $startedAt);

        // Link a transfer started in this turn to its conversation, for audit.
        if ($started && $conversationId) {
            CrossBorderTransfer::query()->where('user_id', $user->id)->where('reference', $started['reference'])
                ->whereNull('conversation_id')->update(['conversation_id' => $conversationId]);
        }

        $transfer = $started ? CrossBorderTransfer::query()->where('user_id', $user->id)->where('reference', $started['reference'])->first() : null;

        return ApiResponse::success('Assistant replied', [
            'conversation_id' => $conversationId,
            'reply' => (string) $response->text,
            'pending' => $response->pendingApprovals->map(fn (PendingApproval $approval): array => [
                'id' => $approval->id,
                'tool' => $approval->tool,
                'reason' => $approval->reason,
                'arguments' => $approval->arguments,
            ])->values()->all(),
            'transfer' => $transfer ? [
                ...CrossBorderTransferPresenter::toArray($transfer),
                'checkout_url' => ($started['checkout_url'] ?? null) ?: CrossBorderTransferPresenter::checkoutUrl($transfer),
            ] : null,
        ]);
    }

    /**
     * The transfer StartTransfer created in this turn, if it ran.
     *
     * @return array{reference: string, checkout_url: ?string}|null
     */
    private function startedTransfer(AgentResponse $response): ?array
    {
        $result = collect($response->toolResults)
            ->first(fn (ToolResult $result): bool => $result->name === 'StartTransfer' && $result->successful());

        $data = $result ? json_decode((string) $result->result, true) : null;

        return is_array($data) && isset($data['reference']) ? ['reference' => $data['reference'], 'checkout_url' => $data['checkout_url'] ?? null] : null;
    }

    /**
     * The account's recent assistant conversations: who had them, when, and
     * the transfers they started.
     */
    public function conversations(Request $request): JsonResponse
    {
        return ApiResponse::success('Conversations', ['conversations' => self::recentConversations($request->user())]);
    }

    /**
     * One conversation's messages, to read it again or carry on with it.
     */
    public function show(Request $request, string $conversation): JsonResponse
    {
        abort_unless($this->owns($request->user(), $conversation), 404);

        $messages = DB::table('agent_conversation_messages')
            ->where('conversation_id', $conversation)
            ->whereIn('role', ['user', 'assistant'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'role', 'content', 'created_at'])
            ->filter(fn ($message): bool => trim((string) $message->content) !== '')
            ->map(fn ($message): array => [
                'id' => (string) $message->id,
                'role' => $message->role,
                'text' => (string) $message->content,
                'at' => $message->created_at ? Carbon::parse($message->created_at)->toIso8601String() : null,
            ])
            ->values();

        $store = resolve(ConversationStore::class);

        return ApiResponse::success('Conversation', [
            'conversation_id' => $conversation,
            'messages' => $messages,
            // A transfer still waiting for approval, so its card comes back too.
            'pending' => $store instanceof ResolvesPendingApprovals
                ? collect($store->pendingApprovalsFor($conversation))->map(fn (PendingApproval $approval): array => [
                    'id' => $approval->id,
                    'tool' => $approval->tool,
                    'reason' => $approval->reason,
                    'arguments' => $approval->arguments,
                ])->values()->all()
                : [],
            'transfers' => CrossBorderTransfer::query()->where('user_id', $request->user()->id)->where('conversation_id', $conversation)->latest('id')->get()
                ->map(fn (CrossBorderTransfer $transfer): array => [
                    ...CrossBorderTransferPresenter::toArray($transfer),
                    'checkout_url' => CrossBorderTransferPresenter::checkoutUrl($transfer),
                ])->all(),
        ]);
    }

    /** How many conversations the Send Money page lists at a time. */
    public const CONVERSATIONS_PER_PAGE = 10;

    /** The query parameter that pages the conversation list. */
    public const CONVERSATIONS_PAGE = 'conversations_page';

    /**
     * The account's conversations, most recent first, a page at a time.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public static function recentConversations(User $user, int $perPage = self::CONVERSATIONS_PER_PAGE): LengthAwarePaginator
    {
        $page = DB::table('agent_conversations')
            ->where('participant_type', Conversation::participantType($user))
            ->where('participant_id', Conversation::participantKey($user))
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['id', 'title', 'created_at', 'updated_at'], self::CONVERSATIONS_PAGE)
            ->withQueryString();
        $conversations = collect($page->items());

        $ids = $conversations->pluck('id');
        $counts = DB::table('agent_conversation_messages')->whereIn('conversation_id', $ids)->whereIn('role', ['user', 'assistant'])
            ->selectRaw('conversation_id, count(*) as messages')->groupBy('conversation_id')->pluck('messages', 'conversation_id');
        $first = DB::table('agent_conversation_messages')->whereIn('conversation_id', $ids)->where('role', 'user')
            ->orderBy('created_at')->get(['conversation_id', 'content'])->unique('conversation_id')
            ->mapWithKeys(fn ($message): array => [$message->conversation_id => self::spoken((string) $message->content)]);
        $transfers = CrossBorderTransfer::query()->where('user_id', $user->id)->whereIn('conversation_id', $ids)->latest('id')->get()->groupBy('conversation_id');

        return $page->setCollection($conversations->map(fn ($conversation): array => [
            'id' => $conversation->id,
            // The AI names each conversation from its first request.
            'title' => Str::limit(trim((string) $conversation->title) ?: (string) ($first[$conversation->id] ?? 'Conversation'), 80),
            'first_message' => isset($first[$conversation->id]) ? Str::limit((string) $first[$conversation->id], 120) : null,
            'by' => ['name' => $user->name, 'email' => $user->email],
            'messages' => (int) ($counts[$conversation->id] ?? 0),
            'started_at' => $conversation->created_at ? Carbon::parse($conversation->created_at)->toIso8601String() : null,
            'updated_at' => $conversation->updated_at ? Carbon::parse($conversation->updated_at)->toIso8601String() : null,
            'transfers' => ($transfers[$conversation->id] ?? collect())->map(fn (CrossBorderTransfer $transfer): array => [
                'reference' => $transfer->reference,
                'status' => $transfer->status->value,
                'status_label' => $transfer->status->label(),
                'recipient_gets' => (string) $transfer->targetAmount().' '.$transfer->target_currency,
            ])->values()->all(),
        ])->values());
    }

    /**
     * What the person typed, without the note that carries over a message
     * from a failed turn.
     */
    private static function spoken(string $content): string
    {
        return preg_match('/^'.preg_quote(self::CARRIED_OVER, '/').' "(.*)"\n\n(.*)$/s', $content, $match)
            ? trim($match[1].' — '.$match[2])
            : $content;
    }

    /**
     * The person's own messages in a conversation, oldest first.
     *
     * @return list<string>
     */
    private function userMessages(?string $conversationId): array
    {
        if ($conversationId === null) {
            return [];
        }

        return DB::table('agent_conversation_messages')
            ->where('conversation_id', $conversationId)
            ->where('role', 'user')
            ->orderBy('created_at')
            ->orderBy('id')
            ->pluck('content')
            ->map(fn ($content): string => (string) $content)
            ->all();
    }

    /**
     * The conversation a failed first turn created, if it got that far.
     */
    private function conversationStartedSince(User $user, CarbonInterface $since): ?string
    {
        return DB::table('agent_conversations')
            ->where('participant_type', Conversation::participantType($user))
            ->where('participant_id', Conversation::participantKey($user))
            ->where('created_at', '>=', $since)
            ->orderByDesc('created_at')
            ->value('id');
    }

    private function unansweredKey(User $user): string
    {
        return "send-assistant:unanswered:{$user->id}";
    }

    /**
     * A transfer the agent started during this turn, found in the database
     * when the response doesn't carry the tool's result (e.g. after an
     * approval, the tool runs inside the resumed turn).
     *
     * @return array{reference: string, checkout_url: ?string}|null
     */
    private function startedSince(User $user, ?string $conversationId, CarbonInterface $since): ?array
    {
        $transfer = CrossBorderTransfer::query()
            ->where('user_id', $user->id)
            ->where('origin', 'agent')
            ->where('created_at', '>=', $since)
            ->where(fn ($query) => $query->whereNull('conversation_id')->when($conversationId, fn ($q) => $q->orWhere('conversation_id', $conversationId)))
            ->with('fundingTransaction')
            ->latest('id')
            ->first();

        return $transfer ? ['reference' => $transfer->reference, 'checkout_url' => CrossBorderTransferPresenter::checkoutUrl($transfer)] : null;
    }

    private function owns(User $user, string $conversationId): bool
    {
        $store = resolve(ConversationStore::class);

        return $store instanceof VerifiesConversationOwnership
            && $store->conversationBelongsTo($conversationId, Conversation::participantType($user), Conversation::participantKey($user));
    }
}
