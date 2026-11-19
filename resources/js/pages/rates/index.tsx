import { Head, router } from '@inertiajs/react';
import { Button, Select } from '@radix-ui/themes';
import {
    ArrowLeftRight,
    ArrowRight,
    Check,
    Copy,
    Landmark,
    Lock,
    Percent,
    RefreshCw,
    Search,
    X,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Popover,
    PopoverClose,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { DisabledTooltip } from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import ratesRoute from '@/routes/rates';
import paypalFxRoute from '@/routes/rates/paypal-fx';

interface RateItem {
    currency: string;
    base_rate: number;
    effective_rate: number;
    inverse_rate: number;
    decimals: number;
    margin_percent: number;
    country_code: string | null;
    country_name: string | null;
    rate_source?: 'paypal' | 'moneyunify';
}

interface RateSource {
    source: 'paypal' | 'moneyunify';
    state:
        | 'enabled'
        | 'not_enabled'
        | 'unavailable'
        | 'unknown'
        | 'off'
        | 'no_paypal';
    message: string | null;
    activation_url: string | null;
    provider: string | null;
}

interface QuoteData {
    id: string;
    source_currency: string;
    target_currency: string;
    source_amount: string;
    target_amount: string;
    base_rate: string;
    effective_rate: string;
    margin_percent: string;
    margin_amount: string;
    is_fx: boolean;
    rate_source?: 'paypal' | 'moneyunify';
    quoted_at: string;
    expires_at: string;
    expires_in_seconds: number;
    is_expired: boolean;
}

interface RatesPageProps {
    baseCurrency: string;
    rates: Record<string, RateItem>;
    marginPercent: number;
    quoteTtlMinutes: number;
    supportedCurrencies: string[];
    activeQuote?: QuoteData | null;
    rateSource: RateSource;
}

const selectClassName =
    'flex h-9 w-full rounded-full border border-input bg-transparent px-3.5 py-1 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

const CURRENCY_COUNTRY_MAP: Record<string, { code: string; name: string }> = {
    USD: { code: 'US', name: 'United States' },
    EUR: { code: 'EU', name: 'Eurozone' },
    GBP: { code: 'GB', name: 'United Kingdom' },
    CAD: { code: 'CA', name: 'Canada' },
    AUD: { code: 'AU', name: 'Australia' },
    ZMW: { code: 'ZM', name: 'Zambia' },
    KES: { code: 'KE', name: 'Kenya' },
    UGX: { code: 'UG', name: 'Uganda' },
    TZS: { code: 'TZ', name: 'Tanzania' },
    GHS: { code: 'GH', name: 'Ghana' },
    RWF: { code: 'RW', name: 'Rwanda' },
    MWK: { code: 'MW', name: 'Malawi' },
    XOF: { code: 'SN', name: 'WAEMU (Senegal, CI)' },
    XAF: { code: 'CM', name: 'CEMAC (Cameroon)' },
    ZAR: { code: 'ZA', name: 'South Africa' },
    NGN: { code: 'NG', name: 'Nigeria' },
    CDF: { code: 'CD', name: 'DR Congo' },
    MZN: { code: 'MZ', name: 'Mozambique' },
};

const CURRENCY_DETAILS: Record<
    string,
    { name: string; category: 'funding' | 'payout' }
> = {
    USD: { name: 'US Dollar', category: 'funding' },
    EUR: { name: 'Euro', category: 'funding' },
    GBP: { name: 'British Pound', category: 'funding' },
    CAD: { name: 'Canadian Dollar', category: 'funding' },
    AUD: { name: 'Australian Dollar', category: 'funding' },
    ZMW: { name: 'Zambian Kwacha', category: 'payout' },
    KES: { name: 'Kenyan Shilling', category: 'payout' },
    UGX: { name: 'Ugandan Shilling', category: 'payout' },
    TZS: { name: 'Tanzanian Shilling', category: 'payout' },
    GHS: { name: 'Ghanaian Cedi', category: 'payout' },
    RWF: { name: 'Rwandan Franc', category: 'payout' },
    MWK: { name: 'Malawian Kwacha', category: 'payout' },
    XOF: { name: 'West African CFA Franc', category: 'payout' },
    XAF: { name: 'Central African CFA Franc', category: 'payout' },
    ZAR: { name: 'South African Rand', category: 'payout' },
    NGN: { name: 'Nigerian Naira', category: 'payout' },
    CDF: { name: 'Congolese Franc', category: 'payout' },
    MZN: { name: 'Mozambican Metical', category: 'payout' },
};

/** Convert ISO-3166 alpha-2 code to emoji flag */
function flagEmoji(code: string): string {
    if (!code || code.length !== 2) {
        return '🌐';
    }

    return code
        .toUpperCase()
        .replace(/./g, (char) =>
            String.fromCodePoint(127397 + char.charCodeAt(0)),
        );
}

function formatRate(value: number, decimals: number): string {
    if (decimals === 0) {
        return Math.round(value).toLocaleString();
    }

    const maxDigits = Math.max(2, Math.min(8, decimals));

    return value.toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: maxDigits,
    });
}

function StatCard({
    title,
    value,
    icon,
    footer,
}: {
    title: string;
    value: ReactNode;
    icon: ReactNode;
    footer: ReactNode;
}) {
    return (
        <Card className="gap-0 border border-sidebar-border/70 p-5 dark:border-sidebar-border">
            <div className="flex items-center justify-between">
                <span className="text-sm font-medium text-neutral-500 dark:text-neutral-400">
                    {title}
                </span>
                <span className="text-neutral-400">{icon}</span>
            </div>
            <div className="mt-3 text-xl font-bold tracking-tight tabular-nums">
                {value}
            </div>
            <div className="mt-2 flex items-center gap-1.5 text-xs text-neutral-500 dark:text-neutral-400">
                {footer}
            </div>
        </Card>
    );
}

const QUOTE_STORAGE_KEY = 'moneyunify_active_fx_quote';

/**
 * The quote to show first: the server's active quote, else an unexpired one
 * kept in this tab's session storage.
 */
function restoreQuote(serverQuote: QuoteData | null): QuoteData | null {
    if (serverQuote && !serverQuote.is_expired) {
        return serverQuote;
    }

    try {
        const stored = sessionStorage.getItem(QUOTE_STORAGE_KEY);

        if (stored) {
            const parsed: QuoteData = JSON.parse(stored);

            if (new Date(parsed.expires_at).getTime() > Date.now()) {
                return parsed;
            }
        }
    } catch {
        // Ignore storage errors
    }

    return null;
}

