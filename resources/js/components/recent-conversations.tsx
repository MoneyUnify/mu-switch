import { Button } from '@radix-ui/themes';
import { Loader2, MessageSquare, Sparkles } from 'lucide-react';
import { useState } from 'react';
import SendMoneyAssistantController from '@/actions/App/Http/Controllers/SendMoneyAssistantController';
import { AssistantMessageContent } from '@/components/send-money-assistant';
import type { ResumedConversation } from '@/components/send-money-assistant';
import { TableFooter } from '@/components/table-footer';
import type { PageMeta } from '@/components/table-footer';
import { Card } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { cn } from '@/lib/utils';

export interface ConversationSummary {
    id: string;
    title: string;
    first_message: string | null;
    by: { name: string; email: string };
    messages: number;
    started_at: string | null;
    updated_at: string | null;
    transfers: Array<{
        reference: string;
        status: string;
        status_label: string;
        recipient_gets: string;
    }>;
}

export type ConversationPage = PageMeta & { data: ConversationSummary[] };

type Transcript = ResumedConversation & {
    transfers: NonNullable<ResumedConversation['transfers']>;
};

function when(iso: string | null): string {
    if (!iso) {
        return '';
    }

    const date = new Date(iso);
    const today = new Date().toDateString() === date.toDateString();

    return today
        ? date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
        : date.toLocaleDateString([], {
              day: 'numeric',
              month: 'short',
              hour: '2-digit',
              minute: '2-digit',
          });
}

const tone: Record<string, string> = {
    delivered:
        'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300',
    refunded:
        'bg-violet-50 text-violet-700 dark:bg-violet-950/40 dark:text-violet-300',
    payment_failed:
        'bg-neutral-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-400',
};

/**
 * The account's recent assistant conversations: who had them, when, and the
 * transfers they started, ten at a time. Each one can be read again or
 * carried on.
 */
