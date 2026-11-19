import { Link, router } from '@inertiajs/react';
import { Button } from '@radix-ui/themes';
import {
    ArrowDownLeft,
    ArrowUpRight,
    Bot,
    Check,
    Copy,
    CreditCard,
    Loader2,
    Send,
    ShieldCheck,
    Sparkles,
    X,
} from 'lucide-react';
import type { FormEvent } from 'react';
import { useEffect, useRef, useState } from 'react';
import SendMoneyAssistantController from '@/actions/App/Http/Controllers/SendMoneyAssistantController';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import ai from '@/routes/ai';

interface Pending {
    id: string;
    tool: string;
    reason: string | null;
    arguments: Record<string, unknown>;
}

interface StartedTransfer {
    reference: string;
    status_label: string;
    sender_pays: { amount: string; currency: string };
    recipient_gets: { amount: string; currency: string };
    checkout_url: string | null;
}

interface Message {
    id: string;
    role: 'user' | 'assistant';
    text: string;
    timestamp?: string;
    answered?: boolean;
}

interface ParsedQuote {
    senderPays: string;
    recipientGets: string;
    exchangeRate?: string | null;
    delivery?: string | null;
    account?: string | null;
    country?: string | null;
}

interface ParsedMessage {
    intro: string | null;
    quote: ParsedQuote | null;
    followUp: string | null;
    plainText: string;
    isConfirmation: boolean;
}

const EXAMPLES = [
    'Send K2,500 to my mum on 0977123456',
    'How much does $100 come to in Zambia?',
    'Where is my last transfer?',
];

function csrfToken(): string {
    return (
        (
            document.querySelector(
                'meta[name="csrf-token"]',
            ) as HTMLMetaElement | null
        )?.content ?? ''
    );
}

function nowTime(): string {
    return new Date().toLocaleTimeString([], {
        hour: '2-digit',
        minute: '2-digit',
    });
}

/**
 * Parses the raw assistant text to detect transfer quotes, key-values, and confirmation queries.
 */
function parseAssistantText(text: string): ParsedMessage {
    const lines = text.split(/\r?\n/);

    let senderPays: string | null = null;
    let recipientGets: string | null = null;
    let exchangeRate: string | null = null;
    let delivery: string | null = null;

    let quoteStart = -1;
    let quoteEnd = -1;

    for (let i = 0; i < lines.length; i++) {
        const line = lines[i];

        const spMatch = line.match(
            /(?:sender pays|you pay):\s*\*?\*?\s*([^\n\r*]+)/i,
        );

        if (spMatch) {
            senderPays = spMatch[1].replace(/[*_]/g, '').trim();

            if (quoteStart === -1) {
                quoteStart = i;
            }

            quoteEnd = i;
            continue;
        }

        const rgMatch = line.match(
            /(?:recipient gets|they receive|recipient receives):\s*\*?\*?\s*([^\n\r*]+)/i,
        );

        if (rgMatch) {
            recipientGets = rgMatch[1].replace(/[*_]/g, '').trim();

            if (quoteStart === -1) {
                quoteStart = i;
            }

            quoteEnd = i;
            continue;
        }

        const erMatch = line.match(
            /(?:exchange rate|rate):\s*\*?\*?\s*([^\n\r*]+)/i,
        );

        if (erMatch) {
            exchangeRate = erMatch[1].replace(/[*_]/g, '').trim();

            if (quoteStart === -1) {
                quoteStart = i;
            }

            quoteEnd = i;
            continue;
        }

        const delMatch = line.match(
            /(?:delivery|delivery method|delivered via):\s*\*?\*?\s*([^\n\r*]+)/i,
        );

        if (delMatch) {
            delivery = delMatch[1].replace(/[*_]/g, '').trim();

            if (quoteStart === -1) {
                quoteStart = i;
            }

            quoteEnd = i;
            continue;
        }
    }

    if (!senderPays || !recipientGets || quoteStart === -1) {
        const isConfirm =
            /(?:shall i|would you like|do you want|should i|please confirm|ready to send|go ahead|proceed|yes or no)/i.test(
                text,
            );

        return {
            intro: null,
            quote: null,
            followUp: null,
            plainText: text,
            isConfirmation: isConfirm,
        };
    }

    const introLines = lines
        .slice(0, quoteStart)
        .filter((l) => l.trim() !== '');
    const followUpLines = lines
        .slice(quoteEnd + 1)
        .filter((l) => l.trim() !== '');

    const intro = introLines.join('\n').trim() || null;
    const followUp = followUpLines.join('\n').trim() || null;

    let account: string | null = null;
    let country: string | null = null;

    if (intro) {
        const phoneMatch = intro.match(/(?:to\s+)?(\+?\d{8,15})/i);

        if (phoneMatch) {
            account = phoneMatch[1];
        }

        const countryMatch = intro.match(/(?:in\s+)([A-Za-z\s]+)(?:[:.,]|$)/i);

        if (countryMatch) {
            country = countryMatch[1].trim();
        }
    }

    const isConfirmation =
        /(?:shall i|would you like|do you want|should i|confirm|go ahead|proceed|yes or no)/i.test(
            followUp || text,
        );

    return {
        intro,
        quote: {
            senderPays,
            recipientGets,
            exchangeRate,
            delivery,
            account,
            country,
        },
        followUp,
        plainText: text,
        isConfirmation,
    };
}