function CorridorQuotePopover({
    baseCurrency,
    rate,
    marginPercent,
    quoteTtlMinutes,
    defaultAmount,
    onOpenInCalculator,
}: {
    baseCurrency: string;
    rate: RateItem;
    marginPercent: number;
    quoteTtlMinutes: number;
    defaultAmount: string;
    onOpenInCalculator: (from: string, to: string, amount: string) => void;
}) {
    const [isOpen, setIsOpen] = useState(false);
    const [popAmount, setPopAmount] = useState(defaultAmount || '100');
    const [lockedQuote, setLockedQuote] = useState<QuoteData | null>(null);
    const [loading, setLoading] = useState(false);
    const [copied, setCopied] = useState(false);
    const [remainingSeconds, setRemainingSeconds] = useState(
        quoteTtlMinutes * 60,
    );

    const countryInfo = CURRENCY_COUNTRY_MAP[rate.currency];
    const countryCode =
        rate.country_code || countryInfo?.code || rate.currency.slice(0, 2);
    const baseCountryCode = CURRENCY_COUNTRY_MAP[baseCurrency]?.code || 'US';

    // Each time the popover opens, it starts from the page's amount.
    const handleOpenChange = (open: boolean) => {
        if (open) {
            setPopAmount(defaultAmount || '100');
        }

        setIsOpen(open);
    };

    useEffect(() => {
        if (!isOpen || !lockedQuote) {
            return;
        }

        const tick = () => {
            const exp = new Date(lockedQuote.expires_at).getTime();
            const now = Date.now();
            const diff = Math.max(0, Math.floor((exp - now) / 1000));
            setRemainingSeconds(diff);
        };

        const first = setTimeout(tick, 0);
        const interval = setInterval(tick, 1000);

        return () => {
            clearTimeout(first);
            clearInterval(interval);
        };
    }, [isOpen, lockedQuote]);

    const requestQuote = async (amt: string) => {
        const num = parseFloat(amt);

        if (isNaN(num) || num <= 0) {
            return;
        }

        setLoading(true);

        try {
            const response = await fetch(ratesRoute.quote.url(), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN':
                        (
                            document.querySelector(
                                'meta[name="csrf-token"]',
                            ) as HTMLMetaElement
                        )?.content || '',
                },
                body: JSON.stringify({
                    from_currency: baseCurrency,
                    to_currency: rate.currency,
                    amount: amt,
                    mode: 'source',
                }),
            });

            const json = await response.json();

            if (response.ok && (json.status === 'success' || json.success)) {
                setLockedQuote(json.data);
            }
        } catch {
            // Keep instant calculation on failure
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        if (!isOpen) {
            return;
        }

        const timer = setTimeout(() => requestQuote(popAmount), 0);

        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [isOpen, baseCurrency, rate.currency]);

    const numAmt = parseFloat(popAmount) || 0;
    const estTarget = numAmt * rate.effective_rate;
    const displayTarget = lockedQuote
        ? lockedQuote.target_amount
        : formatRate(estTarget, rate.decimals);
    const displayBaseRate = lockedQuote
        ? lockedQuote.base_rate
        : formatRate(rate.base_rate, rate.decimals);
    const displayEffectiveRate = lockedQuote
        ? lockedQuote.effective_rate
        : formatRate(rate.effective_rate, rate.decimals);
    const spreadDeduction =
        numAmt > 0 ? (numAmt * (marginPercent / 100)).toFixed(2) : '0.00';

    const copyQuoteRef = (id: string) => {
        navigator.clipboard.writeText(id);
        setCopied(true);
        toast.success('Quote reference copied');
        setTimeout(() => setCopied(false), 2000);
    };

    return (
        <Popover open={isOpen} onOpenChange={handleOpenChange}>
            <PopoverTrigger asChild>
                <Button
                    variant="soft"
                    color="gray"
                    size="1"
                    className="cursor-pointer"
                >
                    Quote
                </Button>
            </PopoverTrigger>
            <PopoverContent
                align="end"
                sideOffset={8}
                className="w-84 rounded-xl border border-sidebar-border/70 bg-popover p-4 text-xs shadow-2xl sm:w-92 dark:border-sidebar-border"
            >
                <div className="flex items-center justify-between border-b border-sidebar-border/50 pb-3 dark:border-sidebar-border/70">
                    <div className="flex items-center gap-2">
                        <span className="text-base leading-none">
                            {flagEmoji(baseCountryCode)}
                        </span>
                        <ArrowRight className="h-3 w-3 text-neutral-400" />
                        <span className="text-base leading-none">
                            {flagEmoji(countryCode)}
                        </span>
                        <span className="font-semibold text-neutral-900 dark:text-neutral-100">
                            {baseCurrency} → {rate.currency}
                        </span>
                    </div>
                    <div className="flex items-center gap-2">
                        {lockedQuote && remainingSeconds > 0 ? (
                            <span className="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-medium text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300">
                                <span className="relative flex h-1.5 w-1.5">
                                    <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75" />
                                    <span className="relative inline-flex h-1.5 w-1.5 rounded-full bg-emerald-500" />
                                </span>
                                <span>
                                    {Math.floor(remainingSeconds / 60)}:
                                    {(remainingSeconds % 60)
                                        .toString()
                                        .padStart(2, '0')}
                                </span>
                            </span>
                        ) : (
                            <span className="inline-flex items-center gap-1 rounded-full bg-blue-50 px-2 py-0.5 text-[11px] font-medium text-blue-700 dark:bg-blue-950/40 dark:text-blue-300">
                                Live Rate
                            </span>
                        )}
                        <PopoverClose className="cursor-pointer rounded p-0.5 text-neutral-400 hover:text-neutral-700 dark:hover:text-neutral-200">
                            <X className="h-3.5 w-3.5" />
                        </PopoverClose>
                    </div>
                </div>

                <div className="mt-3">
                    <div className="mb-1 flex items-center justify-between text-neutral-500 dark:text-neutral-400">
                        <span>Send amount ({baseCurrency})</span>
                        <span className="text-[10px] text-neutral-400">
                            Guaranteed {quoteTtlMinutes} min
                        </span>
                    </div>
                    <div className="flex items-center gap-1.5">
                        <Input
                            type="number"
                            step="any"
                            min="0.01"
                            value={popAmount}
                            onChange={(e) => setPopAmount(e.target.value)}
                            onKeyDown={(e) =>
                                e.key === 'Enter' && requestQuote(popAmount)
                            }
                            className="h-8 text-xs tabular-nums"
                        />
                        <Button
                            size="1"
                            variant="surface"
                            onClick={() => requestQuote(popAmount)}
                            disabled={loading}
                            className="h-8 shrink-0 cursor-pointer gap-1"
                        >
                            <RefreshCw
                                className={cn(
                                    'h-3 w-3',
                                    loading && 'animate-spin',
                                )}
                            />
                            Refresh
                        </Button>
                    </div>
                </div>

                <div className="mt-3 rounded-lg border border-sidebar-border/40 bg-neutral-50 p-3 dark:border-sidebar-border/60 dark:bg-neutral-900/60">
                    <div className="text-[11px] text-neutral-500 dark:text-neutral-400">
                        Recipient receives
                    </div>
                    <div className="mt-0.5 flex items-baseline gap-1.5">
                        <span className="text-xl font-bold tracking-tight text-neutral-900 tabular-nums dark:text-neutral-100">
                            {displayTarget}
                        </span>
                        <span className="text-xs font-semibold text-neutral-700 dark:text-neutral-300">
                            {rate.currency}
                        </span>
                    </div>
                    <p className="mt-0.5 text-[11px] text-neutral-500 dark:text-neutral-400">
                        for {popAmount} {baseCurrency} at {displayEffectiveRate}
                    </p>

                    <dl className="mt-2.5 space-y-1 border-t border-sidebar-border/50 pt-2 text-[11px] dark:border-sidebar-border/70">
                        <div className="flex items-center justify-between">
                            <dt className="text-neutral-500 dark:text-neutral-400">
                                Mid-market rate
                            </dt>
                            <dd className="text-neutral-700 tabular-nums dark:text-neutral-300">
                                1 {baseCurrency} = {displayBaseRate}{' '}
                                {rate.currency}
                            </dd>
                        </div>
                        <div className="flex items-center justify-between">
                            <dt className="text-neutral-500 dark:text-neutral-400">
                                Spread ({marginPercent}%)
                            </dt>
                            <dd className="text-neutral-700 tabular-nums dark:text-neutral-300">
                                −{' '}
                                {lockedQuote
                                    ? lockedQuote.margin_amount
                                    : spreadDeduction}{' '}
                                {baseCurrency}
                            </dd>
                        </div>
                        <div className="flex items-center justify-between border-t border-sidebar-border/40 pt-1 font-semibold text-neutral-900 dark:text-neutral-100">
                            <dt>Effective rate</dt>
                            <dd className="tabular-nums">
                                1 {baseCurrency} = {displayEffectiveRate}{' '}
                                {rate.currency}
                            </dd>
                        </div>
                    </dl>
                </div>

                <div className="mt-3 flex items-center justify-between border-t border-sidebar-border/50 pt-2.5 text-[11px] text-neutral-500 dark:border-sidebar-border/70 dark:text-neutral-400">
                    {lockedQuote ? (
                        <button
                            type="button"
                            onClick={() => copyQuoteRef(lockedQuote.id)}
                            className="flex cursor-pointer items-center gap-1 font-mono text-[11px] text-neutral-500 hover:text-neutral-900 dark:text-neutral-400 dark:hover:text-neutral-100"
                        >
                            {copied ? (
                                <Check className="h-3 w-3 text-emerald-500" />
                            ) : (
                                <Copy className="h-3 w-3" />
                            )}
                            Ref: {lockedQuote.id.slice(0, 8)}…
                        </button>
                    ) : (
                        <span className="text-neutral-400">
                            Guaranteed {quoteTtlMinutes} min
                        </span>
                    )}
                    <button
                        type="button"
                        onClick={() => {
                            setIsOpen(false);
                            onOpenInCalculator(
                                baseCurrency,
                                rate.currency,
                                popAmount,
                            );
                        }}
                        className="flex cursor-pointer items-center gap-1 font-medium text-primary hover:underline"
                    >
                        Open in calculator
                        <ArrowRight className="h-3 w-3" />
                    </button>
                </div>
            </PopoverContent>
        </Popover>
    );
}

