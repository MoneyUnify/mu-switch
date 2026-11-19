import { Head, router, useForm } from '@inertiajs/react';
import {
    ArrowDown,
    CreditCard,
    Loader2,
    Lock,
    ShieldCheck,
    Store,
} from 'lucide-react';
import type { FormEvent } from 'react';
import { useEffect, useRef, useState } from 'react';
import InputError from '@/components/input-error';
import { PaypalSdkButton } from '@/components/paypal-sdk-button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { csrfToken } from '@/lib/csrf';
import { countryFlag } from '@/lib/flags';
import { cn } from '@/lib/utils';
import pay from '@/routes/pay';
import payLink, { order as linkOrder } from '@/routes/pay/link';

interface Money {
    amount: string;
    currency: string;
}

interface Quote {
    customer_pays: Money;
    merchant_price: Money;
    merchant_receives: Money;
    fx: { effective_rate: string };
    expires_at: string;
}

interface Props {
    merchant: {
        name: string;
        slug: string;
        country: string;
        country_name: string;
        currency: string;
        is_active: boolean;
    };
    link: { code: string; label: string | null; fixed_amount: boolean } | null;
    payCurrencies: string[];
    settleCurrency: string;
    prefill: { amount: string | null; reference: string | null };
    paypalSdk: { client_id: string; environment: 'sandbox' | 'live' } | null;
}

function formatMoney({ amount, currency }: Money): string {
    const [whole, fraction] = amount.split('.');

    return `${currency} ${Number(whole).toLocaleString('en-US')}${fraction ? `.${fraction}` : ''}`;
}

/**
 * A merchant's public pay page: pay with PayPal from anywhere, and the
 * merchant is paid in its own currency to its mobile-money wallet.
 */