/**
 * Render formatted markdown lines with bold tags and auto-link URLs opening in a new tab.
 */
/**
 * A link's readable text: a PayPal checkout reads as such, a bare URL
 * shows its host, and a written label is kept.
 */
function linkLabel(url: string, label?: string): string {
    if (url.includes('paypal.com') && url.includes('checkout')) {
        return 'PayPal checkout';
    }

    if (label && !/^https?:\/\//.test(label)) {
        return label;
    }

    try {
        return new URL(url).host;
    } catch {
        return url;
    }
}

function FormattedText({ text }: { text: string }) {
    const lines = text.split('\n');

    return (
        <div className="space-y-1">
            {lines.map((line, i) => {
                if (!line.trim()) {
                    return <div key={i} className="h-1.5" />;
                }

                // Strip leading markdown bullets if present for cleaner prose
                const cleanLine = line.replace(/^\s*[*-]\s+/, '');
                // Markdown links first, so "[text](url)" never shows its brackets.
                const regex =
                    /(\[[^\]]+\]\(https?:\/\/[^)\s]+\)|https?:\/\/[^\s<)\]]+|\*\*[^*]+\*\*)/g;
                const parts = cleanLine.split(regex);

                return (
                    <div key={i} className="leading-relaxed break-words">
                        {parts.map((part, j) => {
                            if (!part) {
                                return null;
                            }

                            const markdownLink = part.match(
                                /^\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)$/,
                            );

                            if (
                                markdownLink ||
                                part.startsWith('http://') ||
                                part.startsWith('https://')
                            ) {
                                const cleanUrl = markdownLink
                                    ? markdownLink[2]
                                    : part.replace(/[.,;)\]]+$/, '');
                                const trailing = markdownLink
                                    ? ''
                                    : part.slice(cleanUrl.length);
                                const isPaypal =
                                    cleanUrl.includes('paypal.com');

                                return (
                                    <span key={j} className="inline">
                                        <a
                                            href={cleanUrl}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className={cn(
                                                'inline-flex cursor-pointer items-center gap-1 font-medium break-all underline underline-offset-2 transition-colors',
                                                isPaypal
                                                    ? 'font-semibold text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-200'
                                                    : 'text-blue-600 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300',
                                            )}
                                            title="Open link in a new tab"
                                        >
                                            <span>
                                                {linkLabel(
                                                    cleanUrl,
                                                    markdownLink?.[1],
                                                )}
                                            </span>
                                            <ArrowUpRight className="inline h-3.5 w-3.5 shrink-0 stroke-[2.2]" />
                                        </a>
                                        {trailing}
                                    </span>
                                );
                            }

                            if (part.startsWith('**') && part.endsWith('**')) {
                                return (
                                    <strong
                                        key={j}
                                        className="font-semibold text-neutral-900 dark:text-neutral-50"
                                    >
                                        {part.slice(2, -2)}
                                    </strong>
                                );
                            }

                            return part;
                        })}
                    </div>
                );
            })}
        </div>
    );
}

