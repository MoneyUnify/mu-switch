import { Head, Link, router, useForm, usePoll } from '@inertiajs/react';
import { Button } from '@radix-ui/themes';
import {
    AlertTriangle,
    ArrowDown,
    ArrowUpRight,
    CheckCircle2,
    Clock,
    CreditCard,
    Loader2,
    Lock,
    RefreshCw,
    Send,
    Sparkles,
    Undo2,
    XCircle,
} from 'lucide-react';
import type { FormEvent, ReactNode } from 'react';
import { useEffect, useMemo, useState } from 'react';
import SendMoneyController from '@/actions/App/Http/Controllers/SendMoneyController';
import InputError from '@/components/input-error';
import { RecentConversations } from '@/components/recent-conversations';
import type { ConversationPage } from '@/components/recent-conversations';
import { SendMoneyAssistant } from '@/components/send-money-assistant';
import type { ResumedConversation } from '@/components/send-money-assistant';
import { TableFooter } from '@/components/table-footer';
import type { PageMeta } from '@/components/table-footer';
import { TableSearch } from '@/components/table-search';
import { Card } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { countryFlag } from '@/lib/flags';
import { cn } from '@/lib/utils';
import providersRoute from '@/routes/providers';
import { index as sendIndex } from '@/routes/send';
import { index as transfersIndex } from '@/routes/transfers';

type Status =
    | 'awaiting_payment'
    | 'funded'
    | 'paying_out'
    | 'delivered'
    | 'payment_failed'
    | 'payout_failed'
    | 'refunding'
    | 'refunded'
    | 'refund_failed';

interface Money {
    amount: string;
    currency: string;
}

interface Transfer {
    reference: string;
    status: Status;
    status_label: string;
    is_final: boolean;
    sender_pays: Money;
    recipient_gets: Money;
    recipient: { account_number: string; country: string; name: string | null };
    fx: {
        effective_rate: string;
        margin_percent: string;
        margin_amount: string;
        rate_source?: 'paypal' | 'moneyunify' | null;
    };
    funding: { provider: string | null; status: string | null };
    payout: { provider: string | null; status: string } | null;
    refund: { status: string } | null;
    failure_reason: string | null;
    created_at: string | null;
    timeline: Array<{ status: Status; note: string | null; at: string | null }>;
}

interface Quote {
    sender_pays: Money;
    recipient_gets: Money;
    fx: {
        base_rate: string;
        effective_rate: string;
        margin_percent: string;
        margin_amount: string;
        rate_source?: 'paypal' | 'moneyunify';
    };
    operator_fees: { paypal: Money | null; payout: Money | null };
    funding_provider: string;
    payout_route: string[];
    expires_at: string;
}

interface Country {
    code: string;
    name: string;
    currency: string | null;
}

interface Props {
    transfers: PageMeta & { data: Transfer[] };
    filters: { q: string | null };
    perPageOptions: number[];
    countries: Country[];
    sourceCurrencies: string[];
    returned: string | null;
    aiReady: boolean;
    conversations: ConversationPage;
}

const ONLY = ['transfers', 'filters', 'returned', 'conversations'];

const selectClassName =
    'flex h-9 w-full rounded-full border border-input bg-transparent px-3.5 py-1 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

/** An exact decimal string in its currency, e.g. "ZMW 2,500.00". */
function formatMoney({ amount, currency }: Money): string {
    const [whole, fraction] = amount.split('.');
    const grouped = Number(whole).toLocaleString('en-US');

    return `${currency} ${fraction ? `${grouped}.${fraction}` : grouped}`;
}

function timeAgo(iso: string | null): string {
    if (!iso) {
        return '';
    }

    const seconds = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000);

    if (seconds < 60) {
        return 'just now';
    }

    if (seconds < 3600) {
        return `${Math.floor(seconds / 60)} min ago`;
    }

    if (seconds < 86400) {
        return `${Math.floor(seconds / 3600)} h ago`;
    }

    return new Date(iso).toLocaleDateString();
}

const statusTone: Record<Status, string> = {
    awaiting_payment:
        'bg-neutral-100 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-300',
    funded: 'bg-blue-50 text-blue-700 dark:bg-blue-950/40 dark:text-blue-300',
    paying_out:
        'bg-blue-50 text-blue-700 dark:bg-blue-950/40 dark:text-blue-300',
    delivered:
        'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300',
    payment_failed:
        'bg-neutral-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-400',
    payout_failed:
        'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300',
    refunding:
        'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300',
    refunded:
        'bg-violet-50 text-violet-700 dark:bg-violet-950/40 dark:text-violet-300',
    refund_failed:
        'bg-red-50 text-red-700 dark:bg-red-950/40 dark:text-red-300',
};

/**
 * The transfer's progress as three steps: paid, sending, then delivered or
 * refunded.
 */
function Progress({ status }: { status: Status }) {
    const refundPath = [
        'payout_failed',
        'refunding',
        'refunded',
        'refund_failed',
    ].includes(status);
    const order: Status[] = refundPath
        ? ['funded', 'payout_failed', 'refunded']
        : ['funded', 'paying_out', 'delivered'];
    const reached: Record<Status, number> = {
        awaiting_payment: 0,
        payment_failed: 0,
        funded: 1,
        paying_out: 2,
        delivered: 3,
        payout_failed: 2,
        refunding: 2,
        refund_failed: 2,
        refunded: 3,
    };
    const labels = refundPath
        ? ['Paid', 'Delivery failed', 'Refunded']
        : ['Paid', 'Sending', 'Delivered'];
    const done = reached[status];

    return (
        <ol className="flex items-center gap-1.5" aria-label="Progress">
            {order.map((step, index) => (
                <li key={step} className="flex flex-1 items-center gap-1.5">
                    <span
                        className={cn(
                            'h-1.5 flex-1 rounded-full',
                            index < done
                                ? refundPath && index > 0
                                    ? 'bg-violet-500'
                                    : 'bg-emerald-500'
                                : index === done &&
                                    ![
                                        'delivered',
                                        'refunded',
                                        'payment_failed',
                                    ].includes(status)
                                  ? 'animate-pulse bg-blue-300 dark:bg-blue-700'
                                  : 'bg-neutral-200 dark:bg-neutral-800',
                        )}
                    />
                    <span className="sr-only">
                        {labels[index]}: {index < done ? 'done' : 'pending'}
                    </span>
                </li>
            ))}
        </ol>
    );
}

