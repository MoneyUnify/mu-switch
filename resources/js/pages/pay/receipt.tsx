import { Head, usePoll } from '@inertiajs/react';
import { Check, Clock, Link2, Printer, Undo2, X } from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect } from 'react';
import { cn } from '@/lib/utils';

interface Money {
    amount: string;
    currency: string;
}

type StepState = 'done' | 'current' | 'upcoming' | 'failed';

interface Receipt {
    reference: string;
    number: string;
    merchant: string | null;
    status: string;
    is_final: boolean;
    headline: string;
    detail: string;
    steps: Array<{ label: string; state: StepState }>;
    paid: Money;
    price: Money;
    created_at: string | null;
    paypal: PaypalReceipt | null;
}

/** PayPal's own record of the payment. */
interface PaypalReceipt {
    checked_live: boolean;
    sandbox: boolean;
    status: string;
    paypal_transaction_id: string;
    paypal_order_id: string;
    paid_at: string | null;
    payer: { name: string | null; email: string | null; verified: boolean };
    seller_protection: string | null;
    refunded: string | null;
    currency: string;
    activity_url: string;
    receipt_url: string;
}

type Tone = 'ok' | 'progress' | 'refund' | 'warn' | 'muted';

const TONES: Record<Tone, string> = {
    ok: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300',
    progress: 'bg-blue-50 text-blue-700 dark:bg-blue-950/40 dark:text-blue-300',
    refund: 'bg-violet-50 text-violet-700 dark:bg-violet-950/40 dark:text-violet-300',
    warn: 'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300',
    muted: 'bg-neutral-100 text-neutral-500 dark:bg-neutral-800 dark:text-neutral-400',
};

function toneOf(status: string, isFinal: boolean): Tone {
    if (status === 'delivered') {
        return 'ok';
    }

    if (status === 'refunded' || status === 'refunding') {
        return 'refund';
    }

    if (status === 'payment_failed') {
        return 'muted';
    }

    return isFinal || status === 'refund_failed' ? 'warn' : 'progress';
}

function formatMoney({ amount, currency }: Money): string {
    const [whole, fraction] = amount.split('.');

    return `${currency} ${Number(whole).toLocaleString('en-US')}${fraction ? `.${fraction}` : ''}`;
}

function Row({
    label,
    children,
    mono = false,
}: {
    label: string;
    children: ReactNode;
    mono?: boolean;
}) {
    return (
        <div className="flex justify-between gap-4 py-1">
            <dt className="shrink-0 text-neutral-500 dark:text-neutral-400">
                {label}
            </dt>
            <dd
                className={cn(
                    'text-right [overflow-wrap:anywhere] tabular-nums',
                    mono && 'font-mono text-[12.5px]',
                )}
            >
                {children}
            </dd>
        </div>
    );
}

function Group({ title, children }: { title: string; children: ReactNode }) {
    return (
        <div className="mt-5 first:mt-0">
            <h2 className="mb-2 text-[11px] font-bold tracking-[0.07em] text-neutral-400 uppercase">
                {title}
            </h2>
            <dl className="text-sm">{children}</dl>
        </div>
    );
}

/** A tear-off edge, like a printed receipt. */
function Tear() {
    return (
        <div
            aria-hidden
            className="relative mx-[18px] h-0 shrink-0 border-t-2 border-dashed border-neutral-200 before:absolute before:top-[-12px] before:left-[-31px] before:h-[22px] before:w-[22px] before:rounded-full before:border before:border-neutral-200 before:bg-neutral-100 before:[clip-path:inset(0_0_0_50%)] after:absolute after:top-[-12px] after:right-[-31px] after:h-[22px] after:w-[22px] after:rounded-full after:border after:border-neutral-200 after:bg-neutral-100 after:[clip-path:inset(0_50%_0_0)] dark:border-neutral-800 dark:before:border-neutral-800 dark:before:bg-neutral-950 dark:after:border-neutral-800 dark:after:bg-neutral-950"
        />
    );
}