/** PayPal's guide to FX as a Service: who can get it and how. */
const PAYPAL_FX_GUIDE =
    'https://developer.paypal.com/docs/checkout/fx-as-a-service/';

/**
 * Where this account's rates come from: PayPal's FX when its PayPal business
 * account has FX as a Service, otherwise MoneyUnify's rate table. PayPal
 * enables FX for the business account, so the guide comes first; PayPal's
 * own activation page is offered once the user says FX is already on.
 */
function PaypalFxNotice({ rateSource }: { rateSource: RateSource }) {
    const [alreadyEnabled, setAlreadyEnabled] = useState(false);
    const [checking, setChecking] = useState(false);

    if (rateSource.state === 'no_paypal') {
        return null;
    }

    if (rateSource.source === 'paypal') {
        return (
            <div
                className="flex items-center gap-3 rounded-xl border border-[#cfe0f5] bg-[#eef4fb] px-4 py-3 text-xs text-[#003087] dark:border-[#1e3a5f] dark:bg-[#0f1a2b] dark:text-[#93c5fd]"
                data-test="paypal-fx-notice"
            >
                <img
                    src="/paypal.png"
                    alt=""
                    className="h-5 w-5 object-contain"
                />
                <p>
                    <span className="font-semibold">Rates from PayPal FX.</span>{' '}
                    Quotes use PayPal&apos;s own exchange rates through{' '}
                    {rateSource.provider}, plus your spread. Payments in another
                    currency are converted by PayPal at a locked rate.
                </p>
            </div>
        );
    }

    const checkAgain = () =>
        router.post(
            paypalFxRoute.check.url(),
            {},
            {
                preserveScroll: true,
                onStart: () => setChecking(true),
                onFinish: () => setChecking(false),
            },
        );

    if (rateSource.state === 'off') {
        return (
            <div
                className="rounded-xl border border-sidebar-border/70 bg-neutral-50 px-4 py-3 text-xs text-neutral-600 dark:border-sidebar-border dark:bg-neutral-900/50 dark:text-neutral-400"
                data-test="paypal-fx-notice"
            >
                <span className="font-semibold text-neutral-800 dark:text-neutral-200">
                    Using MoneyUnify rates.
                </span>{' '}
                {rateSource.provider} is set to MoneyUnify&apos;s rates. Set its{' '}
                <span className="font-medium">Exchange rates</span> to paypal on
                the Providers page to use PayPal FX.
            </div>
        );
    }

    return (
        <div
            className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-200"
            data-test="paypal-fx-notice"
        >
            <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <p>
                    <span className="font-semibold">
                        {rateSource.state === 'unavailable'
                            ? 'PayPal FX is unavailable right now.'
                            : 'Use PayPal’s exchange rates.'}
                    </span>{' '}
                    {rateSource.state === 'unavailable'
                        ? "Quotes use MoneyUnify's rates until PayPal answers again."
                        : `PayPal enables FX as a Service for the business account behind ${rateSource.provider} (ask PayPal or your account manager). Once it's on, quotes switch to PayPal's rates automatically; until then, MoneyUnify's rates are used.`}
                </p>
                <div className="flex shrink-0 items-center gap-2">
                    <a
                        href={PAYPAL_FX_GUIDE}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="rounded-full bg-amber-900 px-3 py-1.5 text-center font-semibold text-amber-50 hover:opacity-90 dark:bg-amber-200 dark:text-amber-950"
                    >
                        How to get PayPal FX
                    </a>
                    {!alreadyEnabled && (
                        <button
                            type="button"
                            onClick={() => setAlreadyEnabled(true)}
                            className="cursor-pointer rounded-full border border-amber-300 px-3 py-1.5 font-semibold hover:bg-amber-100 dark:border-amber-800 dark:hover:bg-amber-900/40"
                        >
                            It&apos;s already enabled
                        </button>
                    )}
                </div>
            </div>

            {alreadyEnabled && (
                <div
                    className="mt-3 flex flex-col gap-2 border-t border-amber-200 pt-3 sm:flex-row sm:items-center sm:justify-between dark:border-amber-900/50"
                    data-test="paypal-fx-enabled-help"
                >
                    <p>
                        If PayPal has enabled FX as a Service for this business
                        account, check again. If PayPal still says it isn&apos;t
                        on, open PayPal&apos;s FX page while signed in to that
                        business account.
                    </p>
                    <div className="flex shrink-0 items-center gap-2">
                        {rateSource.activation_url && (
                            <a
                                href={rateSource.activation_url}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="rounded-full border border-amber-300 px-3 py-1.5 text-center font-semibold hover:bg-amber-100 dark:border-amber-800 dark:hover:bg-amber-900/40"
                            >
                                Open PayPal&apos;s FX page
                            </a>
                        )}
                        <button
                            type="button"
                            onClick={checkAgain}
                            disabled={checking}
                            className="inline-flex cursor-pointer items-center gap-1.5 rounded-full bg-amber-900 px-3 py-1.5 font-semibold text-amber-50 hover:opacity-90 disabled:opacity-60 dark:bg-amber-200 dark:text-amber-950"
                        >
                            <RefreshCw
                                className={cn(
                                    'h-3 w-3',
                                    checking && 'animate-spin',
                                )}
                            />
                            Check again
                        </button>
                    </div>
                </div>
            )}
        </div>
    );
}