function QuoteRow({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex items-baseline justify-between gap-4 text-xs">
            <dt className="text-neutral-500 dark:text-neutral-400">{label}</dt>
            <dd className="text-right font-medium text-neutral-800 tabular-nums dark:text-neutral-200">
                {children}
            </dd>
        </div>
    );
}

export default function SendMoney({
    transfers,
    filters,
    perPageOptions,
    countries,
    sourceCurrencies,
    returned,
    aiReady,
    conversations,
}: Props) {
    const [tab, setTab] = useState<'assistant' | 'form'>('assistant');
    const [resume, setResume] = useState<ResumedConversation | null>(null);
    const ready = countries.length > 0 && sourceCurrencies.length > 0;
    const form = useForm({
        country: countries[0]?.code ?? '',
        source_currency: sourceCurrencies.includes('USD')
            ? 'USD'
            : (sourceCurrencies[0] ?? ''),
        mode: 'target' as 'source' | 'target',
        amount: '',
        account_number: '',
        recipient_name: '',
        recipient_email: '',
        network: '',
        payer_email: '',
    });
    const { country, source_currency: source, mode, amount } = form.data;
    const target = countries.find((c) => c.code === country)?.currency ?? '';

    const [quote, setQuote] = useState<Quote | null>(null);
    const [payingWith, setPayingWith] = useState<'paypal' | 'card'>('paypal');
    const [quoteError, setQuoteError] = useState<string | null>(null);
    const [isQuoting, setIsQuoting] = useState(false);
    const [now, setNow] = useState(() => Date.now());

    // Re-quote shortly after the inputs settle.
    useEffect(() => {
        if (!ready || !(Number(amount) > 0)) {
            return;
        }

        const controller = new AbortController();
        const timer = setTimeout(async () => {
            setIsQuoting(true);

            try {
                const response = await fetch(SendMoneyController.quote.url(), {
                    method: 'POST',
                    signal: controller.signal,
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN':
                            (
                                document.querySelector(
                                    'meta[name="csrf-token"]',
                                ) as HTMLMetaElement | null
                            )?.content ?? '',
                    },
                    body: JSON.stringify({
                        amount,
                        mode,
                        country,
                        source_currency: source,
                    }),
                });
                const json = await response.json();

                if (response.ok) {
                    setQuote(json.data as Quote);
                    setQuoteError(null);
                } else {
                    setQuote(null);
                    setQuoteError(
                        json.message ?? 'This transfer cannot be quoted.',
                    );
                }
            } catch (error) {
                if ((error as Error).name !== 'AbortError') {
                    setQuote(null);
                    setQuoteError('Could not reach the quote service.');
                }
            } finally {
                if (!controller.signal.aborted) {
                    setIsQuoting(false);
                }
            }
        }, 400);

        return () => {
            controller.abort();
            clearTimeout(timer);
        };
    }, [ready, amount, mode, country, source]);

    // Tick the quote's countdown.
    useEffect(() => {
        if (!quote) {
            return;
        }

        const interval = setInterval(() => setNow(Date.now()), 1000);

        return () => clearInterval(interval);
    }, [quote]);

    const shownQuote = Number(amount) > 0 ? quote : null;
    const shownError = Number(amount) > 0 ? quoteError : null;
    const secondsLeft = shownQuote
        ? Math.max(
              0,
              Math.floor(
                  (new Date(shownQuote.expires_at).getTime() - now) / 1000,
              ),
          )
        : 0;

    // Follow transfers that are still moving.
    const inFlight = transfers.data.some((transfer) => !transfer.is_final);
    const { start, stop } = usePoll(4000, { only: ONLY }, { autoStart: false });

    useEffect(() => {
        if (inFlight) {
            start();
        } else {
            stop();
        }

        return stop;
    }, [inFlight, start, stop]);

    const returnedTransfer = useMemo(
        () => transfers.data.find((t) => t.reference === returned) ?? null,
        [transfers.data, returned],
    );

    const [selectedTransfer, setSelectedTransfer] = useState<Transfer | null>(
        null,
    );

    // The open transfer, kept in step with the latest poll data.
    const shownTransfer = selectedTransfer
        ? (transfers.data.find(
              (t) => t.reference === selectedTransfer.reference,
          ) ?? selectedTransfer)
        : null;

    const getCountryName = (code: string) =>
        countries.find((c) => c.code === code)?.name ?? code;

    const cannotPay =
        !ready ||
        !shownQuote ||
        secondsLeft === 0 ||
        !form.data.account_number ||
        form.processing;

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        // Both buttons go through PayPal; "card" opens its guest card form.
        const submitter = (event.nativeEvent as SubmitEvent)
            .submitter as HTMLButtonElement | null;
        const payWith = submitter?.value === 'card' ? 'card' : 'paypal';

        setPayingWith(payWith);
        form.transform((data) => ({ ...data, pay_with: payWith }));
        form.post(SendMoneyController.store.url());
    };

    const verify = (reference: string) =>
        router.post(
            SendMoneyController.verify.url(reference),
            {},
            { only: ONLY, preserveScroll: true },
        );

    return (
        <>
            <Head title="Send Money" />
            <div className="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-6">
                <div>
                    <h1 className="text-lg font-semibold tracking-tight">
                        Send Money
                    </h1>
                    <p className="mt-1 text-xs text-neutral-600 dark:text-neutral-400">
                        The sender pays with PayPal. The recipient is paid on
                        mobile money in their own currency, at a rate locked
                        when you quote. If it can&apos;t be delivered, PayPal
                        refunds the sender.
                    </p>
                </div>

                {returnedTransfer && (
                    <div
                        className="flex items-start gap-2.5 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm dark:border-blue-900/60 dark:bg-blue-950/30"
                        data-test="returned-transfer"
                    >
                        <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0 text-blue-600" />
                        <p className="text-blue-900 dark:text-blue-100">
                            <span className="font-medium">
                                {returnedTransfer.status_label}.
                            </span>{' '}
                            {formatMoney(returnedTransfer.recipient_gets)} to{' '}
                            {returnedTransfer.recipient.name ??
                                returnedTransfer.recipient.account_number}
                            . This page updates on its own.
                        </p>
                    </div>
                )}

                {!ready && (
                    <div className="flex flex-col gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm sm:flex-row sm:items-center sm:justify-between dark:border-amber-900/60 dark:bg-amber-950/30">
                        <div className="flex items-start gap-2.5">
                            <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-amber-600" />
                            <p className="text-amber-900 dark:text-amber-200">
                                {sourceCurrencies.length === 0
                                    ? 'Add an active PayPal account to collect from senders.'
                                    : 'Add an active payout provider (e.g. Lenco or pawaPay) to deliver to mobile money.'}
                            </p>
                        </div>
                        <Button asChild size="1" className="shrink-0">
                            <Link href={providersRoute.index.url()}>
                                Open Providers
                            </Link>
                        </Button>
                    </div>
                )}

                <div className="grid items-start gap-6 xl:grid-cols-12">
                    <div className="space-y-6 xl:col-span-5">
                        <Card className="gap-0 border border-sidebar-border/70 p-5 dark:border-sidebar-border">
                            <div className="flex items-start justify-between gap-3">
                                <div>
                                    <h2 className="text-sm font-semibold">
                                        New transfer
                                    </h2>
                                    <p className="mt-0.5 text-xs text-neutral-500">
                                        {tab === 'assistant'
                                            ? 'Describe the transfer; you approve before anything starts.'
                                            : 'Quotes are free and lock the rate for a few minutes.'}
                                    </p>
                                </div>
                                <div
                                    className="inline-flex shrink-0 rounded-full bg-neutral-100 p-0.5 text-[11px] dark:bg-neutral-800"
                                    role="tablist"
                                    aria-label="How to send"
                                >
                                    {(
                                        [
                                            ['assistant', 'Assistant'],
                                            ['form', 'Form'],
                                        ] as const
                                    ).map(([value, label]) => (
                                        <button
                                            key={value}
                                            type="button"
                                            role="tab"
                                            aria-selected={tab === value}
                                            onClick={() => setTab(value)}
                                            className={cn(
                                                'inline-flex cursor-pointer items-center gap-1 rounded-full px-2.5 py-0.5 font-medium transition-colors',
                                                tab === value
                                                    ? 'bg-white text-neutral-900 shadow-xs dark:bg-neutral-950 dark:text-neutral-100'
                                                    : 'text-neutral-500',
                                            )}
                                        >
                                            {value === 'assistant' && (
                                                <Sparkles className="h-3 w-3" />
                                            )}
                                            {label}
                                        </button>
                                    ))}
                                </div>
                            </div>

                            {/* Kept mounted, so switching tabs never loses the conversation. */}
                            <div
                                className={cn(
                                    'mt-4',
                                    tab !== 'assistant' && 'hidden',
                                )}
                            >
                                <SendMoneyAssistant
                                    key={resume?.id ?? 'new'}
                                    resume={resume}
                                    aiReady={aiReady}
                                    canSend={ready}
                                    onlyReload={ONLY}
                                />
                            </div>
                            {tab === 'form' && (
                                <form
                                    onSubmit={submit}
                                    className="mt-4 space-y-4"
                                >
                                    <div className="grid grid-cols-2 gap-3">
                                        <div className="space-y-1">
                                            <Label htmlFor="send-country">
                                                Send to
                                            </Label>
                                            <select
                                                id="send-country"
                                                value={country}
                                                onChange={(e) =>
                                                    form.setData(
                                                        'country',
                                                        e.target.value,
                                                    )
                                                }
                                                className={selectClassName}
                                                disabled={!ready}
                                            >
                                                {countries.map((option) => (
                                                    <option
                                                        key={option.code}
                                                        value={option.code}
                                                    >
                                                        {countryFlag(
                                                            option.code,
                                                        )}{' '}
                                                        {option.name} (
                                                        {option.currency})
                                                    </option>
                                                ))}
                                            </select>
                                            <InputError
                                                message={form.errors.country}
                                            />
                                        </div>
                                        <div className="space-y-1">
                                            <Label htmlFor="send-source">
                                                Sender pays in
                                            </Label>
                                            <select
                                                id="send-source"
                                                value={source}
                                                onChange={(e) =>
                                                    form.setData(
                                                        'source_currency',
                                                        e.target.value,
                                                    )
                                                }
                                                className={selectClassName}
                                                disabled={!ready}
                                            >
                                                {sourceCurrencies.map(
                                                    (currency) => (
                                                        <option
                                                            key={currency}
                                                            value={currency}
                                                        >
                                                            {currency} via
                                                            PayPal
                                                        </option>
                                                    ),
                                                )}
                                            </select>
                                        </div>
                                    </div>

                                    <div className="space-y-1">
                                        <div className="flex items-center justify-between">
                                            <Label htmlFor="send-amount">
                                                Amount
                                            </Label>
                                            <div
                                                className="inline-flex rounded-full bg-neutral-100 p-0.5 text-[11px] dark:bg-neutral-800"
                                                role="radiogroup"
                                                aria-label="What the amount means"
                                            >
                                                {(
                                                    [
                                                        [
                                                            'target',
                                                            'Recipient gets',
                                                        ],
                                                        [
                                                            'source',
                                                            'Sender pays',
                                                        ],
                                                    ] as const
                                                ).map(([value, label]) => (
                                                    <button
                                                        key={value}
                                                        type="button"
                                                        role="radio"
                                                        aria-checked={
                                                            mode === value
                                                        }
                                                        onClick={() =>
                                                            form.setData(
                                                                'mode',
                                                                value,
                                                            )
                                                        }
                                                        className={cn(
                                                            'cursor-pointer rounded-full px-2.5 py-0.5 font-medium transition-colors',
                                                            mode === value
                                                                ? 'bg-white text-neutral-900 shadow-xs dark:bg-neutral-950 dark:text-neutral-100'
                                                                : 'text-neutral-500',
                                                        )}
                                                    >
                                                        {label}
                                                    </button>
                                                ))}
                                            </div>
                                        </div>
                                        <div className="relative">
                                            <Input
                                                id="send-amount"
                                                type="number"
                                                inputMode="decimal"
                                                min="0"
                                                step="any"
                                                value={amount}
                                                onChange={(e) =>
                                                    form.setData(
                                                        'amount',
                                                        e.target.value,
                                                    )
                                                }
                                                placeholder="0.00"
                                                className="pr-14"
                                                disabled={!ready}
                                            />
                                            <span className="pointer-events-none absolute top-1/2 right-4 -translate-y-1/2 text-xs font-medium text-neutral-500">
                                                {mode === 'target'
                                                    ? target
                                                    : source}
                                            </span>
                                        </div>
                                        <InputError
                                            message={form.errors.amount}
                                        />
                                    </div>

                                    <div
                                        className="rounded-lg border border-sidebar-border/70 bg-neutral-50/60 p-3.5 dark:border-sidebar-border dark:bg-neutral-900/40"
                                        aria-live="polite"
                                        data-test="send-quote"
                                    >
                                        {shownQuote ? (
                                            <>
                                                <div className="flex items-center justify-between gap-3">
                                                    <div>
                                                        <div className="text-[11px] tracking-wide text-neutral-500 uppercase">
                                                            Sender pays
                                                        </div>
                                                        <div className="text-lg font-semibold tabular-nums">
                                                            {formatMoney(
                                                                shownQuote.sender_pays,
                                                            )}
                                                        </div>
                                                    </div>
                                                    <ArrowDown className="h-4 w-4 -rotate-90 text-neutral-400" />
                                                    <div className="text-right">
                                                        <div className="text-[11px] tracking-wide text-neutral-500 uppercase">
                                                            Recipient gets
                                                        </div>
                                                        <div className="text-lg font-semibold text-emerald-700 tabular-nums dark:text-emerald-400">
                                                            {formatMoney(
                                                                shownQuote.recipient_gets,
                                                            )}
                                                        </div>
                                                    </div>
                                                </div>
                                                <dl className="mt-3 space-y-1.5 border-t border-sidebar-border/60 pt-3">
                                                    <QuoteRow
                                                        label={
                                                            shownQuote.fx
                                                                .rate_source ===
                                                            'paypal'
                                                                ? 'Rate (PayPal FX)'
                                                                : 'Rate'
                                                        }
                                                    >
                                                        1{' '}
                                                        {
                                                            shownQuote
                                                                .sender_pays
                                                                .currency
                                                        }{' '}
                                                        ={' '}
                                                        {Number(
                                                            shownQuote.fx
                                                                .effective_rate,
                                                        ).toLocaleString(
                                                            'en-US',
                                                            {
                                                                maximumFractionDigits: 4,
                                                            },
                                                        )}{' '}
                                                        {
                                                            shownQuote
                                                                .recipient_gets
                                                                .currency
                                                        }
                                                    </QuoteRow>
                                                    <QuoteRow
                                                        label={`FX margin (${Number(shownQuote.fx.margin_percent)}%)`}
                                                    >
                                                        {formatMoney({
                                                            amount: shownQuote
                                                                .fx
                                                                .margin_amount,
                                                            currency:
                                                                shownQuote
                                                                    .sender_pays
                                                                    .currency,
                                                        })}
                                                    </QuoteRow>
                                                    <QuoteRow label="Delivered via">
                                                        {shownQuote.payout_route.join(
                                                            ' → ',
                                                        )}
                                                    </QuoteRow>
                                                    <QuoteRow label="Your provider fees (est.)">
                                                        {[
                                                            shownQuote
                                                                .operator_fees
                                                                .paypal,
                                                            shownQuote
                                                                .operator_fees
                                                                .payout,
                                                        ]
                                                            .filter(
                                                                (
                                                                    fee,
                                                                ): fee is Money =>
                                                                    fee !==
                                                                    null,
                                                            )
                                                            .map(formatMoney)
                                                            .join(' + ') || '—'}
                                                    </QuoteRow>
                                                </dl>
                                                <p className="mt-2.5 flex items-center gap-1.5 text-[11px] text-neutral-500">
                                                    <Lock className="h-3 w-3" />
                                                    {secondsLeft > 0
                                                        ? `Rate locked for ${Math.floor(secondsLeft / 60)}:${String(secondsLeft % 60).padStart(2, '0')}`
                                                        : 'Rate expired: change the amount to re-quote'}
                                                    {isQuoting && (
                                                        <Loader2 className="ml-auto h-3 w-3 animate-spin" />
                                                    )}
                                                </p>
                                            </>
                                        ) : (
                                            <p className="flex items-center gap-2 text-xs text-neutral-500">
                                                {isQuoting ? (
                                                    <>
                                                        <Loader2 className="h-3.5 w-3.5 animate-spin" />
                                                        Quoting…
                                                    </>
                                                ) : shownError ? (
                                                    <span className="text-red-600 dark:text-red-400">
                                                        {shownError}
                                                    </span>
                                                ) : (
                                                    'Enter an amount to see the rate, what the sender pays and what arrives.'
                                                )}
                                            </p>
                                        )}
                                    </div>

                                    <div className="grid grid-cols-2 gap-3">
                                        <div className="col-span-2 space-y-1 sm:col-span-1">
                                            <Label htmlFor="send-number">
                                                Recipient&apos;s mobile number
                                            </Label>
                                            <Input
                                                id="send-number"
                                                value={form.data.account_number}
                                                onChange={(e) =>
                                                    form.setData(
                                                        'account_number',
                                                        e.target.value,
                                                    )
                                                }
                                                placeholder="0977 123 456"
                                                autoComplete="off"
                                                disabled={!ready}
                                            />
                                            <InputError
                                                message={
                                                    form.errors.account_number
                                                }
                                            />
                                        </div>
                                        <div className="col-span-2 space-y-1 sm:col-span-1">
                                            <Label htmlFor="send-name">
                                                Recipient&apos;s name (optional)
                                            </Label>
                                            <Input
                                                id="send-name"
                                                value={form.data.recipient_name}
                                                onChange={(e) =>
                                                    form.setData(
                                                        'recipient_name',
                                                        e.target.value,
                                                    )
                                                }
                                                disabled={!ready}
                                            />
                                        </div>
                                        <div className="col-span-2 space-y-1 sm:col-span-1">
                                            <Label htmlFor="send-recipient-email">
                                                Recipient&apos;s email
                                                (optional)
                                            </Label>
                                            <Input
                                                id="send-recipient-email"
                                                type="email"
                                                value={
                                                    form.data.recipient_email
                                                }
                                                onChange={(e) =>
                                                    form.setData(
                                                        'recipient_email',
                                                        e.target.value,
                                                    )
                                                }
                                                placeholder="Told when the money arrives"
                                                disabled={!ready}
                                            />
                                            <InputError
                                                message={
                                                    form.errors.recipient_email
                                                }
                                            />
                                        </div>
                                        <div className="col-span-2 space-y-1 sm:col-span-1">
                                            <Label htmlFor="send-network">
                                                Network (optional)
                                            </Label>
                                            <Input
                                                id="send-network"
                                                value={form.data.network}
                                                onChange={(e) =>
                                                    form.setData(
                                                        'network',
                                                        e.target.value,
                                                    )
                                                }
                                                placeholder="Detected from the number"
                                                disabled={!ready}
                                            />
                                        </div>
                                        <div className="col-span-2 space-y-1 sm:col-span-1">
                                            <Label htmlFor="send-payer">
                                                Sender&apos;s email (optional)
                                            </Label>
                                            <Input
                                                id="send-payer"
                                                type="email"
                                                value={form.data.payer_email}
                                                onChange={(e) =>
                                                    form.setData(
                                                        'payer_email',
                                                        e.target.value,
                                                    )
                                                }
                                                disabled={!ready}
                                            />
                                            <InputError
                                                message={
                                                    form.errors.payer_email
                                                }
                                            />
                                        </div>
                                    </div>

                                    <div className="flex flex-col gap-3">
                                        <Button
                                            type="submit"
                                            value="paypal"
                                            size="3"
                                            style={{ width: '100%' }}
                                            className="cursor-pointer gap-2"
                                            disabled={cannotPay}
                                        >
                                            {form.processing &&
                                            payingWith === 'paypal' ? (
                                                <Loader2 className="h-4 w-4 animate-spin" />
                                            ) : (
                                                <img
                                                    src="/paypal.png"
                                                    alt=""
                                                    className="h-4 w-4 rounded-sm bg-white object-contain"
                                                />
                                            )}
                                            {shownQuote
                                                ? `Pay ${formatMoney(shownQuote.sender_pays)} with PayPal`
                                                : 'Pay with PayPal'}
                                        </Button>
                                        <Button
                                            type="submit"
                                            value="card"
                                            size="3"
                                            variant="outline"
                                            color="gray"
                                            highContrast
                                            style={{ width: '100%' }}
                                            className="cursor-pointer gap-2"
                                            disabled={cannotPay}
                                            data-test="pay-with-card"
                                        >
                                            {form.processing &&
                                            payingWith === 'card' ? (
                                                <Loader2 className="h-4 w-4 animate-spin" />
                                            ) : (
                                                <CreditCard className="h-4 w-4" />
                                            )}
                                            Debit or credit card
                                        </Button>
                                    </div>
                                    <p className="text-center text-[11px] text-neutral-500">
                                        The sender approves the payment on
                                        PayPal, with a PayPal account or as a
                                        guest by card. Nothing is sent until
                                        PayPal confirms it.
                                    </p>
                                </form>
                            )}
                        </Card>

                        <RecentConversations
                            page={conversations}
                            onContinue={(conversation) => {
                                setResume(conversation);
                                setTab('assistant');
                            }}
                        />
                    </div>

                    <Card className="gap-0 overflow-hidden border border-sidebar-border/70 p-0 xl:col-span-7 dark:border-sidebar-border">
                        <div className="card-header-raised flex flex-col gap-3 px-5 pt-5 pb-4 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <h2 className="text-sm font-semibold">
                                    Transfers
                                </h2>
                                <p className="mt-0.5 text-xs text-neutral-500">
                                    Each transfer moves on its own: paid,
                                    sending, then delivered or refunded.
                                </p>
                            </div>
                            <TableSearch
                                param="q"
                                pageName="page"
                                only={ONLY}
                                initial={filters.q}
                                placeholder="Search transfers…"
                            />
                        </div>

                        <div className="px-5 pb-5">
                            {transfers.data.length === 0 ? (
                                <div className="flex flex-col items-center py-12 text-center text-sm text-neutral-500">
                                    <Send className="mb-2 h-6 w-6 text-neutral-400" />
                                    {filters.q
                                        ? `No transfers match “${filters.q}”.`
                                        : 'No transfers yet. Quote one on the left.'}
                                </div>
                            ) : (
                                <ul className="mt-1 divide-y divide-sidebar-border/50 dark:divide-sidebar-border/70">
                                    {transfers.data.map((transfer) => (
                                        <li
                                            key={transfer.reference}
                                            className={cn(
                                                'py-3.5 transition-colors',
                                                transfer.reference ===
                                                    returned &&
                                                    '-mx-2 rounded-lg bg-blue-50/60 px-2 dark:bg-blue-950/20',
                                            )}
                                        >
                                            <div className="flex items-start justify-between gap-3">
                                                <div className="min-w-0">
                                                    <div className="flex flex-wrap items-center gap-2 text-sm">
                                                        <span
                                                            aria-hidden
                                                            className="text-base"
                                                        >
                                                            {countryFlag(
                                                                transfer
                                                                    .recipient
                                                                    .country,
                                                            )}
                                                        </span>
                                                        <span className="truncate font-medium text-neutral-900 dark:text-neutral-100">
                                                            {transfer.recipient
                                                                .name ??
                                                                transfer
                                                                    .recipient
                                                                    .account_number}
                                                        </span>
                                                        <span
                                                            className={cn(
                                                                'rounded-md px-2 py-0.5 text-[11px] font-medium',
                                                                statusTone[
                                                                    transfer
                                                                        .status
                                                                ],
                                                            )}
                                                        >
                                                            {
                                                                transfer.status_label
                                                            }
                                                        </span>
                                                    </div>
                                                    <div className="mt-0.5 text-xs text-neutral-500">
                                                        {transfer.recipient
                                                            .name &&
                                                            `${transfer.recipient.account_number} · `}
                                                        {formatMoney(
                                                            transfer.sender_pays,
                                                        )}{' '}
                                                        via{' '}
                                                        {transfer.funding
                                                            .provider ??
                                                            'PayPal'}
                                                        {transfer.payout
                                                            ?.provider &&
                                                            ` → ${transfer.payout.provider}`}
                                                        {' · '}
                                                        {timeAgo(
                                                            transfer.created_at,
                                                        )}
                                                    </div>
                                                </div>
                                                <div className="shrink-0 text-right">
                                                    <div className="text-sm font-semibold text-neutral-900 tabular-nums dark:text-neutral-100">
                                                        {formatMoney(
                                                            transfer.recipient_gets,
                                                        )}
                                                    </div>
                                                    <div className="text-[11px] text-neutral-500 tabular-nums">
                                                        @{' '}
                                                        {Number(
                                                            transfer.fx
                                                                .effective_rate,
                                                        ).toLocaleString(
                                                            'en-US',
                                                            {
                                                                maximumFractionDigits: 4,
                                                            },
                                                        )}
                                                    </div>
                                                </div>
                                            </div>

                                            <div className="mt-2.5 flex items-center justify-between gap-3">
                                                <div className="max-w-xs flex-1 sm:max-w-sm">
                                                    <Progress
                                                        status={transfer.status}
                                                    />
                                                </div>
                                                <div className="flex shrink-0 items-center gap-1.5">
                                                    <Button
                                                        size="1"
                                                        variant="soft"
                                                        color="gray"
                                                        onClick={() =>
                                                            setSelectedTransfer(
                                                                transfer,
                                                            )
                                                        }
                                                        className="cursor-pointer gap-1 text-[11px]"
                                                    >
                                                        <Clock className="h-3 w-3 text-neutral-500" />
                                                        <span>Timeline</span>
                                                        <span className="text-[10px] text-neutral-400">
                                                            (
                                                            {
                                                                transfer
                                                                    .timeline
                                                                    .length
                                                            }
                                                            )
                                                        </span>
                                                    </Button>
                                                    {!transfer.is_final && (
                                                        <Button
                                                            size="1"
                                                            variant="soft"
                                                            color="gray"
                                                            onClick={() =>
                                                                verify(
                                                                    transfer.reference,
                                                                )
                                                            }
                                                            className="cursor-pointer gap-1 text-[11px]"
                                                        >
                                                            <RefreshCw className="h-3 w-3" />
                                                            Check now
                                                        </Button>
                                                    )}
                                                </div>
                                            </div>

                                            {transfer.failure_reason && (
                                                <div className="mt-2 flex items-start justify-between gap-2 rounded-md bg-amber-50/70 px-2.5 py-1.5 text-xs text-amber-800 dark:bg-amber-950/20 dark:text-amber-300">
                                                    <div className="flex min-w-0 items-start gap-1.5">
                                                        {transfer.status ===
                                                        'refunded' ? (
                                                            <Undo2 className="mt-0.5 h-3.5 w-3.5 shrink-0 text-amber-600 dark:text-amber-400" />
                                                        ) : (
                                                            <XCircle className="mt-0.5 h-3.5 w-3.5 shrink-0 text-amber-600 dark:text-amber-400" />
                                                        )}
                                                        <span className="line-clamp-1">
                                                            {
                                                                transfer.failure_reason
                                                            }
                                                        </span>
                                                    </div>
                                                    <button
                                                        type="button"
                                                        onClick={() =>
                                                            setSelectedTransfer(
                                                                transfer,
                                                            )
                                                        }
                                                        className="shrink-0 cursor-pointer text-[11px] font-medium underline underline-offset-2 hover:text-amber-950 dark:hover:text-amber-100"
                                                    >
                                                        Details
                                                    </button>
                                                </div>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            )}

                            <TableFooter
                                page={transfers}
                                only={ONLY}
                                perPageKey="perPage"
                                pageName="page"
                                perPageOptions={perPageOptions}
                            />
                        </div>
                    </Card>
                </div>
            </div>

            {/* Transfer Timeline & Details Dialog */}
            <Dialog
                open={!!shownTransfer}
                onOpenChange={(open) => !open && setSelectedTransfer(null)}
            >
                <DialogContent className="flex max-h-[85vh] flex-col gap-0 overflow-hidden p-0 sm:max-w-xl">
                    <DialogHeader className="shrink-0 border-b border-sidebar-border/70 px-6 py-4 pr-12 text-left">
                        <DialogTitle className="flex flex-wrap items-center gap-2">
                            {shownTransfer && (
                                <>
                                    <span aria-hidden className="text-base">
                                        {countryFlag(
                                            shownTransfer.recipient.country,
                                        )}
                                    </span>
                                    <span className="font-semibold">
                                        {shownTransfer.recipient.name ??
                                            shownTransfer.recipient
                                                .account_number}
                                    </span>
                                    <span
                                        className={cn(
                                            'rounded-md px-2 py-0.5 text-xs font-medium',
                                            statusTone[shownTransfer.status],
                                        )}
                                    >
                                        {shownTransfer.status_label}
                                    </span>
                                </>
                            )}
                        </DialogTitle>
                        <DialogDescription className="mt-1 flex flex-wrap items-center gap-2 text-xs text-neutral-500">
                            <span className="font-mono text-neutral-700 dark:text-neutral-300">
                                {shownTransfer?.reference}
                            </span>
                            {shownTransfer?.created_at && (
                                <>
                                    <span>•</span>
                                    <span>
                                        {new Date(
                                            shownTransfer.created_at,
                                        ).toLocaleString()}
                                    </span>
                                    <span>
                                        ({timeAgo(shownTransfer.created_at)})
                                    </span>
                                </>
                            )}
                        </DialogDescription>
                    </DialogHeader>

                    <div className="min-h-0 flex-1 space-y-4 overflow-y-auto px-6 py-4">
                        {shownTransfer && (
                            <>
                                {/* Visual Progress Stepper */}
                                <div className="rounded-lg border border-sidebar-border/70 bg-neutral-50/70 p-3.5 dark:bg-neutral-900/40">
                                    <div className="mb-2 flex items-center justify-between text-xs">
                                        <span className="font-medium text-neutral-700 dark:text-neutral-300">
                                            Transfer Progress
                                        </span>
                                        <span className="text-[11px] text-neutral-500">
                                            {shownTransfer.status_label}
                                        </span>
                                    </div>
                                    <Progress status={shownTransfer.status} />
                                </div>

                                {/* Failure or Refund Alert if present */}
                                {shownTransfer.failure_reason && (
                                    <div className="rounded-lg border border-amber-200/80 bg-amber-50/80 p-3 text-xs text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-200">
                                        <div className="flex items-start gap-2">
                                            {shownTransfer.status ===
                                            'refunded' ? (
                                                <Undo2 className="mt-0.5 h-4 w-4 shrink-0 text-amber-600 dark:text-amber-400" />
                                            ) : (
                                                <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-amber-600 dark:text-amber-400" />
                                            )}
                                            <div>
                                                <span className="font-semibold">
                                                    {shownTransfer.status ===
                                                    'refunded'
                                                        ? 'Refund Resolution'
                                                        : 'Transfer Issue'}
                                                    :
                                                </span>{' '}
                                                {shownTransfer.failure_reason}
                                            </div>
                                        </div>
                                    </div>
                                )}

                                {/* Financial & Delivery Details */}
                                <div className="grid grid-cols-2 gap-x-4 gap-y-3 rounded-lg border border-sidebar-border/60 p-3 text-xs">
                                    <div>
                                        <div className="text-[10px] font-medium tracking-wide text-neutral-400 uppercase">
                                            Sender pays
                                        </div>
                                        <div className="mt-0.5 text-sm font-semibold text-neutral-900 dark:text-neutral-100">
                                            {formatMoney(
                                                shownTransfer.sender_pays,
                                            )}
                                        </div>
                                        <span className="text-[11px] text-neutral-500">
                                            via{' '}
                                            {shownTransfer.funding.provider ??
                                                'PayPal'}
                                        </span>
                                    </div>
                                    <div>
                                        <div className="text-[10px] font-medium tracking-wide text-neutral-400 uppercase">
                                            Recipient receives
                                        </div>
                                        <div className="mt-0.5 text-sm font-semibold text-emerald-700 dark:text-emerald-400">
                                            {formatMoney(
                                                shownTransfer.recipient_gets,
                                            )}
                                        </div>
                                        <span className="text-[11px] text-neutral-500">
                                            {shownTransfer.payout?.provider
                                                ? `via ${shownTransfer.payout.provider}`
                                                : 'mobile money'}
                                        </span>
                                    </div>
                                    <div>
                                        <div className="text-[10px] font-medium tracking-wide text-neutral-400 uppercase">
                                            Effective rate
                                        </div>
                                        <div className="mt-0.5 font-mono text-neutral-800 dark:text-neutral-200">
                                            1{' '}
                                            {shownTransfer.sender_pays.currency}{' '}
                                            ={' '}
                                            {Number(
                                                shownTransfer.fx.effective_rate,
                                            ).toLocaleString('en-US', {
                                                maximumFractionDigits: 4,
                                            })}{' '}
                                            {
                                                shownTransfer.recipient_gets
                                                    .currency
                                            }
                                        </div>
                                        <span className="text-[11px] text-neutral-500">
                                            Margin:{' '}
                                            {Number(
                                                shownTransfer.fx.margin_percent,
                                            )}
                                            %
                                            {shownTransfer.fx.rate_source ===
                                                'paypal' && ' · PayPal FX rate'}
                                        </span>
                                    </div>
                                    <div>
                                        <div className="text-[10px] font-medium tracking-wide text-neutral-400 uppercase">
                                            Recipient account
                                        </div>
                                        <div className="mt-0.5 font-medium text-neutral-800 dark:text-neutral-200">
                                            {
                                                shownTransfer.recipient
                                                    .account_number
                                            }
                                        </div>
                                        <span className="text-[11px] text-neutral-500">
                                            {getCountryName(
                                                shownTransfer.recipient.country,
                                            )}{' '}
                                            ({shownTransfer.recipient.country})
                                        </span>
                                    </div>
                                </div>

                                {/* Timeline Stepper */}
                                <div className="border-t border-sidebar-border/70 pt-4">
                                    <div className="mb-3 flex items-center justify-between">
                                        <span className="text-xs font-semibold tracking-wide text-neutral-700 dark:text-neutral-300">
                                            Timeline Events (
                                            {shownTransfer.timeline.length})
                                        </span>
                                        <span className="text-[11px] text-neutral-400">
                                            Chronological history
                                        </span>
                                    </div>

                                    <ol className="relative ml-2.5 space-y-4 border-l-2 border-neutral-200 pl-4 dark:border-neutral-800">
                                        {shownTransfer.timeline.map(
                                            (event, index) => {
                                                const isRefund =
                                                    event.status.includes(
                                                        'refund',
                                                    );
                                                const isFail =
                                                    event.status.includes(
                                                        'failed',
                                                    );
                                                const isSuccess =
                                                    event.status ===
                                                    'delivered';

                                                return (
                                                    <li
                                                        key={index}
                                                        className="relative text-xs"
                                                    >
                                                        <span
                                                            className={cn(
                                                                'absolute top-1 -left-[23px] h-3 w-3 rounded-full border-2 border-background',
                                                                isSuccess
                                                                    ? 'bg-emerald-500'
                                                                    : isRefund
                                                                      ? 'bg-violet-500'
                                                                      : isFail
                                                                        ? 'bg-red-500'
                                                                        : 'bg-blue-500',
                                                            )}
                                                        />
                                                        <div className="flex flex-wrap items-baseline justify-between gap-1">
                                                            <span className="font-semibold text-neutral-800 dark:text-neutral-200">
                                                                {event.note ??
                                                                    event.status}
                                                            </span>
                                                            {event.at && (
                                                                <span className="shrink-0 text-[11px] text-neutral-400">
                                                                    {new Date(
                                                                        event.at,
                                                                    ).toLocaleTimeString(
                                                                        [],
                                                                        {
                                                                            hour: '2-digit',
                                                                            minute: '2-digit',
                                                                            second: '2-digit',
                                                                        },
                                                                    )}
                                                                </span>
                                                            )}
                                                        </div>
                                                        {event.at && (
                                                            <p className="mt-0.5 text-[11px] text-neutral-400">
                                                                {new Date(
                                                                    event.at,
                                                                ).toLocaleDateString(
                                                                    [],
                                                                    {
                                                                        month: 'short',
                                                                        day: 'numeric',
                                                                        year: 'numeric',
                                                                    },
                                                                )}
                                                            </p>
                                                        )}
                                                    </li>
                                                );
                                            },
                                        )}
                                    </ol>
                                </div>
                            </>
                        )}
                    </div>

                    <DialogFooter className="flex shrink-0 flex-row items-center justify-between border-t border-sidebar-border/70 bg-neutral-50/60 px-6 py-3.5 sm:justify-between dark:bg-neutral-900/40">
                        <Button
                            variant="ghost"
                            size="2"
                            onClick={() => setSelectedTransfer(null)}
                            className="cursor-pointer"
                        >
                            Close
                        </Button>
                        <div className="flex items-center gap-2">
                            {shownTransfer && !shownTransfer.is_final && (
                                <Button
                                    size="2"
                                    variant="soft"
                                    color="blue"
                                    onClick={() => {
                                        verify(shownTransfer.reference);
                                    }}
                                    className="cursor-pointer gap-1.5"
                                >
                                    <RefreshCw className="h-3.5 w-3.5" />
                                    Check status now
                                </Button>
                            )}
                            <Button
                                size="2"
                                variant="soft"
                                color="gray"
                                asChild
                            >
                                <Link href={transfersIndex.url()}>
                                    All Transfers{' '}
                                    <ArrowUpRight className="ml-1 h-3.5 w-3.5" />
                                </Link>
                            </Button>
                        </div>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

SendMoney.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'Send Money', href: sendIndex.url() },
    ],
};