export function RecentConversations({
    page,
    onContinue,
}: {
    page: ConversationPage;
    onContinue: (conversation: ResumedConversation) => void;
}) {
    const conversations = page.data;

    const [open, setOpen] = useState<ConversationSummary | null>(null);
    const [transcript, setTranscript] = useState<Transcript | null>(null);
    const [loading, setLoading] = useState(false);

    const view = async (conversation: ConversationSummary) => {
        setOpen(conversation);
        setTranscript(null);
        setLoading(true);

        try {
            const response = await fetch(
                SendMoneyAssistantController.show.url(conversation.id),
                { headers: { Accept: 'application/json' } },
            );
            const json = await response.json();

            if (response.ok) {
                setTranscript({
                    id: json.data.conversation_id,
                    messages: json.data.messages,
                    pending: json.data.pending,
                    transfers: json.data.transfers,
                });
            }
        } finally {
            setLoading(false);
        }
    };

    return (
        <Card
            className="gap-0 overflow-hidden border border-sidebar-border/70 p-0 dark:border-sidebar-border"
            data-test="recent-conversations"
        >
            <div className="card-header-raised px-5 pt-5 pb-4">
                <h2 className="text-sm font-semibold">Recent conversations</h2>
                <p className="mt-0.5 text-xs text-neutral-500">
                    Who used the assistant to send money, and what it started.
                </p>
            </div>

            <div className="px-5 pb-5">
                {conversations.length === 0 ? (
                    <p className="py-8 text-center text-xs text-neutral-500">
                        No conversations yet.
                    </p>
                ) : (
                    <ul className="mt-1 divide-y divide-sidebar-border/50 dark:divide-sidebar-border/70">
                        {conversations.map((conversation) => (
                            <li key={conversation.id}>
                                <button
                                    type="button"
                                    onClick={() => view(conversation)}
                                    className="flex w-full cursor-pointer items-start gap-3 py-2.5 text-left hover:bg-neutral-50/70 dark:hover:bg-neutral-900/40"
                                >
                                    <span className="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-neutral-100 text-neutral-500 dark:bg-neutral-800">
                                        <MessageSquare className="h-3.5 w-3.5" />
                                    </span>
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate text-sm font-medium">
                                            {conversation.title}
                                        </span>
                                        {conversation.first_message &&
                                            conversation.first_message !==
                                                conversation.title && (
                                                <span className="block truncate text-xs text-neutral-600 dark:text-neutral-400">
                                                    “
                                                    {conversation.first_message}
                                                    ”
                                                </span>
                                            )}
                                        <span className="block truncate text-xs text-neutral-500">
                                            {conversation.by.name} ·{' '}
                                            {conversation.messages} messages ·{' '}
                                            {when(conversation.updated_at)}
                                        </span>
                                        {conversation.transfers.length > 0 && (
                                            <span className="mt-1 flex flex-wrap gap-1">
                                                {conversation.transfers
                                                    .slice(0, 3)
                                                    .map((transfer) => (
                                                        <span
                                                            key={
                                                                transfer.reference
                                                            }
                                                            className={cn(
                                                                'rounded px-1.5 py-0.5 text-[10px] font-medium',
                                                                tone[
                                                                    transfer
                                                                        .status
                                                                ] ??
                                                                    'bg-blue-50 text-blue-700 dark:bg-blue-950/40 dark:text-blue-300',
                                                            )}
                                                        >
                                                            {
                                                                transfer.recipient_gets
                                                            }{' '}
                                                            ·{' '}
                                                            {
                                                                transfer.status_label
                                                            }
                                                        </span>
                                                    ))}
                                            </span>
                                        )}
                                    </span>
                                </button>
                            </li>
                        ))}
                    </ul>
                )}

                <TableFooter
                    page={page}
                    only={['conversations']}
                    perPageKey="conversationsPerPage"
                    pageName="conversations_page"
                    perPageOptions={[page.per_page]}
                />
            </div>

            <Dialog
                open={open !== null}
                onOpenChange={(isOpen) => !isOpen && setOpen(null)}
            >
                <DialogContent className="flex max-h-[85vh] flex-col sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle className="truncate pr-6">
                            {open?.title}
                        </DialogTitle>
                        <DialogDescription>
                            {open?.by.name} ({open?.by.email}) · started{' '}
                            {when(open?.started_at ?? null)}
                        </DialogDescription>
                    </DialogHeader>

                    <div className="min-h-0 flex-1 space-y-2 overflow-y-auto pr-1">
                        {loading && (
                            <p className="flex items-center gap-2 text-xs text-neutral-500">
                                <Loader2 className="h-3.5 w-3.5 animate-spin" />
                                Loading…
                            </p>
                        )}
                        {transcript?.messages.map((message) => (
                            <div
                                key={message.id}
                                className={cn(
                                    'flex',
                                    message.role === 'user'
                                        ? 'justify-end'
                                        : 'justify-start',
                                )}
                            >
                                {message.role === 'user' ? (
                                    <p className="max-w-[85%] rounded-2xl rounded-tr-xs bg-neutral-900 px-3 py-2 text-sm whitespace-pre-line text-white dark:bg-neutral-100 dark:text-neutral-900">
                                        {message.text}
                                    </p>
                                ) : (
                                    <div className="max-w-[90%] rounded-2xl rounded-tl-xs border border-neutral-200/60 bg-neutral-100/90 px-3.5 py-2.5 text-sm text-neutral-800 dark:border-neutral-800/80 dark:bg-neutral-800/80 dark:text-neutral-100">
                                        <AssistantMessageContent
                                            text={message.text}
                                        />
                                    </div>
                                )}
                            </div>
                        ))}
                        {transcript && transcript.transfers.length > 0 && (
                            <div className="rounded-lg border border-sidebar-border/70 p-3 text-xs dark:border-sidebar-border">
                                <p className="font-medium">
                                    Transfers started here
                                </p>
                                <ul className="mt-1 space-y-0.5 text-neutral-600 dark:text-neutral-400">
                                    {transcript.transfers.map((transfer) => (
                                        <li key={transfer.reference}>
                                            {transfer.recipient_gets.currency}{' '}
                                            {transfer.recipient_gets.amount} (
                                            {transfer.sender_pays.currency}{' '}
                                            {transfer.sender_pays.amount}) ·{' '}
                                            {transfer.status_label}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}
                    </div>

                    {transcript && (
                        <Button
                            onClick={() => {
                                onContinue(transcript);
                                setOpen(null);
                            }}
                            className="cursor-pointer gap-1.5"
                        >
                            <Sparkles className="h-3.5 w-3.5" /> Continue this
                            conversation
                        </Button>
                    )}
                </DialogContent>
            </Dialog>
        </Card>
    );
}
