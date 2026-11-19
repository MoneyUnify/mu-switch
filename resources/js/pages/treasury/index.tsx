import { Head, Link, router, useForm } from '@inertiajs/react';
import { Button } from '@radix-ui/themes';
import {
    AlertTriangle,
    ArrowLeftRight,
    ArrowRight,
    CheckCircle2,
    Clock,
    Landmark,
    Loader2,
    Plus,
    ShieldCheck,
    Sparkles,
    X,
} from 'lucide-react';
import type { FormEvent, ReactNode } from 'react';
import { useEffect, useState } from 'react';
import FloatRuleController from '@/actions/App/Http/Controllers/FloatRuleController';
import RebalanceSuggestionController from '@/actions/App/Http/Controllers/RebalanceSuggestionController';
import TreasuryTransferController from '@/actions/App/Http/Controllers/TreasuryTransferController';
import InputError from '@/components/input-error';
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
import { DisabledTooltip } from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import ai from '@/routes/ai';
import { index as treasuryIndex } from '@/routes/treasury';

type FloatStatus = 'ok' | 'low' | 'none';
type TransferStatus =
    | 'requested'
    | 'approved'
    | 'in_transit'
    | 'settled'
    | 'failed'
    | 'cancelled';

interface Account {
    providerId: number;
    provider: string;
    logo: string | null;
    currency: string;
    balance: string;
    inTransitOut: string | null;
    estimated: boolean;
    rule: {
        id: number;
        minimum: string;
        target: string | null;
        active: boolean;
    } | null;
    status: FloatStatus;
}

interface Alert {
    id: number;
    providerId: number;
    provider: string;
    currency: string;
    balance: string;
    minimum: string;
    target: string | null;
    openedAt: string | null;
}

interface Transfer {
    id: number;
    reference: string;
    from: string | null;
    to: string | null;
    currency: string;
    toCurrency?: string;
    isFx?: boolean;
    fxRate?: string | null;
    targetAmount?: string | null;
    amount: string;
    fee: string;
    received: string | null;
    status: TransferStatus;
    externalReference: string | null;
    note: string | null;
    origin: 'operator' | 'agent';
    reasoning: string | null;
    failureReason: string | null;
    createdAt: string | null;
    events: Array<{
        to: TransferStatus;
        by: string | null;
        note: string | null;
        at: string | null;
    }>;
}

interface Entry {
    id: number;
    type: string;
    provider: string | null;
    currency: string;
    amount: string;
    estimated: boolean;
    reference: string | null;
    occurredAt: string | null;
}

interface ProviderOption {
    id: number;
    name: string;
    currencies: string[];
}

interface Paginated<T> extends PageMeta {
    data: T[];
    current_page: number;
}

type TableKey = 'accounts' | 'transfers' | 'entries';

interface Props {
    accounts: Paginated<Account>;
    alerts: Alert[];
    transfers: Paginated<Transfer>;
    entries: Paginated<Entry>;
    providers: ProviderOption[];
    filters: Record<TableKey, string | null>;
    perPageOptions: Record<TableKey, number[]>;
    aiReady: boolean;
}

/** The treasury agent's last answer, flashed after "Suggest rebalances". */
interface RebalanceInsight {
    summary: string;
    notes: string[];
    rejected: Array<{ suggestion: string; reason: string }>;
    suggested: string[];
    askedAgent: boolean;
}

/** Each table's query keys, matching TreasuryController::TABLES. */
const TABLES: Record<
    TableKey,
    { search: string; page: string; perPage: string; only: string[] }
> = {
    accounts: {
        search: 'aq',
        page: 'accounts',
        perPage: 'accountsPer',
        only: ['accounts', 'filters'],
    },
    transfers: {
        search: 'tq',
        page: 'transfers',
        perPage: 'transfersPer',
        only: ['transfers', 'filters'],
    },
    entries: {
        search: 'lq',
        page: 'entries',
        perPage: 'entriesPer',
        only: ['entries', 'filters'],
    },
};

type StepAction = 'send' | 'settle' | 'fail';

const selectClassName =
    'flex h-9 w-full rounded-full border border-input bg-transparent px-3.5 py-1 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

const statusLabels: Record<TransferStatus, string> = {
    requested: 'Requested',
    approved: 'Approved',
    in_transit: 'In transit',
    settled: 'Settled',
    failed: 'Failed',
    cancelled: 'Cancelled',
};

const entryLabels: Record<string, string> = {
    collection: 'Payment in',
    payout: 'Payout',
    refund: 'Refund',
    fee: 'Fee',
    transfer_out: 'Transfer out',
    transfer_in: 'Transfer in',
    adjustment: 'Adjustment',
    opening_balance: 'Opening balance',
};

/** An exact decimal string, formatted in its currency's own precision. */
function formatMoney(amount: string, currency: string): string {
    try {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency,
        }).format(Number(amount));
    } catch {
        return `${currency} ${amount}`;
    }
}

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
    XOF: { code: 'SN', name: 'WAEMU' },
    XAF: { code: 'CM', name: 'CEMAC' },
    ZAR: { code: 'ZA', name: 'South Africa' },
    NGN: { code: 'NG', name: 'Nigeria' },
    CDF: { code: 'CD', name: 'DR Congo' },
    MZN: { code: 'MZ', name: 'Mozambique' },
};