function StatusIcon({ tone }: { tone: Tone }) {
    const icon = {
        ok: <Check className="h-6 w-6" strokeWidth={2.5} />,
        progress: <Clock className="h-6 w-6" strokeWidth={2.2} />,
        refund: <Undo2 className="h-6 w-6" strokeWidth={2.2} />,
        warn: <Clock className="h-6 w-6" strokeWidth={2.2} />,
        muted: <X className="h-6 w-6" strokeWidth={2.2} />,
    }[tone];

    return (
        <div
            aria-hidden
            className={cn(
                'mx-auto flex h-[52px] w-[52px] items-center justify-center rounded-full',
                TONES[tone],
            )}
        >
            {icon}
        </div>
    );
}

/** The payment's journey: paid with PayPal, then the merchant paid (or a refund). */
function Steps({ steps }: { steps: Receipt['steps'] }) {
    return (
        <ol className="space-y-2.5" data-test="receipt-steps">
            {steps.map((step) => (
                <li
                    key={step.label}
                    className="flex items-center gap-3 text-sm"
                >
                    <span
                        className={cn(
                            'flex h-5 w-5 shrink-0 items-center justify-center rounded-full',
                            step.state === 'done' &&
                                'bg-emerald-600 text-white dark:bg-emerald-500',
                            step.state === 'current' &&
                                'border-2 border-blue-600 dark:border-blue-400',
                            step.state === 'upcoming' &&
                                'border-2 border-neutral-200 dark:border-neutral-700',
                            step.state === 'failed' &&
                                'bg-neutral-300 text-white dark:bg-neutral-700',
                        )}
                    >
                        {step.state === 'done' && (
                            <Check className="h-3 w-3" strokeWidth={3} />
                        )}
                        {step.state === 'current' && (
                            <span className="h-2 w-2 animate-pulse rounded-full bg-blue-600 dark:bg-blue-400" />
                        )}
                        {step.state === 'failed' && (
                            <X className="h-3 w-3" strokeWidth={3} />
                        )}
                    </span>
                    <span
                        className={cn(
                            step.state === 'upcoming' && 'text-neutral-400',
                            step.state === 'failed' &&
                                'text-neutral-400 line-through',
                            step.state === 'current' && 'font-semibold',
                        )}
                    >
                        {step.label}
                    </span>
                </li>
            ))}
        </ol>
    );
}

/**
 * The customer's receipt after paying a merchant, updated live until the
 * merchant has been paid (or the customer refunded). Laid out like the
 * PayPal checkout receipt: the brand header and the actions stay put while
 * the details scroll between them.
 */