function ApprovalNeededCard({
    approval,
    busy,
    onApprove,
    onReject,
}: {
    approval: Pending;
    busy: boolean;
    onApprove: () => void;
    onReject: () => void;
}) {
    return (
        <div
            className="my-3 overflow-hidden rounded-xl border border-sidebar-border/80 bg-white/95 shadow-xs dark:border-neutral-700/80 dark:bg-neutral-900/95"
            data-test="assistant-approval"
        >
            <div className="flex items-center justify-between border-b border-sidebar-border/50 bg-neutral-50/70 px-3.5 py-2.5 dark:border-neutral-800 dark:bg-neutral-800/40">
                <div className="flex items-center gap-2">
                    <span className="flex h-5 w-5 items-center justify-center rounded-full bg-amber-500/15 text-amber-600 dark:text-amber-400">
                        <ShieldCheck className="h-3.5 w-3.5" />
                    </span>
                    <span className="text-xs font-semibold text-neutral-800 dark:text-neutral-200">
                        Authorization Required
                    </span>
                </div>
                <span className="rounded-full bg-amber-500/10 px-2 py-0.5 text-[10px] font-medium text-amber-700 dark:text-amber-300">
                    Awaiting confirmation
                </span>
            </div>

            <div className="p-3.5">
                <p className="text-xs leading-relaxed text-neutral-700 dark:text-neutral-300">
                    {approval.reason ??
                        `The assistant requested authorization to execute ${approval.tool}.`}
                </p>

                <div className="mt-3.5 flex items-center gap-2">
                    <button
                        type="button"
                        onClick={onApprove}
                        disabled={busy}
                        className="inline-flex cursor-pointer items-center justify-center gap-1.5 rounded-lg bg-neutral-900 px-3.5 py-1.5 text-xs font-medium text-white shadow-xs transition hover:bg-neutral-800 active:scale-98 disabled:pointer-events-none disabled:opacity-50 dark:bg-neutral-100 dark:text-neutral-900 dark:hover:bg-neutral-200"
                    >
                        <Check className="h-3.5 w-3.5 stroke-[2.5]" />
                        Approve &amp; Send
                    </button>
                    <button
                        type="button"
                        onClick={onReject}
                        disabled={busy}
                        className="inline-flex cursor-pointer items-center justify-center gap-1.5 rounded-lg border border-sidebar-border bg-white px-3 py-1.5 text-xs font-medium text-neutral-600 shadow-2xs transition hover:bg-neutral-50 active:scale-98 disabled:pointer-events-none disabled:opacity-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300 dark:hover:bg-neutral-700"
                    >
                        <X className="h-3.5 w-3.5" />
                        Decline
                    </button>
                </div>
            </div>
        </div>
    );
}