function flagEmoji(currency: string): string {
    const code = CURRENCY_COUNTRY_MAP[currency]?.code || currency.slice(0, 2);

    if (!code || code.length !== 2) {
        return '🌐';
    }

    return code
        .toUpperCase()
        .replace(/./g, (char) =>
            String.fromCodePoint(127397 + char.charCodeAt(0)),
        );
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

function transferBadgeClass(status: TransferStatus): string {
    return {
        requested:
            'bg-neutral-100 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-300',
        approved:
            'bg-blue-50 text-blue-700 dark:bg-blue-950/40 dark:text-blue-300',
        in_transit:
            'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300',
        settled:
            'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300',
        failed: 'bg-red-50 text-red-700 dark:bg-red-950/40 dark:text-red-300',
        cancelled:
            'bg-neutral-100 text-neutral-500 dark:bg-neutral-800 dark:text-neutral-400',
    }[status];
}

/** One labelled figure in a float alert: small label above, value below. */
function AlertStat({
    label,
    tone = 'default',
    children,
}: {
    label: string;
    tone?: 'default' | 'danger';
    children: ReactNode;
}) {
    return (
        <div className="min-w-0">
            <dt className="text-[11px] font-medium tracking-wide text-neutral-500 uppercase dark:text-neutral-400">
                {label}
            </dt>
            <dd
                className={cn(
                    'mt-0.5 truncate text-sm font-semibold tabular-nums',
                    tone === 'danger'
                        ? 'text-red-600 dark:text-red-400'
                        : 'text-neutral-900 dark:text-neutral-100',
                )}
            >
                {children}
            </dd>
        </div>
    );
}

function FloatBadge({ status }: { status: FloatStatus }) {
    if (status === 'low') {
        return (
            <span className="inline-flex items-center gap-1.5 rounded-full bg-red-50 px-2.5 py-0.5 text-xs font-medium text-red-700 dark:bg-red-950/40 dark:text-red-300">
                <span className="h-1.5 w-1.5 rounded-full bg-red-500" />
                Below minimum
            </span>
        );
    }

    if (status === 'ok') {
        return (
            <span className="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300">
                <span className="h-1.5 w-1.5 rounded-full bg-emerald-500" />
                Healthy
            </span>
        );
    }

    return (
        <span className="inline-flex items-center gap-1.5 rounded-full bg-neutral-100 px-2.5 py-0.5 text-xs font-medium text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300">
            <span className="h-1.5 w-1.5 rounded-full bg-neutral-400" />
            No float rule
        </span>
    );
}

/** A compact section header with a title, a one-line description and its search. */
function SectionHeader({
    title,
    description,
    table,
    filter,
    placeholder,
}: {
    title: string;
    description: string;
    table: TableKey;
    filter: string | null;
    placeholder: string;
}) {
    return (
        <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <h2 className="text-sm font-semibold">{title}</h2>
                <p className="text-xs text-neutral-500 dark:text-neutral-400">
                    {description}
                </p>
            </div>
            <TableSearch
                param={TABLES[table].search}
                pageName={TABLES[table].page}
                only={TABLES[table].only}
                initial={filter}
                placeholder={placeholder}
            />
        </div>
    );
}

/** An empty state that says whether nothing exists or nothing matched. */
function EmptyRow({
    filter,
    noun,
    children,
}: {
    filter: string | null;
    noun: string;
    children: ReactNode;
}) {
    return (
        <div className="mt-4 flex flex-col items-center justify-center rounded-lg border border-dashed border-neutral-200 py-8 text-center text-sm text-neutral-500 dark:border-neutral-800">
            {filter ? (
                <>
                    No {noun} match “{filter}”.
                </>
            ) : (
                children
            )}
        </div>
    );
}

export default function Treasury({
    accounts,
    alerts,
    transfers,
    entries,
    providers,
    filters,
    perPageOptions,
    aiReady,
}: Props) {
    const [isTransferOpen, setIsTransferOpen] = useState(false);
    const [isSuggesting, setIsSuggesting] = useState(false);
    const [insight, setInsight] = useState<RebalanceInsight | null>(null);
    const [ruleFor, setRuleFor] = useState<Account | null>(null);
    const [step, setStep] = useState<{
        action: StepAction;
        transfer: Transfer;
    } | null>(null);

    const transferForm = useForm({
        from_provider_id: '',
        to_provider_id: '',
        currency: '',
        to_currency: '',
        amount: '',
        fee: '',
        note: '',
    });
    const ruleForm = useForm({
        provider_id: 0,
        currency: '',
        minimum: '',
        target: '',
    });
    const stepForm = useForm({
        external_reference: '',
        received: '',
        reason: '',
    });

    const fromProvider = providers.find(
        (p) => String(p.id) === transferForm.data.from_provider_id,
    );
    const toProvider = providers.find(
        (p) => String(p.id) === transferForm.data.to_provider_id,
    );

    const openTransfer = (toProviderId?: number, currency?: string) => {
        transferForm.reset();
        transferForm.clearErrors();

        if (toProviderId) {
            transferForm.setData((data) => ({
                ...data,
                to_provider_id: String(toProviderId),
                currency: currency ?? '',
                to_currency: currency ?? '',
            }));
        }

        setIsTransferOpen(true);
    };

    const submitTransfer = (event: FormEvent) => {
        event.preventDefault();
        transferForm.post(TreasuryTransferController.store.url(), {
            preserveScroll: true,
            onSuccess: () => setIsTransferOpen(false),
        });
    };

    const openRule = (account: Account) => {
        ruleForm.setData({
            provider_id: account.providerId,
            currency: account.currency,
            minimum: account.rule?.minimum ?? '',
            target: account.rule?.target ?? '',
        });
        ruleForm.clearErrors();
        setRuleFor(account);
    };

    const submitRule = (event: FormEvent) => {
        event.preventDefault();
        ruleForm.put(FloatRuleController.upsert.url(), {
            preserveScroll: true,
            onSuccess: () => setRuleFor(null),
        });
    };

    const openStep = (action: StepAction, transfer: Transfer) => {
        stepForm.reset();
        stepForm.clearErrors();
        setStep({ action, transfer });
    };

    const submitStep = (event: FormEvent) => {
        event.preventDefault();

        if (!step) {
            return;
        }

        const url = TreasuryTransferController[step.action].url(
            step.transfer.id,
        );

        stepForm.post(url, {
            preserveScroll: true,
            onSuccess: () => setStep(null),
        });
    };

    const quickAction = (url: string) =>
        router.post(url, {}, { preserveScroll: true });

    useEffect(() => {
        return router.on('flash', (event) => {
            const rebalance = (event as CustomEvent).detail?.flash
                ?.rebalance as RebalanceInsight | undefined;

            if (rebalance) {
                setInsight(rebalance);
            }
        });
    }, []);

    const suggestRebalances = () =>
        router.post(
            RebalanceSuggestionController.store.url(),
            {},
            {
                preserveScroll: true,
                onStart: () => setIsSuggesting(true),
                onFinish: () => setIsSuggesting(false),
            },
        );

    return (
        <>
            <Head title="Treasury" />
            <div className="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-6">
                <div className="flex items-center justify-between gap-4">
                    <div>
                        <h1 className="text-lg font-semibold tracking-tight">
                            Treasury
                        </h1>
                        <p className="mt-1 text-xs text-neutral-600 dark:text-neutral-400">
                            Your money across provider accounts, float alerts,
                            and transfers between accounts.
                        </p>
                    </div>
                    <div className="flex shrink-0 flex-wrap justify-end gap-2">
                        {aiReady ? (
                            <DisabledTooltip
                                disabled={isSuggesting || providers.length < 2}
                                content={
                                    isSuggesting
                                        ? 'Rebalancing analysis in progress…'
                                        : 'Connect at least 2 payment providers to enable rebalancing suggestions.'
                                }
                            >
                                <Button
                                    variant="soft"
                                    onClick={suggestRebalances}
                                    disabled={
                                        isSuggesting || providers.length < 2
                                    }
                                    className="cursor-pointer gap-2"
                                >
                                    {isSuggesting ? (
                                        <Loader2 className="h-4 w-4 animate-spin" />
                                    ) : (
                                        <Sparkles className="h-4 w-4" />
                                    )}
                                    {isSuggesting
                                        ? 'Thinking…'
                                        : 'Suggest rebalances'}
                                </Button>
                            </DisabledTooltip>
                        ) : (
                            <Button
                                variant="soft"
                                color="gray"
                                asChild
                                className="cursor-pointer gap-2"
                            >
                                <Link
                                    href={ai.index.url()}
                                    title="Add an AI provider to get rebalancing suggestions"
                                >
                                    <Sparkles className="h-4 w-4" /> Set up AI
                                </Link>
                            </Button>
                        )}
                        <DisabledTooltip
                            disabled={providers.length < 2}
                            content="Connect at least 2 payment providers to transfer funds between accounts."
                        >
                            <Button
                                onClick={() => openTransfer()}
                                disabled={providers.length < 2}
                                className="cursor-pointer gap-2"
                            >
                                <Plus className="h-4 w-4" /> New transfer
                            </Button>
                        </DisabledTooltip>
                    </div>
                </div>

                {/* Metric Summary Strip */}
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <StatCard
                        title="Provider Accounts"
                        value={`${accounts.total ?? accounts.data.length}`}
                        icon={<Landmark className="h-4 w-4" />}
                        footer={
                            <span>
                                Across {providers.length} payment{' '}
                                {providers.length === 1
                                    ? 'provider'
                                    : 'providers'}
                            </span>
                        }
                    />
                    <StatCard
                        title="Float Health"
                        value={
                            alerts.length === 0
                                ? 'Healthy'
                                : `${alerts.length} Low Float`
                        }
                        icon={
                            alerts.length === 0 ? (
                                <ShieldCheck className="h-4 w-4 text-emerald-500" />
                            ) : (
                                <AlertTriangle className="h-4 w-4 text-amber-500" />
                            )
                        }
                        footer={
                            alerts.length === 0 ? (
                                <span className="flex items-center gap-1.5 text-emerald-600 dark:text-emerald-400">
                                    <span className="h-1.5 w-1.5 rounded-full bg-emerald-500" />
                                    All accounts meet minimum float rules
                                </span>
                            ) : (
                                <span className="flex items-center gap-1.5 text-amber-600 dark:text-amber-400">
                                    <span className="h-1.5 w-1.5 rounded-full bg-amber-500" />
                                    {alerts.length}{' '}
                                    {alerts.length === 1
                                        ? 'account requires'
                                        : 'accounts require'}{' '}
                                    top up
                                </span>
                            )
                        }
                    />
                    <StatCard
                        title="Inter-Account Transfers"
                        value={`${transfers.total ?? transfers.data.length}`}
                        icon={<ArrowLeftRight className="h-4 w-4" />}
                        footer={
                            <span>
                                {
                                    transfers.data.filter((t) =>
                                        [
                                            'requested',
                                            'approved',
                                            'in_transit',
                                        ].includes(t.status),
                                    ).length
                                }{' '}
                                active in flight
                            </span>
                        }
                    />
                </div>

                {insight && (
                    <Card
                        className="gap-0 border border-sidebar-border/70 p-5 shadow-xs dark:border-sidebar-border"
                        data-test="rebalance-insight"
                    >
                        <div className="flex items-start justify-between gap-4">
                            <div className="flex items-start gap-3.5">
                                <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-violet-500/10 text-violet-600 dark:bg-violet-500/15 dark:text-violet-400">
                                    <Sparkles className="h-4.5 w-4.5" />
                                </div>
                                <div className="space-y-1.5">
                                    <div className="flex items-center gap-2">
                                        <span className="inline-flex items-center gap-1.5 rounded-full bg-violet-500/10 px-2.5 py-0.5 text-xs font-medium text-violet-700 dark:bg-violet-500/15 dark:text-violet-300">
                                            <span className="h-1.5 w-1.5 rounded-full bg-violet-500" />
                                            {insight.askedAgent
                                                ? 'Treasury Agent'
                                                : 'Rebalance Check'}
                                        </span>
                                    </div>
                                    {insight.summary && (
                                        <p className="text-sm font-medium text-neutral-900 dark:text-neutral-100">
                                            {insight.summary}
                                        </p>
                                    )}
                                    {insight.notes.length > 0 && (
                                        <ul className="list-disc space-y-0.5 pl-4 text-xs text-neutral-600 dark:text-neutral-400">
                                            {insight.notes.map(
                                                (note, index) => (
                                                    <li key={index}>{note}</li>
                                                ),
                                            )}
                                        </ul>
                                    )}
                                    {insight.rejected.length > 0 && (
                                        <div className="rounded-lg border border-amber-200/60 bg-amber-50/50 p-3 text-xs text-amber-900 dark:border-amber-900/40 dark:bg-amber-950/30 dark:text-amber-200">
                                            <p className="font-semibold text-amber-800 dark:text-amber-300">
                                                Blocked by guardrails
                                            </p>
                                            <ul className="mt-1 list-disc space-y-0.5 pl-4">
                                                {insight.rejected.map(
                                                    (item, index) => (
                                                        <li key={index}>
                                                            <span className="font-medium">
                                                                {
                                                                    item.suggestion
                                                                }
                                                                :
                                                            </span>{' '}
                                                            {item.reason}
                                                        </li>
                                                    ),
                                                )}
                                            </ul>
                                        </div>
                                    )}
                                    {insight.suggested.length > 0 && (
                                        <p className="text-xs text-neutral-500 dark:text-neutral-400">
                                            Suggestions are requested transfers
                                            in the Transfers table below.
                                            Nothing moves until you approve and
                                            send them.
                                        </p>
                                    )}
                                </div>
                            </div>
                            <button
                                type="button"
                                onClick={() => setInsight(null)}
                                className="shrink-0 cursor-pointer rounded-lg p-1 text-neutral-400 hover:bg-neutral-100 hover:text-neutral-600 dark:hover:bg-neutral-800 dark:hover:text-neutral-200"
                                aria-label="Dismiss"
                            >
                                <X className="h-4 w-4" />
                            </button>
                        </div>
                    </Card>
                )}

                {alerts.length > 0 && (
                    <div className="space-y-3" data-test="float-alerts">
                        {alerts.map((alert) => {
                            const balance = Number(alert.balance);
                            const minimum = Number(alert.minimum);
                            const shortfall = Math.max(0, minimum - balance);
                            const coverage =
                                minimum > 0
                                    ? Math.min(
                                          100,
                                          Math.max(
                                              0,
                                              (balance / minimum) * 100,
                                          ),
                                      )
                                    : 0;
                            const country =
                                CURRENCY_COUNTRY_MAP[alert.currency]?.name;

                            return (
                                <Card
                                    key={alert.id}
                                    className="gap-0 border border-amber-200/70 p-4 shadow-xs sm:p-5 dark:border-amber-900/50"
                                >
                                    <div className="flex flex-col gap-4 sm:flex-row sm:items-center">
                                        <div className="flex min-w-0 flex-1 gap-3.5">
                                            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-amber-500/10 text-amber-600 dark:bg-amber-500/15 dark:text-amber-400">
                                                <AlertTriangle className="h-4.5 w-4.5" />
                                            </div>

                                            <div className="min-w-0 flex-1">
                                                <div className="flex flex-wrap items-center gap-x-2.5 gap-y-1">
                                                    <h3 className="text-sm font-semibold text-neutral-900 dark:text-neutral-100">
                                                        {flagEmoji(
                                                            alert.currency,
                                                        )}{' '}
                                                        {alert.provider} (
                                                        {alert.currency})
                                                    </h3>
                                                    <span className="inline-flex items-center gap-1.5 rounded-full bg-amber-500/10 px-2 py-0.5 text-[11px] font-medium text-amber-700 dark:bg-amber-500/15 dark:text-amber-300">
                                                        <span className="h-1.5 w-1.5 rounded-full bg-amber-500" />
                                                        Below minimum float
                                                    </span>
                                                </div>
                                                <p className="mt-0.5 flex flex-wrap items-center gap-x-1.5 text-xs text-neutral-500 dark:text-neutral-400">
                                                    {country && (
                                                        <span>{country}</span>
                                                    )}
                                                    {country &&
                                                        alert.openedAt && (
                                                            <span aria-hidden>
                                                                ·
                                                            </span>
                                                        )}
                                                    {alert.openedAt && (
                                                        <span className="inline-flex items-center gap-1">
                                                            <Clock className="h-3 w-3" />
                                                            Low since{' '}
                                                            {alert.openedAt}
                                                        </span>
                                                    )}
                                                </p>

                                                <dl className="mt-3 grid grid-cols-2 gap-x-6 gap-y-3 sm:grid-cols-4">
                                                    <AlertStat label="Balance">
                                                        {formatMoney(
                                                            alert.balance,
                                                            alert.currency,
                                                        )}
                                                    </AlertStat>
                                                    <AlertStat label="Minimum float">
                                                        {formatMoney(
                                                            alert.minimum,
                                                            alert.currency,
                                                        )}
                                                    </AlertStat>
                                                    <AlertStat
                                                        label="Shortfall"
                                                        tone="danger"
                                                    >
                                                        {formatMoney(
                                                            shortfall.toFixed(
                                                                2,
                                                            ),
                                                            alert.currency,
                                                        )}
                                                    </AlertStat>
                                                    <AlertStat label="Top up to">
                                                        {formatMoney(
                                                            alert.target ??
                                                                alert.minimum,
                                                            alert.currency,
                                                        )}
                                                    </AlertStat>
                                                </dl>

                                                <div className="mt-3 flex items-center gap-3">
                                                    <div
                                                        className="h-1.5 flex-1 overflow-hidden rounded-full bg-neutral-100 dark:bg-neutral-800"
                                                        role="progressbar"
                                                        aria-valuenow={Math.round(
                                                            coverage,
                                                        )}
                                                        aria-valuemin={0}
                                                        aria-valuemax={100}
                                                        aria-label="Balance as a share of the minimum float"
                                                    >
                                                        <div
                                                            className="h-full rounded-full bg-amber-500"
                                                            style={{
                                                                width: `${Math.max(coverage, 2)}%`,
                                                            }}
                                                        />
                                                    </div>
                                                    <span className="shrink-0 text-[11px] text-neutral-500 tabular-nums">
                                                        {coverage < 1
                                                            ? '<1'
                                                            : Math.round(
                                                                  coverage,
                                                              )}
                                                        % of minimum
                                                    </span>
                                                </div>
                                            </div>
                                        </div>

                                        <div className="shrink-0 self-start sm:self-center">
                                            <DisabledTooltip
                                                disabled={providers.length < 2}
                                                content="Connect at least 2 payment providers to transfer funds and top up this account."
                                            >
                                                <Button
                                                    size="2"
                                                    disabled={
                                                        providers.length < 2
                                                    }
                                                    onClick={() =>
                                                        openTransfer(
                                                            alert.providerId,
                                                            alert.currency,
                                                        )
                                                    }
                                                    className="cursor-pointer gap-1.5"
                                                >
                                                    <Plus className="h-3.5 w-3.5" />
                                                    Top up account
                                                </Button>
                                            </DisabledTooltip>
                                        </div>
                                    </div>
                                </Card>
                            );
                        })}
                    </div>
                )}

                <Card className="gap-0 border border-sidebar-border/70 p-5 dark:border-sidebar-border">
                    <SectionHeader
                        title="Accounts"
                        description="Ledger balances per provider and currency, with each account's minimum float."
                        table="accounts"
                        filter={filters.accounts}
                        placeholder="Search accounts…"
                    />

                    {accounts.data.length === 0 ? (
                        <EmptyRow filter={filters.accounts} noun="accounts">
                            <Landmark className="mb-2 h-6 w-6 text-neutral-400" />
                            No balances yet. Settled payments, payouts and
                            opening balances appear here.
                        </EmptyRow>
                    ) : (
                        <div className="mt-4 overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="text-left text-xs text-neutral-500 dark:text-neutral-400">
                                    <tr>
                                        <th className="pb-2 font-medium">
                                            Account
                                        </th>
                                        <th className="pb-2 text-right font-medium">
                                            Balance
                                        </th>
                                        <th className="hidden pb-2 text-right font-medium sm:table-cell">
                                            In transit
                                        </th>
                                        <th className="hidden pb-2 text-right font-medium md:table-cell">
                                            Minimum
                                        </th>
                                        <th className="pb-2 pl-4 font-medium">
                                            Float
                                        </th>
                                        <th className="pb-2" />
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-sidebar-border/50 dark:divide-sidebar-border/70">
                                    {accounts.data.map((account) => (
                                        <tr
                                            key={`${account.providerId}-${account.currency}`}
                                        >
                                            <td className="py-3 pr-4">
                                                <div className="flex items-center gap-2.5">
                                                    <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-neutral-100 text-base dark:bg-neutral-800">
                                                        {flagEmoji(
                                                            account.currency,
                                                        )}
                                                    </span>
                                                    <div>
                                                        <div className="font-medium text-neutral-900 dark:text-neutral-100">
                                                            {account.provider}
                                                        </div>
                                                        <div className="text-xs text-neutral-500 dark:text-neutral-400">
                                                            {account.currency} ·{' '}
                                                            {CURRENCY_COUNTRY_MAP[
                                                                account.currency
                                                            ]?.name ||
                                                                'Operating Account'}
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td className="py-3 text-right font-semibold whitespace-nowrap tabular-nums">
                                                {formatMoney(
                                                    account.balance,
                                                    account.currency,
                                                )}
                                                {account.estimated && (
                                                    <span
                                                        className="ml-1.5 inline-flex items-center rounded bg-neutral-100 px-1.5 py-0.5 text-[10px] font-medium text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300"
                                                        title="Includes fees estimated from a published schedule"
                                                    >
                                                        Est.
                                                    </span>
                                                )}
                                            </td>
                                            <td className="hidden py-3 text-right whitespace-nowrap text-neutral-500 tabular-nums sm:table-cell">
                                                {account.inTransitOut
                                                    ? formatMoney(
                                                          account.inTransitOut,
                                                          account.currency,
                                                      )
                                                    : '—'}
                                            </td>
                                            <td className="hidden py-3 text-right whitespace-nowrap tabular-nums md:table-cell">
                                                {account.rule
                                                    ? formatMoney(
                                                          account.rule.minimum,
                                                          account.currency,
                                                      )
                                                    : '—'}
                                            </td>
                                            <td className="py-3 pl-4">
                                                <FloatBadge
                                                    status={account.status}
                                                />
                                            </td>
                                            <td className="py-3 text-right">
                                                <Button
                                                    variant="soft"
                                                    size="1"
                                                    color="gray"
                                                    onClick={() =>
                                                        openRule(account)
                                                    }
                                                    className="cursor-pointer"
                                                >
                                                    {account.rule
                                                        ? 'Edit float'
                                                        : 'Set float'}
                                                </Button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}

                    <TableFooter
                        page={accounts}
                        only={TABLES.accounts.only}
                        perPageKey={TABLES.accounts.perPage}
                        pageName={TABLES.accounts.page}
                        perPageOptions={perPageOptions.accounts}
                    />
                </Card>

                <Card className="gap-0 border border-sidebar-border/70 p-5 dark:border-sidebar-border">
                    <SectionHeader
                        title="Transfers"
                        description="Money moved between your accounts. Each step is approved, recorded and reflected in the ledger."
                        table="transfers"
                        filter={filters.transfers}
                        placeholder="Search transfers…"
                    />

                    {transfers.data.length === 0 ? (
                        <EmptyRow filter={filters.transfers} noun="transfers">
                            No transfers yet.
                        </EmptyRow>
                    ) : (
                        <ul className="mt-4 divide-y divide-sidebar-border/50 dark:divide-sidebar-border/70">
                            {transfers.data.map((transfer) => (
                                <li key={transfer.id} className="py-2.5">
                                    <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                        <div className="min-w-0">
                                            <div className="flex flex-wrap items-center gap-2 text-sm font-medium">
                                                <span className="flex items-center gap-1.5 truncate">
                                                    <span className="text-sm">
                                                        {flagEmoji(
                                                            transfer.currency,
                                                        )}
                                                    </span>
                                                    <span>{transfer.from}</span>
                                                </span>
                                                <ArrowRight className="h-3.5 w-3.5 shrink-0 text-neutral-400" />
                                                <span className="flex items-center gap-1.5 truncate">
                                                    {transfer.isFx && (
                                                        <span className="text-sm">
                                                            {flagEmoji(
                                                                transfer.toCurrency ||
                                                                    transfer.currency,
                                                            )}
                                                        </span>
                                                    )}
                                                    <span>{transfer.to}</span>
                                                </span>
                                                <span
                                                    className={cn(
                                                        'rounded-md px-2 py-0.5 text-[11px] font-medium',
                                                        transferBadgeClass(
                                                            transfer.status,
                                                        ),
                                                    )}
                                                >
                                                    {
                                                        statusLabels[
                                                            transfer.status
                                                        ]
                                                    }
                                                </span>
                                                {transfer.isFx && (
                                                    <span className="inline-flex items-center gap-1 rounded-md bg-blue-50 px-2 py-0.5 text-[11px] font-medium text-blue-700 dark:bg-blue-950/40 dark:text-blue-300">
                                                        <ArrowLeftRight className="h-3 w-3" />
                                                        FX
                                                        {transfer.fxRate
                                                            ? ` @ ${Number(transfer.fxRate).toFixed(4)}`
                                                            : ''}
                                                    </span>
                                                )}
                                                {transfer.origin ===
                                                    'agent' && (
                                                    <span className="inline-flex items-center gap-1 rounded-md bg-violet-50 px-2 py-0.5 text-[11px] font-medium text-violet-700 dark:bg-violet-950/40 dark:text-violet-300">
                                                        <Sparkles className="h-3 w-3" />
                                                        AI suggested
                                                    </span>
                                                )}
                                            </div>
                                            <div className="mt-0.5 text-xs text-neutral-500">
                                                <span className="font-medium text-neutral-700 tabular-nums dark:text-neutral-300">
                                                    {formatMoney(
                                                        transfer.amount,
                                                        transfer.currency,
                                                    )}
                                                    {transfer.isFx &&
                                                        transfer.targetAmount && (
                                                            <>
                                                                {' → '}
                                                                {formatMoney(
                                                                    transfer.targetAmount,
                                                                    transfer.toCurrency ||
                                                                        transfer.currency,
                                                                )}
                                                            </>
                                                        )}
                                                </span>
                                                {Number(transfer.fee) > 0 &&
                                                    ` + ${formatMoney(transfer.fee, transfer.currency)} fee`}
                                                {transfer.received &&
                                                    ` · ${formatMoney(transfer.received, transfer.toCurrency || transfer.currency)} received`}
                                                {transfer.externalReference &&
                                                    ` · ref ${transfer.externalReference}`}
                                                {transfer.createdAt &&
                                                    ` · ${transfer.createdAt}`}
                                            </div>
                                            {transfer.reasoning && (
                                                <p className="mt-1 max-w-2xl text-xs text-violet-800 dark:text-violet-300/90">
                                                    {transfer.reasoning}
                                                </p>
                                            )}
                                            {transfer.failureReason && (
                                                <p className="mt-0.5 text-xs text-red-600">
                                                    {transfer.failureReason}
                                                </p>
                                            )}
                                        </div>
                                        <div className="flex shrink-0 flex-wrap gap-1.5">
                                            {transfer.status ===
                                                'requested' && (
                                                <Button
                                                    size="1"
                                                    onClick={() =>
                                                        quickAction(
                                                            TreasuryTransferController.approve.url(
                                                                transfer.id,
                                                            ),
                                                        )
                                                    }
                                                    className="cursor-pointer"
                                                >
                                                    Approve
                                                </Button>
                                            )}
                                            {transfer.status === 'approved' && (
                                                <Button
                                                    size="1"
                                                    onClick={() =>
                                                        openStep(
                                                            'send',
                                                            transfer,
                                                        )
                                                    }
                                                    className="cursor-pointer"
                                                >
                                                    Mark sent
                                                </Button>
                                            )}
                                            {transfer.status ===
                                                'in_transit' && (
                                                <>
                                                    <Button
                                                        size="1"
                                                        onClick={() =>
                                                            openStep(
                                                                'settle',
                                                                transfer,
                                                            )
                                                        }
                                                        className="cursor-pointer"
                                                    >
                                                        <CheckCircle2 className="h-3 w-3" />{' '}
                                                        Confirm received
                                                    </Button>
                                                    <Button
                                                        size="1"
                                                        variant="soft"
                                                        color="red"
                                                        onClick={() =>
                                                            openStep(
                                                                'fail',
                                                                transfer,
                                                            )
                                                        }
                                                        className="cursor-pointer"
                                                    >
                                                        Mark failed
                                                    </Button>
                                                </>
                                            )}
                                            {['requested', 'approved'].includes(
                                                transfer.status,
                                            ) && (
                                                <Button
                                                    size="1"
                                                    variant="soft"
                                                    color="gray"
                                                    onClick={() =>
                                                        quickAction(
                                                            TreasuryTransferController.cancel.url(
                                                                transfer.id,
                                                            ),
                                                        )
                                                    }
                                                    className="cursor-pointer"
                                                >
                                                    {transfer.origin ===
                                                        'agent' &&
                                                    transfer.status ===
                                                        'requested'
                                                        ? 'Dismiss'
                                                        : 'Cancel'}
                                                </Button>
                                            )}
                                        </div>
                                    </div>
                                    <details className="mt-1 text-xs text-neutral-500">
                                        <summary className="w-fit cursor-pointer select-none hover:text-neutral-700 dark:hover:text-neutral-300">
                                            History
                                        </summary>
                                        <ol className="mt-1.5 space-y-0.5 border-l border-neutral-200 pl-3 dark:border-neutral-800">
                                            {transfer.events.map(
                                                (event, index) => (
                                                    <li key={index}>
                                                        <span className="font-medium text-neutral-700 dark:text-neutral-300">
                                                            {
                                                                statusLabels[
                                                                    event.to
                                                                ]
                                                            }
                                                        </span>{' '}
                                                        · {event.at}
                                                        {event.by &&
                                                            ` · ${event.by}`}
                                                        {event.note &&
                                                            ` · ${event.note}`}
                                                    </li>
                                                ),
                                            )}
                                        </ol>
                                    </details>
                                </li>
                            ))}
                        </ul>
                    )}

                    <TableFooter
                        page={transfers}
                        only={TABLES.transfers.only}
                        perPageKey={TABLES.transfers.perPage}
                        pageName={TABLES.transfers.page}
                        perPageOptions={perPageOptions.transfers}
                    />
                </Card>

                <Card className="gap-0 border border-sidebar-border/70 p-5 dark:border-sidebar-border">
                    <SectionHeader
                        title="Ledger activity"
                        description="Every movement in your provider accounts, newest first."
                        table="entries"
                        filter={filters.entries}
                        placeholder="Search activity…"
                    />

                    {entries.data.length === 0 ? (
                        <EmptyRow filter={filters.entries} noun="entries">
                            No ledger activity yet.
                        </EmptyRow>
                    ) : (
                        <div className="mt-4 overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="text-left text-xs text-neutral-500 dark:text-neutral-400">
                                    <tr>
                                        <th className="pb-2 font-medium">
                                            Activity
                                        </th>
                                        <th className="hidden pb-2 font-medium sm:table-cell">
                                            When
                                        </th>
                                        <th className="pb-2 text-right font-medium">
                                            Amount
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-sidebar-border/50 dark:divide-sidebar-border/70">
                                    {entries.data.map((entry) => (
                                        <tr key={entry.id}>
                                            <td className="py-3 pr-4">
                                                <div className="flex items-center gap-2.5">
                                                    <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-neutral-100 text-sm dark:bg-neutral-800">
                                                        {flagEmoji(
                                                            entry.currency,
                                                        )}
                                                    </span>
                                                    <div>
                                                        <div className="font-medium text-neutral-900 dark:text-neutral-100">
                                                            {entryLabels[
                                                                entry.type
                                                            ] ?? entry.type}
                                                        </div>
                                                        <div className="max-w-[20rem] truncate text-xs text-neutral-500 dark:text-neutral-400">
                                                            {entry.provider}
                                                            {entry.reference &&
                                                                ` · ${entry.reference}`}
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td className="hidden py-3 pr-4 text-xs whitespace-nowrap text-neutral-500 sm:table-cell">
                                                {entry.occurredAt}
                                            </td>
                                            <td
                                                className={cn(
                                                    'py-3 text-right font-medium whitespace-nowrap tabular-nums',
                                                    entry.amount.startsWith('-')
                                                        ? 'text-red-600 dark:text-red-400'
                                                        : 'text-emerald-600 dark:text-emerald-400',
                                                )}
                                            >
                                                {formatMoney(
                                                    entry.amount,
                                                    entry.currency,
                                                )}
                                                {entry.estimated && (
                                                    <span className="ml-1.5 inline-flex items-center rounded bg-neutral-100 px-1.5 py-0.5 text-[10px] font-medium text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300">
                                                        Est.
                                                    </span>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}

                    <TableFooter
                        page={entries}
                        only={TABLES.entries.only}
                        perPageKey={TABLES.entries.perPage}
                        pageName={TABLES.entries.page}
                        perPageOptions={perPageOptions.entries}
                    />
                </Card>
            </div>

            <Dialog open={isTransferOpen} onOpenChange={setIsTransferOpen}>
                <DialogContent className="sm:max-w-lg">
                    <form onSubmit={submitTransfer} className="space-y-4">
                        <DialogHeader>
                            <DialogTitle>New transfer</DialogTitle>
                            <DialogDescription>
                                Move money between your provider accounts.
                                Nothing moves until you approve and send it.
                            </DialogDescription>
                        </DialogHeader>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-1">
                                <Label htmlFor="transfer-from">From</Label>
                                <select
                                    id="transfer-from"
                                    value={transferForm.data.from_provider_id}
                                    onChange={(e) =>
                                        transferForm.setData(
                                            'from_provider_id',
                                            e.target.value,
                                        )
                                    }
                                    className={selectClassName}
                                    required
                                >
                                    <option value="">Choose an account</option>
                                    {providers.map((provider) => (
                                        <option
                                            key={provider.id}
                                            value={provider.id}
                                        >
                                            {provider.name}
                                        </option>
                                    ))}
                                </select>
                                <InputError
                                    message={
                                        transferForm.errors.from_provider_id
                                    }
                                />
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="transfer-to">To</Label>
                                <select
                                    id="transfer-to"
                                    value={transferForm.data.to_provider_id}
                                    onChange={(e) =>
                                        transferForm.setData(
                                            'to_provider_id',
                                            e.target.value,
                                        )
                                    }
                                    className={selectClassName}
                                    required
                                >
                                    <option value="">Choose an account</option>
                                    {providers.map((provider) => (
                                        <option
                                            key={provider.id}
                                            value={provider.id}
                                        >
                                            {provider.name}
                                        </option>
                                    ))}
                                </select>
                                <InputError
                                    message={transferForm.errors.to_provider_id}
                                />
                            </div>
                        </div>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-1">
                                <Label htmlFor="transfer-currency">
                                    From Currency
                                </Label>
                                <select
                                    id="transfer-currency"
                                    value={transferForm.data.currency}
                                    onChange={(e) => {
                                        const val = e.target.value;
                                        transferForm.setData((data) => ({
                                            ...data,
                                            currency: val,
                                            to_currency:
                                                data.to_currency || val,
                                        }));
                                    }}
                                    className={selectClassName}
                                    required
                                >
                                    <option value="">Currency</option>
                                    {(fromProvider?.currencies ?? []).map(
                                        (currency) => (
                                            <option
                                                key={currency}
                                                value={currency}
                                            >
                                                {flagEmoji(currency)} {currency}
                                            </option>
                                        ),
                                    )}
                                </select>
                                <InputError
                                    message={transferForm.errors.currency}
                                />
                            </div>

                            <div className="space-y-1">
                                <Label htmlFor="transfer-to-currency">
                                    To Currency
                                </Label>
                                <select
                                    id="transfer-to-currency"
                                    value={
                                        transferForm.data.to_currency ||
                                        transferForm.data.currency
                                    }
                                    onChange={(e) =>
                                        transferForm.setData(
                                            'to_currency',
                                            e.target.value,
                                        )
                                    }
                                    className={selectClassName}
                                    required
                                >
                                    <option value="">Currency</option>
                                    {(toProvider?.currencies ?? []).map(
                                        (currency) => (
                                            <option
                                                key={currency}
                                                value={currency}
                                            >
                                                {flagEmoji(currency)} {currency}
                                            </option>
                                        ),
                                    )}
                                    {transferForm.data.currency &&
                                        !toProvider?.currencies.includes(
                                            transferForm.data.currency,
                                        ) && (
                                            <option
                                                value={
                                                    transferForm.data.currency
                                                }
                                            >
                                                {flagEmoji(
                                                    transferForm.data.currency,
                                                )}{' '}
                                                {transferForm.data.currency}
                                            </option>
                                        )}
                                </select>
                                <InputError
                                    message={transferForm.errors.to_currency}
                                />
                            </div>
                        </div>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-1">
                                <Label htmlFor="transfer-amount">
                                    Amount{' '}
                                    {transferForm.data.currency
                                        ? `(${transferForm.data.currency})`
                                        : ''}
                                </Label>
                                <Input
                                    id="transfer-amount"
                                    inputMode="decimal"
                                    value={transferForm.data.amount}
                                    onChange={(e) =>
                                        transferForm.setData(
                                            'amount',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="0.00"
                                    required
                                />
                                <InputError
                                    message={transferForm.errors.amount}
                                />
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="transfer-fee">
                                    Fee (optional)
                                </Label>
                                <Input
                                    id="transfer-fee"
                                    inputMode="decimal"
                                    value={transferForm.data.fee}
                                    onChange={(e) =>
                                        transferForm.setData(
                                            'fee',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="0.00"
                                />
                                <InputError message={transferForm.errors.fee} />
                            </div>
                        </div>

                        {transferForm.data.currency &&
                            transferForm.data.to_currency &&
                            transferForm.data.currency !==
                                transferForm.data.to_currency && (
                                <div className="flex items-start gap-2.5 rounded-lg border border-blue-200/80 bg-blue-50/60 p-3 text-xs text-blue-900 dark:border-blue-900/50 dark:bg-blue-950/30 dark:text-blue-200">
                                    <ArrowLeftRight className="mt-0.5 h-4 w-4 shrink-0 text-blue-600 dark:text-blue-400" />
                                    <div>
                                        <p className="font-semibold text-blue-900 dark:text-blue-100">
                                            Cross-Currency FX Transfer
                                        </p>
                                        <p className="mt-0.5 text-blue-700 dark:text-blue-300">
                                            Converting{' '}
                                            {transferForm.data.currency} →{' '}
                                            {transferForm.data.to_currency}. The
                                            platform rate is locked upon
                                            transfer request.
                                        </p>
                                    </div>
                                </div>
                            )}

                        <div className="space-y-1">
                            <Label htmlFor="transfer-note">
                                Note (optional)
                            </Label>
                            <Input
                                id="transfer-note"
                                value={transferForm.data.note}
                                onChange={(e) =>
                                    transferForm.setData('note', e.target.value)
                                }
                                placeholder="e.g. Top up the payout float"
                            />
                            <InputError message={transferForm.errors.note} />
                        </div>

                        <p className="text-xs text-neutral-500">
                            {transferForm.data.currency &&
                            transferForm.data.to_currency &&
                            transferForm.data.currency !==
                                transferForm.data.to_currency
                                ? 'Cross-currency transfers execute via the switch FX engine upon approval.'
                                : 'Transfers move money between your provider accounts upon approval.'}
                        </p>

                        <DialogFooter>
                            <Button
                                type="button"
                                variant="soft"
                                color="gray"
                                onClick={() => setIsTransferOpen(false)}
                                className="cursor-pointer"
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                disabled={transferForm.processing}
                                className="cursor-pointer"
                            >
                                Request transfer
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog
                open={ruleFor !== null}
                onOpenChange={(open) => !open && setRuleFor(null)}
            >
                <DialogContent className="sm:max-w-md">
                    <form onSubmit={submitRule} className="space-y-4">
                        <DialogHeader>
                            <DialogTitle>
                                Float for {ruleFor?.provider} (
                                {ruleFor?.currency})
                            </DialogTitle>
                            <DialogDescription>
                                You&apos;re alerted when the balance drops below
                                the minimum. The target is the amount to top up
                                to.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-1">
                                <Label htmlFor="rule-minimum">Minimum</Label>
                                <Input
                                    id="rule-minimum"
                                    inputMode="decimal"
                                    value={ruleForm.data.minimum}
                                    onChange={(e) =>
                                        ruleForm.setData(
                                            'minimum',
                                            e.target.value,
                                        )
                                    }
                                    required
                                />
                                <InputError message={ruleForm.errors.minimum} />
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="rule-target">
                                    Target (optional)
                                </Label>
                                <Input
                                    id="rule-target"
                                    inputMode="decimal"
                                    value={ruleForm.data.target}
                                    onChange={(e) =>
                                        ruleForm.setData(
                                            'target',
                                            e.target.value,
                                        )
                                    }
                                />
                                <InputError message={ruleForm.errors.target} />
                            </div>
                        </div>
                        <DialogFooter className="sm:justify-between">
                            {ruleFor?.rule ? (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    color="red"
                                    onClick={() => {
                                        router.delete(
                                            FloatRuleController.destroy.url(
                                                ruleFor.rule!.id,
                                            ),
                                            {
                                                preserveScroll: true,
                                                onSuccess: () =>
                                                    setRuleFor(null),
                                            },
                                        );
                                    }}
                                    className="cursor-pointer"
                                >
                                    Remove rule
                                </Button>
                            ) : (
                                <span />
                            )}
                            <Button
                                type="submit"
                                disabled={ruleForm.processing}
                                className="cursor-pointer"
                            >
                                Save float
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog
                open={step !== null}
                onOpenChange={(open) => !open && setStep(null)}
            >
                <DialogContent className="sm:max-w-md">
                    <form onSubmit={submitStep} className="space-y-4">
                        <DialogHeader>
                            <DialogTitle>
                                {step?.action === 'send' && 'Mark as sent'}
                                {step?.action === 'settle' &&
                                    'Confirm received'}
                                {step?.action === 'fail' && 'Mark as failed'}
                            </DialogTitle>
                            <DialogDescription>
                                {step &&
                                    `${formatMoney(step.transfer.amount, step.transfer.currency)} from ${step.transfer.from} to ${step.transfer.to}.`}{' '}
                                {step?.action === 'send' &&
                                    'Do this once the money has left the source account.'}
                                {step?.action === 'settle' &&
                                    'Enter what actually arrived; any shortfall is recorded as a fee.'}
                                {step?.action === 'fail' &&
                                    'The money never arrived. Its effect on the ledger is reversed.'}
                            </DialogDescription>
                        </DialogHeader>

                        {step?.action === 'settle' && (
                            <div className="space-y-1">
                                <Label htmlFor="step-received">
                                    Amount received
                                </Label>
                                <Input
                                    id="step-received"
                                    inputMode="decimal"
                                    value={stepForm.data.received}
                                    onChange={(e) =>
                                        stepForm.setData(
                                            'received',
                                            e.target.value,
                                        )
                                    }
                                    placeholder={step.transfer.amount}
                                />
                                <InputError
                                    message={stepForm.errors.received}
                                />
                            </div>
                        )}

                        {step?.action !== 'fail' && (
                            <div className="space-y-1">
                                <Label htmlFor="step-reference">
                                    External reference (optional)
                                </Label>
                                <Input
                                    id="step-reference"
                                    value={stepForm.data.external_reference}
                                    onChange={(e) =>
                                        stepForm.setData(
                                            'external_reference',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="e.g. bank transfer reference"
                                />
                                <InputError
                                    message={stepForm.errors.external_reference}
                                />
                            </div>
                        )}

                        {step?.action === 'fail' && (
                            <div className="space-y-1">
                                <Label htmlFor="step-reason">Reason</Label>
                                <Input
                                    id="step-reason"
                                    value={stepForm.data.reason}
                                    onChange={(e) =>
                                        stepForm.setData(
                                            'reason',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="e.g. The bank returned the funds"
                                    required
                                />
                                <InputError message={stepForm.errors.reason} />
                            </div>
                        )}

                        <DialogFooter>
                            <Button
                                type="button"
                                variant="soft"
                                color="gray"
                                onClick={() => setStep(null)}
                                className="cursor-pointer"
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                color={
                                    step?.action === 'fail' ? 'red' : undefined
                                }
                                disabled={stepForm.processing}
                                className="cursor-pointer"
                            >
                                Confirm
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}

Treasury.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'Treasury', href: treasuryIndex.url() },
    ],
};