export default function PayReceipt({ receipt }: { receipt: Receipt }) {
    const { start, stop } = usePoll(
        4000,
        { only: ['receipt'] },
        { autoStart: false },
    );

    useEffect(() => {
        if (receipt.is_final) {
            stop();
        } else {
            start();
        }

        return stop;
    }, [receipt.is_final, start, stop]);

    const tone = toneOf(receipt.status, receipt.is_final);
    const paypal = receipt.paypal;
    const business = receipt.merchant ?? 'MoneyUnify';
    const paidAt = paypal?.paid_at ?? receipt.created_at;
    const refunded = paypal?.refunded
        ? { amount: paypal.refunded, currency: paypal.currency }
        : null;

    return (
        <>
            <Head title={`Receipt ${receipt.number} · ${business}`} />
            <div className="flex min-h-svh flex-col items-center justify-center bg-neutral-100 px-4 py-5 sm:py-8 dark:bg-neutral-950 print:bg-white print:py-0">
                <div className="flex max-h-[calc(100dvh-2.5rem)] w-full max-w-[440px] flex-col print:max-h-none [@media(max-height:640px)]:max-h-none">
                    <main
                        className="relative flex min-h-0 flex-1 flex-col rounded-[18px] border border-neutral-200 bg-white text-neutral-900 card-stack dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-100 print:shadow-none"
                        data-test="pay-receipt"
                    >
                        {/* Header: stays put */}
                        <div className="flex shrink-0 items-center justify-between gap-3 px-6 pt-5 pb-4">
                            <div className="flex min-w-0 items-center gap-2.5">
                                <div
                                    aria-hidden
                                    className="flex h-9 w-9 shrink-0 items-center justify-center rounded-[10px] bg-[#2d6fbe] font-bold text-white"
                                >
                                    {business.charAt(0).toUpperCase()}
                                </div>
                                <div className="min-w-0">
                                    <div className="truncate font-semibold">
                                        {business}
                                    </div>
                                    <div className="text-xs text-neutral-500">
                                        Payment receipt
                                    </div>
                                </div>
                            </div>
                            <div className="text-right text-xs leading-snug text-neutral-500">
                                <div>
                                    No.{' '}
                                    <span className="font-mono">
                                        {receipt.number}
                                    </span>
                                </div>
                                {paidAt && (
                                    <div>
                                        {new Date(paidAt).toLocaleDateString(
                                            undefined,
                                            {
                                                day: 'numeric',
                                                month: 'short',
                                                year: 'numeric',
                                            },
                                        )}
                                    </div>
                                )}
                            </div>
                        </div>

                        {/* Details: scroll between the header and the actions */}
                        <div className="min-h-0 flex-1 overflow-x-hidden overflow-y-auto print:overflow-visible [@media(max-height:640px)]:overflow-visible">
                            <div className="px-6 pt-2 pb-5 text-center">
                                <StatusIcon tone={tone} />
                                <h1 className="mt-3 text-lg font-semibold">
                                    {receipt.headline}
                                </h1>
                                <p className="mx-auto mt-1 max-w-xs text-sm text-neutral-500 dark:text-neutral-400">
                                    {receipt.detail}
                                </p>
                                <div className="mt-4 text-[34px] font-bold tracking-tight tabular-nums">
                                    {formatMoney(receipt.paid)}
                                </div>
                                {paypal && (
                                    <div className="mt-3 inline-flex flex-wrap items-center justify-center gap-1.5">
                                        <span className="inline-flex items-center gap-1.5 rounded-full bg-[#eef4fb] px-3 py-1 text-xs font-semibold text-[#003087] dark:bg-[#0f1a2b] dark:text-[#93c5fd]">
                                            <img
                                                src="/paypal.png"
                                                alt=""
                                                className="h-3.5 w-3.5 object-contain"
                                            />
                                            Paid with PayPal
                                            <span className="text-emerald-700 dark:text-emerald-300">
                                                · ✓ Verified with PayPal
                                            </span>
                                        </span>
                                        {paypal.sandbox && (
                                            <span className="inline-flex items-center gap-1.5 rounded-full border border-neutral-200 bg-neutral-50 px-2.5 py-0.5 text-[11px] font-medium text-neutral-600 dark:border-neutral-800 dark:bg-neutral-800/60 dark:text-neutral-400">
                                                <span className="h-1.5 w-1.5 rounded-full bg-amber-500 ring-2 ring-amber-500/20" />
                                                Sandbox · test payment
                                            </span>
                                        )}
                                    </div>
                                )}
                            </div>

                            <Tear />

                            <div className="px-6 py-5">
                                <Group title="Progress">
                                    <Steps steps={receipt.steps} />
                                </Group>

                                {paypal && (
                                    <Group title="Payment">
                                        <div data-test="paypal-receipt">
                                            <Row label="Status">
                                                <span
                                                    className={cn(
                                                        'rounded-full px-2 py-0.5 text-[11px] font-semibold',
                                                        TONES[
                                                            refunded
                                                                ? 'refund'
                                                                : 'ok'
                                                        ],
                                                    )}
                                                >
                                                    {paypal.status}
                                                </span>
                                            </Row>
                                            {paypal.paid_at && (
                                                <Row label="Paid on">
                                                    {new Date(
                                                        paypal.paid_at,
                                                    ).toLocaleString()}
                                                </Row>
                                            )}
                                            <Row
                                                label="PayPal transaction ID"
                                                mono
                                            >
                                                {paypal.paypal_transaction_id}
                                            </Row>
                                            <Row label="PayPal order ID" mono>
                                                {paypal.paypal_order_id}
                                            </Row>
                                            {paypal.seller_protection && (
                                                <Row label="Seller protection">
                                                    {paypal.seller_protection}
                                                </Row>
                                            )}
                                        </div>
                                    </Group>
                                )}

                                {paypal &&
                                    (paypal.payer.name ||
                                        paypal.payer.email) && (
                                        <Group title="Paid by">
                                            {paypal.payer.name && (
                                                <Row label="Name">
                                                    {paypal.payer.name}
                                                </Row>
                                            )}
                                            {paypal.payer.email && (
                                                <Row
                                                    label="PayPal account"
                                                    mono
                                                >
                                                    {paypal.payer.email}
                                                </Row>
                                            )}
                                            {paypal.payer.verified && (
                                                <Row label="Account">
                                                    <span className="font-semibold text-emerald-700 dark:text-emerald-300">
                                                        ✓ Verified PayPal
                                                        account
                                                    </span>
                                                </Row>
                                            )}
                                        </Group>
                                    )}

                                <Group title="Paid to">
                                    <Row label="Business">{business}</Row>
                                    <Row label="Price">
                                        {formatMoney(receipt.price)}
                                    </Row>
                                    <Row label="Reference" mono>
                                        {receipt.reference}
                                    </Row>
                                </Group>

                                <dl className="mt-5 rounded-xl bg-neutral-50 px-3.5 py-3 text-sm dark:bg-neutral-800/60">
                                    <Row label="Amount paid">
                                        {formatMoney(receipt.paid)}
                                    </Row>
                                    {refunded && (
                                        <Row label="Refunded">
                                            − {formatMoney(refunded)}
                                        </Row>
                                    )}
                                </dl>
                            </div>
                        </div>

                        {/* Actions: stay put */}
                        {paypal && (
                            <>
                                <Tear />
                                <div className="shrink-0 px-6 pt-4 pb-5">
                                    <p className="text-center text-xs text-neutral-500 dark:text-neutral-400">
                                        PayPal has also emailed your official
                                        receipt. Find it in your{' '}
                                        <a
                                            href={paypal.activity_url}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="font-semibold text-[#003087] underline dark:text-[#93c5fd]"
                                        >
                                            PayPal activity
                                        </a>{' '}
                                        under the transaction ID above.
                                    </p>
                                    <div className="mt-3 flex flex-wrap justify-center gap-2 print:hidden">
                                        <button
                                            type="button"
                                            onClick={() => window.print()}
                                            className="inline-flex cursor-pointer items-center gap-1.5 rounded-full border border-neutral-200 px-3.5 py-1.5 text-[12.5px] font-semibold hover:bg-neutral-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
                                        >
                                            <Printer className="h-3.5 w-3.5" />
                                            Print or save as PDF
                                        </button>
                                        <a
                                            href={paypal.receipt_url}
                                            className="inline-flex items-center gap-1.5 rounded-full border border-neutral-200 px-3.5 py-1.5 text-[12.5px] font-semibold hover:bg-neutral-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
                                        >
                                            <Link2 className="h-3.5 w-3.5" />
                                            PayPal receipt
                                        </a>
                                    </div>
                                </div>
                            </>
                        )}
                    </main>

                    <footer className="mt-4 shrink-0 text-center text-[11px] text-neutral-400">
                        Powered by MoneyUnify · PayPal to mobile money
                        {!receipt.is_final && (
                            <span className="print:hidden">
                                {' '}
                                · this page updates on its own
                            </span>
                        )}
                    </footer>
                </div>
            </div>
        </>
    );
}