function PaymentActionCard({ started }: { started: StartedTransfer }) {
    const [copied, setCopied] = useState(false);

    if (!started.checkout_url) {
        return null;
    }

    const copyLink = async () => {
        if (!started.checkout_url) {
            return;
        }

        try {
            await navigator.clipboard.writeText(started.checkout_url);
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        } catch {
            // fallback
        }
    };

    return (
        <div className="my-3 overflow-hidden rounded-2xl border border-sidebar-border/80 bg-white shadow-xs transition dark:border-neutral-800 dark:bg-neutral-900">
            {/* Header row */}
            <div className="flex items-center justify-between border-b border-sidebar-border/50 bg-neutral-50/70 px-4 py-2.5 dark:border-neutral-800 dark:bg-neutral-800/40">
                <div className="flex items-center gap-2">
                    <span className="flex h-5 w-5 items-center justify-center rounded-full bg-blue-500/15 text-blue-600 dark:text-blue-400">
                        <CreditCard className="h-3 w-3 stroke-[2.2]" />
                    </span>
                    <span className="text-xs font-semibold text-neutral-800 dark:text-neutral-200">
                        Transfer Initiated
                    </span>
                    {started.reference && (
                        <span className="rounded-md border border-neutral-200/80 bg-white px-1.5 py-0.5 font-mono text-[10px] text-neutral-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-400">
                            {started.reference}
                        </span>
                    )}
                </div>
                <span className="rounded-full bg-amber-500/10 px-2 py-0.5 text-[10px] font-medium text-amber-700 dark:text-amber-400">
                    Awaiting Payment
                </span>
            </div>

            {/* Financial Details */}
            <div className="p-4">
                <div className="flex items-baseline justify-between gap-3">
                    <div>
                        <div className="text-[10px] font-medium tracking-wider text-neutral-400 uppercase dark:text-neutral-500">
                            Recipient Receives
                        </div>
                        <div className="mt-0.5 text-2xl font-bold tracking-tight text-neutral-900 tabular-nums dark:text-white">
                            {started.recipient_gets.currency}{' '}
                            {Number(
                                started.recipient_gets.amount,
                            ).toLocaleString('en-US', {
                                minimumFractionDigits: 2,
                            })}
                        </div>
                        <div className="mt-0.5 text-[11px] text-neutral-500 dark:text-neutral-400">
                            Delivered to recipient via mobile money
                        </div>
                    </div>
                    <div className="text-right">
                        <div className="text-[10px] font-medium tracking-wider text-neutral-400 uppercase dark:text-neutral-500">
                            Sender Pays
                        </div>
                        <div className="mt-0.5 text-base font-bold text-neutral-900 tabular-nums dark:text-neutral-100">
                            {started.sender_pays.currency}{' '}
                            {started.sender_pays.amount}
                        </div>
                        <div className="mt-0.5 text-[11px] font-medium text-neutral-400 dark:text-neutral-500">
                            via PayPal
                        </div>
                    </div>
                </div>

                <div className="mt-3 flex items-center gap-1.5 text-[11px] text-neutral-500 dark:text-neutral-400">
                    <ShieldCheck className="h-3.5 w-3.5 shrink-0 text-emerald-600 dark:text-emerald-400" />
                    <span>
                        Protected by PayPal · Auto-refunded if delivery cannot
                        be completed
                    </span>
                </div>

                <div className="mt-4 flex flex-col gap-2 sm:flex-row sm:items-center">
                    <a
                        href={started.checkout_url}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="inline-flex flex-1 cursor-pointer items-center justify-center gap-2 rounded-xl bg-[#0070BA] px-4 py-2.5 text-xs font-semibold text-white shadow-xs transition hover:bg-[#003087] active:scale-98"
                    >
                        <span>Pay with PayPal</span>
                        <ArrowUpRight className="h-3.5 w-3.5" />
                    </a>

                    <button
                        type="button"
                        onClick={copyLink}
                        className="inline-flex cursor-pointer items-center justify-center gap-1.5 rounded-xl border border-sidebar-border bg-white px-3.5 py-2.5 text-xs font-medium text-neutral-700 shadow-2xs transition hover:bg-neutral-50 active:scale-98 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200 dark:hover:bg-neutral-700"
                        title="Copy payment link"
                    >
                        {copied ? (
                            <>
                                <Check className="h-3.5 w-3.5 text-emerald-600 dark:text-emerald-400" />
                                <span>Copied link</span>
                            </>
                        ) : (
                            <>
                                <Copy className="h-3.5 w-3.5 text-neutral-500" />
                                <span>Copy link</span>
                            </>
                        )}
                    </button>
                </div>

                <p className="mt-2 text-center text-[11px] text-neutral-400 dark:text-neutral-500">
                    Opens in a new tab. Return to this page to check transfer
                    status.
                </p>
            </div>
        </div>
    );
}

/**
 * Sub-card on the existing card: renders a WhatsApp/Fluent-style interactive transfer preview card.
 */