export default function RatesIndexPage({
    baseCurrency,
    rates,
    marginPercent,
    quoteTtlMinutes,
    supportedCurrencies,
    activeQuote: serverActiveQuote,
    rateSource,
}: RatesPageProps) {
    const [initialQuote] = useState(() =>
        restoreQuote(serverActiveQuote ?? null),
    );
    const [search, setSearch] = useState('');
    const [fromCurrency, setFromCurrency] = useState(
        initialQuote?.source_currency ?? baseCurrency,
    );
    const [toCurrency, setToCurrency] = useState(
        initialQuote?.target_currency ?? 'ZMW',
    );
    const [amount, setAmount] = useState(initialQuote?.source_amount ?? '100');
    const [mode, setMode] = useState<'source' | 'target'>('source');
    const [activeQuote, setActiveQuote] = useState<QuoteData | null>(
        initialQuote,
    );
    const [remainingSeconds, setRemainingSeconds] = useState<number>(0);
    const [isQuoting, setIsQuoting] = useState(false);
    const [copiedId, setCopiedId] = useState(false);

    // Table pagination: back to page 1 whenever the search or base changes.
    const pageKey = `${search}|${baseCurrency}`;
    const [pageState, setPageState] = useState({ key: pageKey, page: 1 });
    const page = pageState.key === pageKey ? pageState.page : 1;
    const setPage = (next: number | ((current: number) => number)) =>
        setPageState({
            key: pageKey,
            page: typeof next === 'function' ? next(page) : next,
        });
    const [perPage, setPerPage] = useState(10);

    useEffect(() => {
        if (!activeQuote) {
            return;
        }

        const updateTimer = () => {
            const exp = new Date(activeQuote.expires_at).getTime();
            const now = Date.now();
            const diff = Math.max(0, Math.floor((exp - now) / 1000));
            setRemainingSeconds(diff);

            if (diff === 0) {
                try {
                    sessionStorage.removeItem(QUOTE_STORAGE_KEY);
                } catch {
                    // Ignore storage errors
                }
            }
        };

        updateTimer();
        const interval = setInterval(updateTimer, 1000);

        return () => clearInterval(interval);
    }, [activeQuote]);

    const calculateQuote = async (
        from: string,
        to: string,
        amt: string,
        quoteMode: 'source' | 'target',
    ) => {
        const numeric = parseFloat(amt);

        if (isNaN(numeric) || numeric <= 0) {
            return;
        }

        setIsQuoting(true);

        try {
            const response = await fetch(ratesRoute.quote.url(), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN':
                        (
                            document.querySelector(
                                'meta[name="csrf-token"]',
                            ) as HTMLMetaElement
                        )?.content || '',
                },
                body: JSON.stringify({
                    from_currency: from,
                    to_currency: to,
                    amount: amt,
                    mode: quoteMode,
                }),
            });

            const json = await response.json();

            if (response.ok && (json.status === 'success' || json.success)) {
                setActiveQuote(json.data);

                try {
                    sessionStorage.setItem(
                        QUOTE_STORAGE_KEY,
                        JSON.stringify(json.data),
                    );
                } catch {
                    // Ignore storage errors
                }
            } else {
                toast.error(json.message || 'Could not generate FX quote');
            }
        } catch {
            toast.error('Network error requesting FX quote');
        } finally {
            setIsQuoting(false);
        }
    };

    const handleSwap = () => {
        const nextFrom = toCurrency;
        const nextTo = fromCurrency;
        setFromCurrency(nextFrom);
        setToCurrency(nextTo);
        calculateQuote(nextFrom, nextTo, amount, mode);
    };

    const handleModeChange = (newMode: 'source' | 'target') => {
        setMode(newMode);
        calculateQuote(fromCurrency, toCurrency, amount, newMode);
    };

    const handleRecalculate = () => {
        calculateQuote(fromCurrency, toCurrency, amount, mode);
    };

    // Quote once on first load when there's no quote to restore.
    useEffect(() => {
        if (initialQuote) {
            return;
        }

        const timer = setTimeout(
            () => calculateQuote(fromCurrency, toCurrency, amount, mode),
            0,
        );

        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps -- first load only
    }, []);

    const handleBaseChange = (base: string) => {
        router.get(
            ratesRoute.index.url(),
            { base },
            { preserveState: true, preserveScroll: true },
        );
    };

    const copyQuoteId = (id: string) => {
        navigator.clipboard.writeText(id);
        setCopiedId(true);
        toast.success('Quote reference copied');
        setTimeout(() => setCopiedId(false), 2000);
    };

    const filteredRates = useMemo(() => {
        const q = search.trim().toLowerCase();

        return Object.values(rates).filter((item) => {
            if (!q) {
                return true;
            }

            const currencyMatch = item.currency.toLowerCase().includes(q);
            const countryMatch = item.country_name?.toLowerCase().includes(q);
            const detailsName = CURRENCY_DETAILS[item.currency]?.name
                .toLowerCase()
                .includes(q);

            return currencyMatch || countryMatch || detailsName;
        });
    }, [rates, search]);

    // Pagination calculations
    const total = filteredRates.length;
    const totalPages = Math.max(1, Math.ceil(total / perPage));
    const safePage = Math.min(page, totalPages);
    const from = total === 0 ? 0 : (safePage - 1) * perPage + 1;
    const to = Math.min(safePage * perPage, total);
    const pagedRates = useMemo(() => {
        const start = (safePage - 1) * perPage;

        return filteredRates.slice(start, start + perPage);
    }, [filteredRates, safePage, perPage]);

    const formatTimeRemaining = (seconds: number) => {
        const mins = Math.floor(seconds / 60);
        const secs = seconds % 60;

        return `${mins}:${secs.toString().padStart(2, '0')}`;
    };

    return (
        <>
            <Head title="Exchange Rates" />
            <div className="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-6">
                {/* Header matching standard pages */}
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-lg font-semibold tracking-tight">
                            Exchange Rates
                        </h1>
                        <p className="mt-1 text-xs text-neutral-600 dark:text-neutral-400">
                            Mid-market exchange rates and guaranteed{' '}
                            {quoteTtlMinutes}-minute quotes across PayPal
                            funding currencies and mobile money corridors.
                        </p>
                    </div>
                </div>

                <PaypalFxNotice rateSource={rateSource} />

                {/* Metric Summary Strip */}
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <StatCard
                        title="Base Currency"
                        value={baseCurrency}
                        icon={<Landmark className="h-4 w-4" />}
                        footer={
                            <span>
                                Active mid-market rates against{' '}
                                {supportedCurrencies.length} currencies
                            </span>
                        }
                    />
                    <StatCard
                        title="Quote Guarantee"
                        value={`${quoteTtlMinutes} min`}
                        icon={<Lock className="h-4 w-4" />}
                        footer={
                            <span className="flex items-center gap-1.5 text-emerald-600 dark:text-emerald-400">
                                <span className="h-1.5 w-1.5 rounded-full bg-emerald-500" />
                                Locked conversion protection
                            </span>
                        }
                    />
                    <StatCard
                        title="Platform Spread"
                        value={`${marginPercent}%`}
                        icon={<Percent className="h-4 w-4" />}
                        footer={
                            <span>
                                Transparent net margin applied across payout
                                corridors
                            </span>
                        }
                    />
                </div>

                {/* Calculator Card */}
                <Card className="gap-0 border border-sidebar-border/70 p-5 dark:border-sidebar-border">
                    <div className="flex flex-col gap-3 border-b border-sidebar-border/50 pb-4 sm:flex-row sm:items-center sm:justify-between dark:border-sidebar-border/70">
                        <div>
                            <h2 className="text-sm font-semibold">
                                Corridor Calculator
                            </h2>
                            <p className="mt-0.5 text-xs text-neutral-500 dark:text-neutral-400">
                                Calculate an exact send or payout amount with
                                locked rate pricing.
                            </p>
                        </div>

                        <div className="flex items-center gap-2">
                            <Select.Root
                                value={mode}
                                onValueChange={(val) =>
                                    handleModeChange(val as 'source' | 'target')
                                }
                            >
                                <Select.Trigger variant="surface" />
                                <Select.Content>
                                    <Select.Item value="source">
                                        Quote mode: You send
                                    </Select.Item>
                                    <Select.Item value="target">
                                        Quote mode: Recipient gets
                                    </Select.Item>
                                </Select.Content>
                            </Select.Root>
                        </div>
                    </div>

                    <div className="mt-5 grid gap-6 lg:grid-cols-12">
                        {/* Input controls */}
                        <div className="space-y-4 lg:col-span-6">
                            <div className="space-y-1">
                                <Label htmlFor="quote-amount">
                                    {mode === 'source'
                                        ? `Send amount (${fromCurrency})`
                                        : `Payout amount (${toCurrency})`}
                                </Label>
                                <div className="flex gap-2">
                                    <Input
                                        id="quote-amount"
                                        type="number"
                                        step="any"
                                        min="0.01"
                                        value={amount}
                                        onChange={(e) =>
                                            setAmount(e.target.value)
                                        }
                                        onKeyDown={(e) =>
                                            e.key === 'Enter' &&
                                            handleRecalculate()
                                        }
                                        placeholder="100.00"
                                        className="tabular-nums"
                                    />
                                    <DisabledTooltip
                                        disabled={isQuoting}
                                        content="Fetching mid-market quote…"
                                    >
                                        <Button
                                            onClick={handleRecalculate}
                                            disabled={isQuoting}
                                            className="shrink-0 cursor-pointer gap-1.5"
                                        >
                                            <RefreshCw
                                                className={cn(
                                                    'h-3.5 w-3.5',
                                                    isQuoting && 'animate-spin',
                                                )}
                                            />
                                            Quote
                                        </Button>
                                    </DisabledTooltip>
                                </div>
                            </div>

                            <div className="grid grid-cols-1 items-center gap-3 sm:grid-cols-9">
                                <div className="space-y-1 sm:col-span-4">
                                    <Label htmlFor="from-currency">From</Label>
                                    <select
                                        id="from-currency"
                                        value={fromCurrency}
                                        onChange={(e) => {
                                            const val = e.target.value;
                                            setFromCurrency(val);
                                            calculateQuote(
                                                val,
                                                toCurrency,
                                                amount,
                                                mode,
                                            );
                                        }}
                                        className={selectClassName}
                                    >
                                        <optgroup label="Funding Currencies">
                                            {supportedCurrencies
                                                .filter(
                                                    (c) =>
                                                        CURRENCY_DETAILS[c]
                                                            ?.category ===
                                                            'funding' ||
                                                        [
                                                            'USD',
                                                            'EUR',
                                                            'GBP',
                                                            'CAD',
                                                            'AUD',
                                                        ].includes(c),
                                                )
                                                .map((c) => {
                                                    const cc =
                                                        CURRENCY_COUNTRY_MAP[c]
                                                            ?.code ||
                                                        c.slice(0, 2);

                                                    return (
                                                        <option
                                                            key={c}
                                                            value={c}
                                                        >
                                                            {flagEmoji(cc)} {c}{' '}
                                                            —{' '}
                                                            {CURRENCY_DETAILS[c]
                                                                ?.name || c}
                                                        </option>
                                                    );
                                                })}
                                        </optgroup>
                                        <optgroup label="Payout Currencies">
                                            {supportedCurrencies
                                                .filter(
                                                    (c) =>
                                                        CURRENCY_DETAILS[c]
                                                            ?.category ===
                                                        'payout',
                                                )
                                                .map((c) => {
                                                    const cc =
                                                        CURRENCY_COUNTRY_MAP[c]
                                                            ?.code ||
                                                        c.slice(0, 2);

                                                    return (
                                                        <option
                                                            key={c}
                                                            value={c}
                                                        >
                                                            {flagEmoji(cc)} {c}{' '}
                                                            —{' '}
                                                            {CURRENCY_DETAILS[c]
                                                                ?.name || c}
                                                        </option>
                                                    );
                                                })}
                                        </optgroup>
                                    </select>
                                </div>

                                <div className="flex justify-center sm:col-span-1 sm:pt-5">
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="1"
                                        color="gray"
                                        onClick={handleSwap}
                                        title="Swap currencies"
                                        className="cursor-pointer"
                                    >
                                        <ArrowLeftRight className="h-4 w-4 text-neutral-500" />
                                    </Button>
                                </div>

                                <div className="space-y-1 sm:col-span-4">
                                    <Label htmlFor="to-currency">To</Label>
                                    <select
                                        id="to-currency"
                                        value={toCurrency}
                                        onChange={(e) => {
                                            const val = e.target.value;
                                            setToCurrency(val);
                                            calculateQuote(
                                                fromCurrency,
                                                val,
                                                amount,
                                                mode,
                                            );
                                        }}
                                        className={selectClassName}
                                    >
                                        <optgroup label="Mobile Money Payouts">
                                            {supportedCurrencies
                                                .filter(
                                                    (c) =>
                                                        CURRENCY_DETAILS[c]
                                                            ?.category ===
                                                        'payout',
                                                )
                                                .map((c) => {
                                                    const cc =
                                                        CURRENCY_COUNTRY_MAP[c]
                                                            ?.code ||
                                                        c.slice(0, 2);

                                                    return (
                                                        <option
                                                            key={c}
                                                            value={c}
                                                        >
                                                            {flagEmoji(cc)} {c}{' '}
                                                            —{' '}
                                                            {CURRENCY_DETAILS[c]
                                                                ?.name || c}
                                                        </option>
                                                    );
                                                })}
                                        </optgroup>
                                        <optgroup label="Funding Currencies">
                                            {supportedCurrencies
                                                .filter(
                                                    (c) =>
                                                        CURRENCY_DETAILS[c]
                                                            ?.category ===
                                                            'funding' ||
                                                        [
                                                            'USD',
                                                            'EUR',
                                                            'GBP',
                                                        ].includes(c),
                                                )
                                                .map((c) => {
                                                    const cc =
                                                        CURRENCY_COUNTRY_MAP[c]
                                                            ?.code ||
                                                        c.slice(0, 2);

                                                    return (
                                                        <option
                                                            key={c}
                                                            value={c}
                                                        >
                                                            {flagEmoji(cc)} {c}{' '}
                                                            —{' '}
                                                            {CURRENCY_DETAILS[c]
                                                                ?.name || c}
                                                        </option>
                                                    );
                                                })}
                                        </optgroup>
                                    </select>
                                </div>
                            </div>
                        </div>

                        {/* Result card */}
                        <div className="flex flex-col justify-between rounded-lg border border-sidebar-border/50 p-4 lg:col-span-6 dark:border-sidebar-border/70">
                            <div>
                                <div className="flex items-center justify-between border-b border-sidebar-border/50 pb-3 dark:border-sidebar-border/70">
                                    <div className="flex items-center gap-2">
                                        <span className="text-base leading-none">
                                            {flagEmoji(
                                                CURRENCY_COUNTRY_MAP[
                                                    fromCurrency
                                                ]?.code || 'US',
                                            )}
                                        </span>
                                        <ArrowRight className="h-3.5 w-3.5 text-neutral-400" />
                                        <span className="text-base leading-none">
                                            {flagEmoji(
                                                CURRENCY_COUNTRY_MAP[toCurrency]
                                                    ?.code || 'ZM',
                                            )}
                                        </span>
                                        <span className="text-xs font-medium text-neutral-700 dark:text-neutral-300">
                                            {fromCurrency} → {toCurrency}
                                        </span>
                                    </div>

                                    {remainingSeconds > 60 ? (
                                        <span className="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300">
                                            <span className="relative flex h-2 w-2">
                                                <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75" />
                                                <span className="relative inline-flex h-2 w-2 rounded-full bg-emerald-500" />
                                            </span>
                                            <span>
                                                Locked (
                                                {formatTimeRemaining(
                                                    remainingSeconds,
                                                )}
                                                )
                                            </span>
                                        </span>
                                    ) : remainingSeconds > 0 ? (
                                        <span className="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-950/40 dark:text-amber-300">
                                            <span className="h-2 w-2 rounded-full bg-amber-500" />
                                            <span>
                                                Expiring ({remainingSeconds}s)
                                            </span>
                                        </span>
                                    ) : (
                                        <span className="inline-flex items-center gap-1.5 rounded-full bg-red-50 px-2.5 py-0.5 text-xs font-medium text-red-700 dark:bg-red-950/40 dark:text-red-300">
                                            <span className="h-2 w-2 rounded-full bg-red-500" />
                                            <span>Expired</span>
                                        </span>
                                    )}
                                </div>

                                {activeQuote ? (
                                    <div className="mt-4">
                                        <div>
                                            <div className="text-xs text-neutral-500 dark:text-neutral-400">
                                                {mode === 'source'
                                                    ? 'Recipient receives'
                                                    : 'Total you pay'}
                                            </div>
                                            <div className="mt-1 flex items-baseline gap-2">
                                                <span className="text-2xl font-bold tracking-tight text-neutral-900 tabular-nums dark:text-neutral-100">
                                                    {mode === 'source'
                                                        ? activeQuote.target_amount
                                                        : activeQuote.source_amount}
                                                </span>
                                                <span className="text-sm font-semibold text-neutral-700 dark:text-neutral-300">
                                                    {mode === 'source'
                                                        ? activeQuote.target_currency
                                                        : activeQuote.source_currency}
                                                </span>
                                            </div>
                                            <p className="mt-0.5 text-xs text-neutral-500 dark:text-neutral-400">
                                                for{' '}
                                                {mode === 'source'
                                                    ? activeQuote.source_amount
                                                    : activeQuote.target_amount}{' '}
                                                {mode === 'source'
                                                    ? activeQuote.source_currency
                                                    : activeQuote.target_currency}
                                            </p>
                                        </div>

                                        <dl className="mt-4 space-y-1.5 border-t border-sidebar-border/50 pt-3 text-xs dark:border-sidebar-border/70">
                                            <div className="flex items-center justify-between">
                                                <dt className="text-neutral-500 dark:text-neutral-400">
                                                    Mid-market rate
                                                </dt>
                                                <dd className="text-neutral-700 tabular-nums dark:text-neutral-300">
                                                    1{' '}
                                                    {
                                                        activeQuote.source_currency
                                                    }{' '}
                                                    = {activeQuote.base_rate}{' '}
                                                    {
                                                        activeQuote.target_currency
                                                    }
                                                </dd>
                                            </div>
                                            <div className="flex items-center justify-between">
                                                <dt className="text-neutral-500 dark:text-neutral-400">
                                                    Platform spread (
                                                    {activeQuote.margin_percent}
                                                    %)
                                                </dt>
                                                <dd className="text-neutral-700 tabular-nums dark:text-neutral-300">
                                                    −{' '}
                                                    {activeQuote.margin_amount}{' '}
                                                    {
                                                        activeQuote.source_currency
                                                    }
                                                </dd>
                                            </div>
                                            <div className="flex items-center justify-between border-t border-sidebar-border/50 pt-2 font-semibold text-neutral-900 dark:border-sidebar-border/70 dark:text-neutral-100">
                                                <dt>Effective rate</dt>
                                                <dd className="tabular-nums">
                                                    1{' '}
                                                    {
                                                        activeQuote.source_currency
                                                    }{' '}
                                                    ={' '}
                                                    {activeQuote.effective_rate}{' '}
                                                    {
                                                        activeQuote.target_currency
                                                    }
                                                </dd>
                                            </div>
                                        </dl>
                                    </div>
                                ) : (
                                    <div className="flex h-32 items-center justify-center text-xs text-neutral-400">
                                        Enter an amount to view rate details.
                                    </div>
                                )}
                            </div>

                            {activeQuote && (
                                <div className="mt-4 flex items-center justify-between border-t border-sidebar-border/50 pt-3 text-xs text-neutral-500 dark:border-sidebar-border/70 dark:text-neutral-400">
                                    <span className="font-mono text-xs text-neutral-400">
                                        Ref: {activeQuote.id.slice(0, 8)}…
                                    </span>
                                    <Button
                                        variant="ghost"
                                        size="1"
                                        color="gray"
                                        onClick={() =>
                                            copyQuoteId(activeQuote.id)
                                        }
                                        className="cursor-pointer gap-1"
                                    >
                                        {copiedId ? (
                                            <>
                                                <Check className="h-3 w-3" />{' '}
                                                Copied
                                            </>
                                        ) : (
                                            <>
                                                <Copy className="h-3 w-3" />{' '}
                                                Copy ref
                                            </>
                                        )}
                                    </Button>
                                </div>
                            )}
                        </div>
                    </div>
                </Card>

                {/* Corridor Rates Table Card */}
                <Card className="gap-0 border border-sidebar-border/70 p-5 dark:border-sidebar-border">
                    <div className="flex flex-col gap-3 border-b border-sidebar-border/50 pb-4 sm:flex-row sm:items-center sm:justify-between dark:border-sidebar-border/70">
                        <div>
                            <h2 className="text-sm font-semibold">
                                Corridor Exchange Rates
                            </h2>
                            <p className="mt-0.5 text-xs text-neutral-500 dark:text-neutral-400">
                                Real-time mid-market pricing and guaranteed
                                locked rates quoted against {baseCurrency}.
                            </p>
                        </div>

                        <div className="flex flex-wrap items-center gap-3">
                            <Select.Root
                                value={baseCurrency}
                                onValueChange={handleBaseChange}
                            >
                                <Select.Trigger variant="surface" />
                                <Select.Content>
                                    <Select.Item value="USD">
                                        Base: 🇺🇸 USD ($)
                                    </Select.Item>
                                    <Select.Item value="EUR">
                                        Base: 🇪🇺 EUR (€)
                                    </Select.Item>
                                    <Select.Item value="GBP">
                                        Base: 🇬🇧 GBP (£)
                                    </Select.Item>
                                </Select.Content>
                            </Select.Root>

                            <div className="relative">
                                <Search className="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                                <Input
                                    type="search"
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    placeholder="Search currency or market..."
                                    className="w-48 pl-9 sm:w-64"
                                />
                            </div>
                        </div>
                    </div>

                    <div className="mt-4 overflow-x-auto">
                        <table className="w-full text-left text-xs">
                            <thead>
                                <tr className="border-b border-sidebar-border/50 font-medium text-neutral-500 dark:border-sidebar-border/70 dark:text-neutral-400">
                                    <th className="pb-2 text-left font-medium">
                                        Currency
                                    </th>
                                    <th className="pb-2 text-left font-medium">
                                        Market
                                    </th>
                                    <th className="pb-2 text-left font-medium">
                                        Corridor Type
                                    </th>
                                    <th className="pb-2 text-right font-medium">
                                        Mid-Market Rate
                                    </th>
                                    <th className="pb-2 text-right font-medium">
                                        Effective Rate (-{marginPercent}%)
                                    </th>
                                    <th className="pb-2 text-center font-medium">
                                        Decimals
                                    </th>
                                    <th className="pb-2" />
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-sidebar-border/50 dark:divide-sidebar-border/70">
                                {pagedRates.map((rate) => {
                                    const meta =
                                        CURRENCY_DETAILS[rate.currency];
                                    const countryInfo =
                                        CURRENCY_COUNTRY_MAP[rate.currency];
                                    const countryCode =
                                        rate.country_code ||
                                        countryInfo?.code ||
                                        rate.currency.slice(0, 2);
                                    const countryName =
                                        rate.country_name ||
                                        countryInfo?.name ||
                                        rate.currency;
                                    const isPayout =
                                        meta?.category === 'payout';

                                    return (
                                        <tr
                                            key={rate.currency}
                                            className="hover:bg-neutral-50/50 dark:hover:bg-neutral-900/50"
                                        >
                                            <td className="py-2.5 pr-4">
                                                <div className="flex items-center gap-2.5">
                                                    <span
                                                        className="text-lg leading-none"
                                                        role="img"
                                                        aria-label={countryCode}
                                                    >
                                                        {flagEmoji(countryCode)}
                                                    </span>
                                                    <div>
                                                        <div className="flex items-center gap-1.5 font-medium text-neutral-900 dark:text-neutral-100">
                                                            {rate.currency}
                                                            {rate.rate_source ===
                                                                'paypal' && (
                                                                <span
                                                                    className="rounded bg-[#eef4fb] px-1 py-px text-[9px] font-semibold text-[#003087] dark:bg-[#0f1a2b] dark:text-[#93c5fd]"
                                                                    title="PayPal's own exchange rate"
                                                                >
                                                                    PayPal
                                                                </span>
                                                            )}
                                                        </div>
                                                        <div className="text-xs text-neutral-500 dark:text-neutral-400">
                                                            {meta?.name ||
                                                                rate.currency}
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td className="py-2.5 pr-4 text-neutral-600 dark:text-neutral-400">
                                                <div className="flex items-center gap-1.5">
                                                    <span>{countryName}</span>
                                                    <span className="text-[11px] text-neutral-400">
                                                        ({countryCode})
                                                    </span>
                                                </div>
                                            </td>
                                            <td className="py-2.5 pr-4">
                                                {isPayout ? (
                                                    <span className="inline-flex items-center gap-1 rounded bg-blue-50 px-1.5 py-0.5 text-[10px] font-medium text-blue-700 dark:bg-blue-950/40 dark:text-blue-300">
                                                        <span className="h-1 w-1 rounded-full bg-blue-500" />
                                                        Mobile Money
                                                    </span>
                                                ) : (
                                                    <span className="inline-flex items-center gap-1 rounded bg-neutral-100 px-1.5 py-0.5 text-[10px] font-medium text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300">
                                                        <span className="h-1 w-1 rounded-full bg-neutral-400" />
                                                        Funding
                                                    </span>
                                                )}
                                            </td>
                                            <td className="py-2.5 pr-4 text-right whitespace-nowrap text-neutral-600 tabular-nums dark:text-neutral-300">
                                                {formatRate(
                                                    rate.base_rate,
                                                    rate.decimals,
                                                )}
                                            </td>
                                            <td className="py-2.5 pr-4 text-right font-semibold whitespace-nowrap text-neutral-900 tabular-nums dark:text-neutral-100">
                                                {formatRate(
                                                    rate.effective_rate,
                                                    rate.decimals,
                                                )}
                                            </td>
                                            <td className="py-2.5 pr-4 text-center">
                                                <span className="rounded bg-neutral-100 px-1.5 py-0.5 text-[10px] font-medium text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300">
                                                    {rate.decimals === 0
                                                        ? '0 (whole)'
                                                        : `${rate.decimals} dec`}
                                                </span>
                                            </td>
                                            <td className="py-2.5 text-right">
                                                <CorridorQuotePopover
                                                    baseCurrency={baseCurrency}
                                                    rate={rate}
                                                    marginPercent={
                                                        marginPercent
                                                    }
                                                    quoteTtlMinutes={
                                                        quoteTtlMinutes
                                                    }
                                                    defaultAmount={amount}
                                                    onOpenInCalculator={(
                                                        from,
                                                        to,
                                                        amt,
                                                    ) => {
                                                        setFromCurrency(from);
                                                        setToCurrency(to);
                                                        setAmount(amt);
                                                        calculateQuote(
                                                            from,
                                                            to,
                                                            amt,
                                                            mode,
                                                        );
                                                        window.scrollTo({
                                                            top: 0,
                                                            behavior: 'smooth',
                                                        });
                                                    }}
                                                />
                                            </td>
                                        </tr>
                                    );
                                })}

                                {filteredRates.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={7}
                                            className="py-8 text-center text-neutral-400"
                                        >
                                            No rates matching “{search}”.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>

                    {/* Table Pagination matching shared TableFooter pattern */}
                    {total > 0 && (
                        <div className="mt-4 flex flex-col gap-3 border-t border-sidebar-border/50 pt-3 text-sm sm:flex-row sm:items-center sm:justify-between dark:border-sidebar-border/70">
                            <div className="flex items-center gap-3 text-xs text-neutral-500 dark:text-neutral-400">
                                <span>
                                    Showing{' '}
                                    <span className="font-medium text-neutral-700 tabular-nums dark:text-neutral-300">
                                        {from}
                                    </span>
                                    –
                                    <span className="font-medium text-neutral-700 tabular-nums dark:text-neutral-300">
                                        {to}
                                    </span>{' '}
                                    of{' '}
                                    <span className="font-medium text-neutral-700 tabular-nums dark:text-neutral-300">
                                        {total.toLocaleString()}
                                    </span>
                                </span>
                                <span className="flex items-center gap-1.5">
                                    <span>Per page</span>
                                    <Select.Root
                                        value={String(perPage)}
                                        onValueChange={(val) => {
                                            setPerPage(Number(val));
                                            setPage(1);
                                        }}
                                    >
                                        <Select.Trigger variant="surface" />
                                        <Select.Content>
                                            {[5, 10, 20, 50].map((option) => (
                                                <Select.Item
                                                    key={option}
                                                    value={String(option)}
                                                >
                                                    {option}
                                                </Select.Item>
                                            ))}
                                        </Select.Content>
                                    </Select.Root>
                                </span>
                            </div>

                            {totalPages > 1 && (
                                <div className="flex flex-wrap items-center gap-1">
                                    <DisabledTooltip
                                        disabled={safePage === 1}
                                        content="You are on the first page."
                                    >
                                        <button
                                            type="button"
                                            onClick={() =>
                                                setPage((p) =>
                                                    Math.max(1, p - 1),
                                                )
                                            }
                                            disabled={safePage === 1}
                                            className={cn(
                                                'min-w-8 rounded-md px-2.5 py-1 text-center text-xs transition-colors',
                                                safePage === 1
                                                    ? 'cursor-not-allowed text-neutral-400 dark:text-neutral-600'
                                                    : 'cursor-pointer text-neutral-600 hover:bg-neutral-100 dark:text-neutral-300 dark:hover:bg-neutral-800',
                                            )}
                                        >
                                            Previous
                                        </button>
                                    </DisabledTooltip>
                                    {Array.from(
                                        { length: totalPages },
                                        (_, i) => i + 1,
                                    ).map((pageNum) => (
                                        <button
                                            key={pageNum}
                                            type="button"
                                            onClick={() => setPage(pageNum)}
                                            className={cn(
                                                'min-w-8 cursor-pointer rounded-md px-2.5 py-1 text-center text-xs transition-colors',
                                                pageNum === safePage
                                                    ? 'bg-primary font-medium text-primary-foreground'
                                                    : 'text-neutral-600 hover:bg-neutral-100 dark:text-neutral-300 dark:hover:bg-neutral-800',
                                            )}
                                        >
                                            {pageNum}
                                        </button>
                                    ))}
                                    <DisabledTooltip
                                        disabled={safePage === totalPages}
                                        content="You are on the last page."
                                    >
                                        <button
                                            type="button"
                                            onClick={() =>
                                                setPage((p) =>
                                                    Math.min(totalPages, p + 1),
                                                )
                                            }
                                            disabled={safePage === totalPages}
                                            className={cn(
                                                'min-w-8 rounded-md px-2.5 py-1 text-center text-xs transition-colors',
                                                safePage === totalPages
                                                    ? 'cursor-not-allowed text-neutral-400 dark:text-neutral-600'
                                                    : 'cursor-pointer text-neutral-600 hover:bg-neutral-100 dark:text-neutral-300 dark:hover:bg-neutral-800',
                                            )}
                                        >
                                            Next
                                        </button>
                                    </DisabledTooltip>
                                </div>
                            )}
                        </div>
                    )}
                </Card>
            </div>
        </>
    );
}

RatesIndexPage.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'Exchange Rates', href: ratesRoute.index.url() },
    ],
};