export default function PayMerchant({
    merchant,
    link,
    payCurrencies,
    settleCurrency,
    prefill,
    paypalSdk,
}: Props) {
    const form = useForm({
        amount: prefill.amount ?? '',
        mode: 'target' as 'source' | 'target',
        source_currency: payCurrencies.includes('USD')
            ? 'USD'
            : (payCurrencies[0] ?? 'USD'),
        payer_email: '',
        reference: prefill.reference ?? '',
        settle_currency: settleCurrency,
    });
    const { amount, mode, source_currency: payIn } = form.data;
    const [quote, setQuote] = useState<Quote | null>(null);
    const [quoteError, setQuoteError] = useState<string | null>(null);
    const [isQuoting, setIsQuoting] = useState(false);
    const [payingWith, setPayingWith] = useState<'paypal' | 'card'>('paypal');
    // True only while the approved payment is being captured: the button
    // stays usable while PayPal's window is open, so a closed or blocked
    // window never leaves the customer stuck.
    const [capturing, setCapturing] = useState(false);
    const [sdkError, setSdkError] = useState<string | null>(null);
    const captureUrl = useRef<string | null>(null);
    const canPay = merchant.is_active && payCurrencies.length > 0;
    const fixed = link?.fixed_amount ?? false;
    const quoteUrl = link
        ? payLink.quote.url(link.code)
        : pay.quote.url(merchant.slug);

    useEffect(() => {
        if (!canPay || !(Number(amount) > 0)) {
            return;
        }

        const controller = new AbortController();
        const timer = setTimeout(async () => {
            setIsQuoting(true);

            try {
                const response = await fetch(quoteUrl, {
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
                        source_currency: payIn,
                        settle_currency: settleCurrency,
                    }),
                });
                const json = await response.json();

                setQuote(response.ok ? (json.data as Quote) : null);
                setQuoteError(
                    response.ok
                        ? null
                        : (json.message ?? 'This amount cannot be paid.'),
                );
            } catch (error) {
                if ((error as Error).name !== 'AbortError') {
                    setQuote(null);
                    setQuoteError('Could not get a price. Please try again.');
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
    }, [canPay, amount, mode, payIn, settleCurrency, quoteUrl]);

    const shownQuote = Number(amount) > 0 ? quote : null;

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        // Both buttons go through PayPal; "card" opens its guest card form.
        const submitter = (event.nativeEvent as SubmitEvent)
            .submitter as HTMLButtonElement | null;
        const payWith = submitter?.value === 'card' ? 'card' : 'paypal';

        setPayingWith(payWith);
        form.transform((data) => ({ ...data, pay_with: payWith }));
        form.post(
            link ? payLink.store.url(link.code) : pay.store.url(merchant.slug),
        );
    };

    // PayPal's JS SDK button: the order is made here, approved in PayPal's
    // window, then captured here before showing the receipt.
    const createSdkOrder = async (): Promise<string> => {
        setSdkError(null);

        const response = await fetch(
            link ? linkOrder.url(link.code) : pay.order.url(merchant.slug),
            {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({ ...form.data, pay_with: 'paypal' }),
            },
        );
        const json = await response.json().catch(() => ({}));

        if (!response.ok) {
            const message =
                json.message ?? 'PayPal could not start the payment.';
            setSdkError(message);

            throw new Error(message);
        }

        // Made with another PayPal account than the button's: pay on PayPal's page instead.
        if (json.data.client_id !== paypalSdk?.client_id) {
            window.location.href = json.data.checkout_url;

            throw new Error('Continuing on PayPal…');
        }

        captureUrl.current = json.data.capture_url;

        return json.data.order_id as string;
    };

    const captureSdkOrder = async () => {
        setCapturing(true);

        try {
            const response = await fetch(captureUrl.current ?? '', {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
            });
            const json = await response.json().catch(() => ({}));

            if (!response.ok) {
                setSdkError(
                    json.message ?? 'PayPal could not confirm the payment.',
                );
                setCapturing(false);

                return;
            }

            router.visit(json.data.receipt_url);
        } catch {
            setSdkError('PayPal could not confirm the payment.');
            setCapturing(false);
        }
    };

    const classicPaypalButton = (
        <button
            type="submit"
            value="paypal"
            disabled={!shownQuote || form.processing}
            className="flex h-12 w-full cursor-pointer items-center justify-center gap-2 rounded-full bg-[#FFC439] text-sm font-semibold text-[#111] transition-opacity hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-50"
        >
            {form.processing && payingWith === 'paypal' ? (
                <Loader2 className="h-4 w-4 animate-spin" />
            ) : (
                <img
                    src="/paypal.png"
                    alt=""
                    className="h-4 w-4 object-contain"
                />
            )}
            {shownQuote
                ? `Pay ${formatMoney(shownQuote.customer_pays)} with PayPal`
                : 'Pay with PayPal'}
        </button>
    );

    return (
        <>
            <Head title={`Pay ${merchant.name}`} />
            <div className="flex min-h-svh flex-col items-center bg-neutral-50 px-4 py-10 dark:bg-neutral-950">
                <main className="w-full max-w-md">
                    <div className="rounded-2xl border border-neutral-200 bg-white p-6 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                        <div className="flex items-center gap-3">
                            <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-[#2d6fbe] text-lg font-semibold text-white shadow-xs">
                                {merchant.name.charAt(0).toUpperCase()}
                            </div>
                            <div className="min-w-0">
                                <h1 className="truncate text-base font-semibold text-neutral-900 dark:text-neutral-100">
                                    {merchant.name}
                                </h1>
                                <p className="text-xs text-neutral-500">
                                    <span aria-hidden>
                                        {countryFlag(merchant.country)}
                                    </span>{' '}
                                    {merchant.country_name} · paid in{' '}
                                    {settleCurrency}
                                    {link?.label && ` · ${link.label}`}
                                </p>
                            </div>
                        </div>

                        {!canPay ? (
                            <div className="mt-6 flex items-start gap-2.5 rounded-lg bg-neutral-100 p-3 text-sm text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300">
                                <Store className="mt-0.5 h-4 w-4 shrink-0" />
                                {merchant.is_active
                                    ? 'Online payments are not available for this merchant right now.'
                                    : 'This merchant is not accepting payments right now.'}
                            </div>
                        ) : (
                            <form onSubmit={submit} className="mt-6 space-y-4">
                                <div className="space-y-1">
                                    <div className="flex items-center justify-between">
                                        <Label htmlFor="pay-amount">
                                            {fixed ? 'Price' : 'Amount'}
                                        </Label>
                                        <div
                                            className={cn(
                                                'inline-flex rounded-full bg-neutral-100 p-0.5 text-[11px] dark:bg-neutral-800',
                                                fixed && 'hidden',
                                            )}
                                            role="radiogroup"
                                            aria-label="Amount currency"
                                        >
                                            {(
                                                [
                                                    ['target', settleCurrency],
                                                    ['source', payIn],
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
                                                        'cursor-pointer rounded-full px-2.5 py-0.5 font-medium',
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
                                            id="pay-amount"
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
                                            className={cn(
                                                'h-11 pr-16 text-base',
                                                fixed &&
                                                    'bg-neutral-50 dark:bg-neutral-800/60',
                                            )}
                                            readOnly={fixed}
                                            aria-readonly={fixed}
                                            autoFocus={!prefill.amount}
                                        />
                                        <span className="pointer-events-none absolute top-1/2 right-4 -translate-y-1/2 text-sm font-medium text-neutral-500">
                                            {mode === 'target'
                                                ? settleCurrency
                                                : payIn}
                                        </span>
                                    </div>
                                    <InputError message={form.errors.amount} />
                                </div>

                                {payCurrencies.length > 1 && (
                                    <div className="space-y-1">
                                        <Label htmlFor="pay-currency">
                                            Pay with PayPal in
                                        </Label>
                                        <select
                                            id="pay-currency"
                                            value={payIn}
                                            onChange={(e) =>
                                                form.setData(
                                                    'source_currency',
                                                    e.target.value,
                                                )
                                            }
                                            className="flex h-9 w-full rounded-full border border-input bg-transparent px-3.5 text-sm shadow-xs"
                                        >
                                            {payCurrencies.map((currency) => (
                                                <option
                                                    key={currency}
                                                    value={currency}
                                                >
                                                    {currency}
                                                </option>
                                            ))}
                                        </select>
                                    </div>
                                )}

                                <div
                                    className="rounded-xl bg-neutral-50 p-4 dark:bg-neutral-800/60"
                                    aria-live="polite"
                                    data-test="pay-quote"
                                >
                                    {shownQuote ? (
                                        <>
                                            <div className="flex items-center justify-between gap-3">
                                                <div>
                                                    <div className="text-[11px] tracking-wide text-neutral-500 uppercase">
                                                        You pay
                                                    </div>
                                                    <div className="text-xl font-semibold tabular-nums">
                                                        {formatMoney(
                                                            shownQuote.customer_pays,
                                                        )}
                                                    </div>
                                                </div>
                                                <ArrowDown className="h-4 w-4 -rotate-90 text-neutral-400" />
                                                <div className="text-right">
                                                    <div className="text-[11px] tracking-wide text-neutral-500 uppercase">
                                                        Price
                                                    </div>
                                                    <div className="text-xl font-semibold text-emerald-700 tabular-nums dark:text-emerald-400">
                                                        {formatMoney(
                                                            shownQuote.merchant_price,
                                                        )}
                                                    </div>
                                                </div>
                                            </div>
                                            <p className="mt-2 text-[11px] text-neutral-500">
                                                1{' '}
                                                {
                                                    shownQuote.customer_pays
                                                        .currency
                                                }{' '}
                                                ={' '}
                                                {Number(
                                                    shownQuote.fx
                                                        .effective_rate,
                                                ).toLocaleString('en-US', {
                                                    maximumFractionDigits: 4,
                                                })}{' '}
                                                {settleCurrency} · price held
                                                for a few minutes
                                            </p>
                                        </>
                                    ) : (
                                        <p className="flex items-center gap-2 text-xs text-neutral-500">
                                            {isQuoting ? (
                                                <>
                                                    <Loader2 className="h-3.5 w-3.5 animate-spin" />
                                                    Getting the price…
                                                </>
                                            ) : quoteError &&
                                              Number(amount) > 0 ? (
                                                <span className="text-red-600 dark:text-red-400">
                                                    {quoteError}
                                                </span>
                                            ) : (
                                                'Enter an amount to see what you pay.'
                                            )}
                                        </p>
                                    )}
                                </div>

                                <div className="grid grid-cols-2 gap-3">
                                    <div className="space-y-1">
                                        <Label htmlFor="pay-email">
                                            Email (optional)
                                        </Label>
                                        <Input
                                            id="pay-email"
                                            type="email"
                                            value={form.data.payer_email}
                                            onChange={(e) =>
                                                form.setData(
                                                    'payer_email',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                        <InputError
                                            message={form.errors.payer_email}
                                        />
                                    </div>
                                    <div className="space-y-1">
                                        <Label htmlFor="pay-reference">
                                            Reference (optional)
                                        </Label>
                                        <Input
                                            id="pay-reference"
                                            value={form.data.reference}
                                            maxLength={100}
                                            onChange={(e) =>
                                                form.setData(
                                                    'reference',
                                                    e.target.value,
                                                )
                                            }
                                            placeholder="Invoice #"
                                        />
                                    </div>
                                </div>

                                {paypalSdk ? (
                                    <PaypalSdkButton
                                        clientId={paypalSdk.client_id}
                                        environment={paypalSdk.environment}
                                        currency={payIn}
                                        disabled={
                                            !shownQuote ||
                                            form.processing ||
                                            capturing
                                        }
                                        createOrder={createSdkOrder}
                                        onApprove={captureSdkOrder}
                                        onCancel={() =>
                                            setSdkError(
                                                'Payment cancelled. You can try again.',
                                            )
                                        }
                                        onError={(error) =>
                                            setSdkError(error.message)
                                        }
                                        fallback={classicPaypalButton}
                                    />
                                ) : (
                                    classicPaypalButton
                                )}
                                {sdkError && (
                                    <p className="-mt-2 text-center text-xs text-red-600 dark:text-red-400">
                                        {sdkError}
                                    </p>
                                )}

                                <button
                                    type="submit"
                                    value="card"
                                    disabled={!shownQuote || form.processing}
                                    data-test="pay-with-card"
                                    className="-mt-1 flex h-12 w-full cursor-pointer items-center justify-center gap-2 rounded-full bg-neutral-900 text-sm font-semibold text-white transition-opacity hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-neutral-100 dark:text-neutral-900"
                                >
                                    {form.processing &&
                                    payingWith === 'card' ? (
                                        <Loader2 className="h-4 w-4 animate-spin" />
                                    ) : (
                                        <CreditCard className="h-4 w-4" />
                                    )}
                                    Debit or credit card
                                </button>

                                <p className="flex items-center justify-center gap-1.5 text-center text-[11px] text-neutral-500">
                                    <ShieldCheck className="h-3.5 w-3.5" />
                                    No PayPal account needed: PayPal charges
                                    your card securely. If the payment
                                    can&apos;t reach {merchant.name}, PayPal
                                    refunds you.
                                </p>
                            </form>
                        )}
                    </div>

                    <p className="mt-4 flex items-center justify-center gap-1.5 text-[11px] text-neutral-400">
                        <Lock className="h-3 w-3" />
                        Powered by MoneyUnify · PayPal to mobile money
                    </p>
                </main>
            </div>
        </>
    );
}