function TransferQuoteSubCard({ quote }: { quote: ParsedQuote }) {
    return (
        <div className="my-2.5 overflow-hidden rounded-xl border border-neutral-200/90 bg-white/95 shadow-xs dark:border-neutral-700/70 dark:bg-neutral-900/95">
            {/* Header: WhatsApp payment preview badge style */}
            <div className="flex items-center justify-between border-b border-neutral-100 bg-neutral-50/80 px-3.5 py-2 dark:border-neutral-800 dark:bg-neutral-800/60">
                <div className="flex items-center gap-1.5">
                    <span className="flex h-5 w-5 items-center justify-center rounded-full bg-emerald-500/15 text-emerald-600 dark:bg-emerald-500/20 dark:text-emerald-400">
                        <ArrowDownLeft className="h-3 w-3 stroke-[2.5]" />
                    </span>
                    <span className="text-[11px] font-semibold tracking-wide text-neutral-700 uppercase dark:text-neutral-200">
                        Transfer Summary
                    </span>
                </div>
                {quote.delivery && (
                    <span className="rounded-full bg-neutral-200/60 px-2 py-0.5 text-[10px] font-medium text-neutral-600 dark:bg-neutral-700/60 dark:text-neutral-300">
                        {quote.delivery}
                    </span>
                )}
            </div>

            {/* Recipient receives highlighted */}
            <div className="p-3.5">
                <div className="flex items-start justify-between gap-2">
                    <div>
                        <div className="text-[10px] font-medium tracking-wider text-neutral-400 uppercase dark:text-neutral-500">
                            Recipient Receives
                        </div>
                        <div className="mt-0.5 text-2xl font-bold tracking-tight text-neutral-900 tabular-nums dark:text-white">
                            {quote.recipientGets}
                        </div>
                    </div>
                    {(quote.account || quote.country) && (
                        <div className="flex flex-col items-end text-right">
                            {quote.account && (
                                <span className="rounded-md border border-neutral-200 bg-neutral-50 px-2 py-0.5 font-mono text-[11px] font-medium text-neutral-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300">
                                    {quote.account}
                                </span>
                            )}
                            {quote.country && (
                                <span className="mt-1 text-[11px] text-neutral-500 dark:text-neutral-400">
                                    {quote.country}
                                </span>
                            )}
                        </div>
                    )}
                </div>

                {/* Sender pays & rate breakdown */}
                <div className="mt-3 grid grid-cols-2 gap-3 border-t border-neutral-100 pt-2.5 text-xs dark:border-neutral-800">
                    <div>
                        <span className="text-[10px] tracking-wide text-neutral-400 uppercase">
                            Sender pays
                        </span>
                        <div className="font-semibold text-neutral-800 tabular-nums dark:text-neutral-200">
                            {quote.senderPays}
                        </div>
                    </div>
                    <div>
                        <span className="text-[10px] tracking-wide text-neutral-400 uppercase">
                            Exchange rate
                        </span>
                        <div className="font-semibold text-neutral-800 tabular-nums dark:text-neutral-200">
                            {quote.exchangeRate ?? 'Live rate'}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}

/**
 * Chat with the Send Money agent. It quotes and checks transfers on its own;
 * starting one shows an approval card, and nothing moves until it's approved
 * here and paid on PayPal.
 */
/**
 * An assistant reply, formatted the same way wherever it's shown: text with
 * emphasis and links, and quotes as a transfer summary card.
 */
export function AssistantMessageContent({ text }: { text: string }) {
    const parsed = parseAssistantText(text);

    if (!parsed.quote) {
        return <FormattedText text={parsed.plainText} />;
    }

    return (
        <div>
            {parsed.intro && <FormattedText text={parsed.intro} />}
            <TransferQuoteSubCard quote={parsed.quote} />
            {parsed.followUp && <FormattedText text={parsed.followUp} />}
        </div>
    );
}

/** Where this browser tab remembers the conversation in progress. */
const CONVERSATION_KEY = 'moneyunify.send.conversation';

export interface ResumedConversation {
    id: string;
    messages: Array<{
        id: string;
        role: 'user' | 'assistant';
        text: string;
        at: string | null;
    }>;
    pending?: Pending[];
    transfers?: Array<StartedTransfer & { status: string }>;
}

/**
 * The transfer still waiting for the sender to pay, so its Pay with PayPal
 * card comes back with the conversation.
 */
function awaitingPayment(
    transfers: ResumedConversation['transfers'],
): StartedTransfer | null {
    return (
        (transfers ?? []).find(
            (transfer) =>
                transfer.status === 'awaiting_payment' && transfer.checkout_url,
        ) ?? null
    );
}

/**
 * Stored messages as chat messages. Everything is answered except a final
 * assistant message, so its quick actions (e.g. "Yes, go ahead") come back.
 */
function fromStored(messages: ResumedConversation['messages']): Message[] {
    return messages.map((message, index) => ({
        id: message.id,
        role: message.role,
        text: message.text,
        answered: !(
            index === messages.length - 1 && message.role === 'assistant'
        ),
        timestamp: message.at
            ? new Date(message.at).toLocaleTimeString([], {
                  hour: '2-digit',
                  minute: '2-digit',
              })
            : undefined,
    }));
}

export function SendMoneyAssistant({
    aiReady,
    canSend,
    onlyReload,
    resume = null,
}: {
    aiReady: boolean;
    canSend: boolean;
    onlyReload: string[];
    /** A past conversation to carry on with (remount with a new key to switch). */
    resume?: ResumedConversation | null;
}) {
    const [messages, setMessages] = useState<Message[]>(() =>
        fromStored(resume?.messages ?? []),
    );
    const [conversationId, setConversationId] = useState<string | null>(
        resume?.id ?? null,
    );
    const [pending, setPending] = useState<Pending[]>(resume?.pending ?? []);
    const [started, setStarted] = useState<StartedTransfer | null>(() =>
        awaitingPayment(resume?.transfers),
    );
    const [draft, setDraft] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const endRef = useRef<HTMLDivElement>(null);

    // Remember the conversation in progress, so a reload or switching tabs
    // carries on with it instead of starting a new one.
    useEffect(() => {
        try {
            if (conversationId) {
                sessionStorage.setItem(CONVERSATION_KEY, conversationId);
            }
        } catch {
            // Storage may be unavailable; the conversation still works.
        }
    }, [conversationId]);

    useEffect(() => {
        if (resume) {
            return;
        }

        let saved: string | null = null;

        try {
            saved = sessionStorage.getItem(CONVERSATION_KEY);
        } catch {
            saved = null;
        }

        if (!saved) {
            return;
        }

        const controller = new AbortController();

        fetch(SendMoneyAssistantController.show.url(saved), {
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        })
            .then((response) => (response.ok ? response.json() : null))
            .then((json) => {
                if (!json?.data) {
                    sessionStorage.removeItem(CONVERSATION_KEY);

                    return;
                }

                setConversationId(json.data.conversation_id);
                setMessages(fromStored(json.data.messages));
                setPending(json.data.pending ?? []);
                setStarted(awaitingPayment(json.data.transfers));
            })
            .catch(() => undefined);

        return () => controller.abort();
    }, [resume]);

    const startNewConversation = () => {
        try {
            sessionStorage.removeItem(CONVERSATION_KEY);
        } catch {
            // Ignore storage errors.
        }

        setConversationId(null);
        setMessages([]);
        setPending([]);
        setStarted(null);
        setError(null);
    };

    useEffect(() => {
        endRef.current?.scrollIntoView({
            behavior: 'smooth',
            block: 'nearest',
        });
    }, [messages, pending, started, busy]);

    const call = async (url: string, body: Record<string, unknown>) => {
        setBusy(true);
        setError(null);

        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify(body),
            });
            const json = await response.json();

            if (!response.ok) {
                // Keep the conversation even when a turn fails, so "try again" has context.
                if (json.data?.conversation_id) {
                    setConversationId(json.data.conversation_id);
                }

                setError(json.message ?? 'The assistant could not respond.');

                return;
            }

            const data = json.data;
            setConversationId(data.conversation_id);
            setPending(data.pending ?? []);
            router.reload({ only: ['conversations'] });

            if (data.reply) {
                setMessages((current) => [
                    ...current,
                    {
                        id: `msg_${Date.now()}_${Math.random().toString(36).slice(2, 6)}`,
                        role: 'assistant',
                        text: data.reply,
                        timestamp: nowTime(),
                    },
                ]);
            }

            if (data.transfer) {
                setStarted(data.transfer);
                router.reload({ only: onlyReload });
            }
        } catch {
            setError('Could not reach the assistant.');
        } finally {
            setBusy(false);
        }
    };

    const send = (text: string) => {
        if (!text.trim() || busy) {
            return;
        }

        // Mark previously answered messages
        setMessages((current) =>
            current
                .map((m) => ({ ...m, answered: true }))
                .concat({
                    id: `msg_${Date.now()}_${Math.random().toString(36).slice(2, 6)}`,
                    role: 'user',
                    answered: false,
                    text,
                    timestamp: nowTime(),
                }),
        );
        setDraft('');
        void call(SendMoneyAssistantController.message.url(), {
            message: text,
            conversation_id: conversationId,
        });
    };

    const decide = (approval: Pending, choice: 'approve' | 'reject') => {
        setMessages((current) => [
            ...current.map((m) => ({ ...m, answered: true })),
            {
                id: `msg_${Date.now()}_${Math.random().toString(36).slice(2, 6)}`,
                role: 'user',
                text: choice === 'approve' ? 'Approved.' : 'Rejected.',
                timestamp: nowTime(),
            },
        ]);
        void call(SendMoneyAssistantController.decide.url(), {
            conversation_id: conversationId,
            decisions: { [approval.id]: choice },
        });
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        send(draft);
    };

    if (!canSend) {
        return (
            <p className="py-10 text-center text-sm text-neutral-500">
                Set up PayPal and a payout provider first (see above).
            </p>
        );
    }

    if (!aiReady) {
        return (
            <div className="flex flex-col items-center gap-2 py-10 text-center text-sm text-neutral-500">
                <Bot className="h-6 w-6 text-neutral-400" />
                Add an AI provider to send money by chatting.
                <Button asChild size="1" variant="soft">
                    <Link href={ai.index.url()}>Open AI &amp; Agents</Link>
                </Button>
            </div>
        );
    }

    return (
        <div className="flex flex-col" data-test="send-assistant">
            {(conversationId || messages.length > 0) && (
                <div className="mb-2 flex items-center justify-between gap-2 border-b border-sidebar-border/50 pb-2 text-[11px] text-neutral-500 dark:border-sidebar-border/70">
                    <span>Follow-ups stay in this conversation.</span>
                    <button
                        type="button"
                        onClick={startNewConversation}
                        disabled={busy}
                        className="cursor-pointer rounded-full border border-sidebar-border/70 px-2.5 py-0.5 font-medium text-neutral-600 hover:bg-neutral-50 disabled:opacity-50 dark:border-sidebar-border dark:text-neutral-300 dark:hover:bg-neutral-900"
                    >
                        New conversation
                    </button>
                </div>
            )}
            <div className="max-h-[28rem] min-h-[16rem] space-y-3.5 overflow-y-auto pr-1">
                {messages.length === 0 && (
                    <div className="space-y-2.5 pt-1">
                        <p className="text-xs text-neutral-500">
                            Tell the assistant who to pay and how much. It
                            quotes first, and every transfer waits for your
                            approval.
                        </p>
                        <div className="flex flex-wrap gap-1.5">
                            {EXAMPLES.map((example) => (
                                <button
                                    key={example}
                                    type="button"
                                    onClick={() => send(example)}
                                    className="cursor-pointer rounded-full border border-sidebar-border/70 bg-white/60 px-3 py-1.5 text-[11px] text-neutral-600 shadow-2xs transition hover:bg-neutral-100 hover:text-neutral-900 dark:border-sidebar-border dark:bg-neutral-900/60 dark:text-neutral-300 dark:hover:bg-neutral-800 dark:hover:text-neutral-100"
                                >
                                    {example}
                                </button>
                            ))}
                        </div>
                    </div>
                )}

                {messages.map((message, index) => {
                    const isLatest = index === messages.length - 1;

                    if (message.role === 'user') {
                        return (
                            <div key={message.id} className="flex justify-end">
                                <div className="max-w-[85%] rounded-2xl rounded-tr-xs bg-neutral-900 px-3.5 py-2 text-sm text-white shadow-xs dark:bg-neutral-100 dark:text-neutral-900">
                                    <p className="leading-relaxed whitespace-pre-line">
                                        {message.text}
                                    </p>
                                    {message.timestamp && (
                                        <span className="mt-0.5 block text-right text-[10px] text-neutral-400 dark:text-neutral-500">
                                            {message.timestamp}
                                        </span>
                                    )}
                                </div>
                            </div>
                        );
                    }

                    return (
                        <div
                            key={message.id}
                            className="flex items-start justify-start gap-2"
                        >
                            <span className="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-neutral-200/70 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-300">
                                <Sparkles className="h-3.5 w-3.5" />
                            </span>
                            <div className="max-w-[88%] rounded-2xl rounded-tl-xs border border-neutral-200/60 bg-neutral-100/90 px-3.5 py-2.5 text-sm text-neutral-800 shadow-2xs dark:border-neutral-800/80 dark:bg-neutral-800/80 dark:text-neutral-100">
                                <AssistantMessageContent text={message.text} />

                                {/* Minimal quick action pill buttons for confirmations */}
                                {isLatest &&
                                    !busy &&
                                    !message.answered &&
                                    parseAssistantText(message.text)
                                        .isConfirmation && (
                                        <div className="mt-2.5 flex flex-wrap items-center gap-2 border-t border-neutral-200/60 pt-2 dark:border-neutral-700/60">
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    send('Yes, go ahead')
                                                }
                                                className="inline-flex cursor-pointer items-center gap-1.5 rounded-full bg-emerald-600 px-3.5 py-1.5 text-xs font-semibold text-white shadow-xs transition hover:bg-emerald-700 active:scale-95"
                                            >
                                                <Check className="h-3.5 w-3.5 stroke-[2.5]" />
                                                Yes, go ahead
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    send('No, cancel')
                                                }
                                                className="inline-flex cursor-pointer items-center gap-1.5 rounded-full border border-neutral-300 bg-white px-3.5 py-1.5 text-xs font-medium text-neutral-700 shadow-2xs transition hover:bg-neutral-50 active:scale-95 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200 dark:hover:bg-neutral-700"
                                            >
                                                <X className="h-3.5 w-3.5" />
                                                Cancel
                                            </button>
                                        </div>
                                    )}

                                {message.timestamp && (
                                    <span className="mt-1 block text-right text-[10px] text-neutral-400 dark:text-neutral-500">
                                        {message.timestamp}
                                    </span>
                                )}
                            </div>
                        </div>
                    );
                })}

                {pending.map((approval) => (
                    <ApprovalNeededCard
                        key={approval.id}
                        approval={approval}
                        busy={busy}
                        onApprove={() => decide(approval, 'approve')}
                        onReject={() => decide(approval, 'reject')}
                    />
                ))}

                {started && <PaymentActionCard started={started} />}

                {busy && (
                    <div className="flex items-center gap-2 pl-8 text-xs text-neutral-500">
                        <Loader2 className="h-3.5 w-3.5 animate-spin text-neutral-400" />
                        <span>Thinking…</span>
                    </div>
                )}
                {error && (
                    <p className="pl-8 text-xs text-red-600 dark:text-red-400">
                        {error}
                    </p>
                )}
                <div ref={endRef} />
            </div>

            <form onSubmit={submit} className="mt-3.5 flex items-center gap-2">
                <Input
                    value={draft}
                    onChange={(e) => setDraft(e.target.value)}
                    placeholder="e.g. Send K2,500 to 0977123456"
                    aria-label="Message the assistant"
                    disabled={busy || pending.length > 0}
                    className="h-10 rounded-full border-sidebar-border/80 bg-neutral-50/70 px-4 text-sm focus-visible:ring-1 dark:bg-neutral-900/70"
                />
                <Button
                    type="submit"
                    disabled={busy || !draft.trim() || pending.length > 0}
                    className="flex h-10 w-10 shrink-0 cursor-pointer items-center justify-center rounded-full p-0 transition disabled:opacity-40"
                    aria-label="Send message"
                >
                    <Send className="h-4 w-4" />
                </Button>
            </form>
        </div>
    );
}
