import { Head, Link, router, usePoll } from '@inertiajs/react';
import { Button, Select, TextField } from '@radix-ui/themes';
import {
    ArrowUpRight,
    ChevronLeft,
    ChevronRight,
    Coins,
    Globe,
    Search,
    Wallet,
} from 'lucide-react';
import { useState } from 'react';
import { Card } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { VolumeSummary } from '@/components/volume-summary';
import type { CurrencyVolume } from '@/components/volume-summary';
import { cn } from '@/lib/utils';
import { index as transfersIndex } from '@/routes/transfers';

interface TransferLeg {
    id: string;
    providerReference: string | null;
    status: string;
    amount: number;
    currency: string;
    provider: string | null;
    providerResponse?: Record<string, unknown> | null;
}

interface TransferEventItem {
    id: number;
    from: string | null;
    to: string | null;
    fromLabel: string | null;
    toLabel: string | null;
    note: string | null;
    createdAt: string | null;
    createdAtHuman: string | null;
}

interface TransferItem {
    id: string;
    type: 'cross_border' | 'payout';
    typeLabel: string;
    reference: string;
    providerReference: string | null;
    provider: string | null;
    providerLogo: string | null;
    fundingProvider?: string | null;
    payoutProvider?: string | null;
    account: string | null;
    recipientName: string | null;
    amount: number;
    currency: string;
    country: string | null;
    countryName: string | null;
    status: string;
    rawStatus: string;
    sourceAmount: number | null;
    sourceCurrency: string | null;
    targetAmount: number;
    targetCurrency: string;
    fxRate: string | null;
    fxMargin: string | null;
    fees?: {
        collection: string;
        settlement: string;
        total: string;
        net: string;
        estimated: boolean;
    } | null;
    failureReason: string | null;
    providerFeedback: string | null;
    fundingLeg: TransferLeg | null;
    payoutLeg: TransferLeg | null;
    refundLeg: TransferLeg | null;
    logsUrl: string;
    events: TransferEventItem[];
    createdAtHuman: string | null;
    createdAt: string | null;
}

interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
}

interface Metrics {
    total: number;
    success: number;
    failed: number;
    pending: number;
    /** Successful volume per currency, busiest first (never added together). */
    volumes: CurrencyVolume[];
    successRate: number | null;
    crossBorderCount: number;
    payoutCount: number;
}

interface Filters {
    range: string;
    type: string;
    status: string | null;
    q: string | null;
    perPage: number;
}

interface MetricTrend {
    total: number[];
    success: number[];
    pending: number[];
    failed: number[];
}

interface TransfersProps {
    metrics: Metrics;
    metricTrend: MetricTrend;
    topCurrencies: { currency: string; count: number; volume: number }[];
    topCountries: { code: string; name: string; count: number }[];
    transfers: Paginated<TransferItem>;
    filters: Filters;
    rangeOptions: string[];
    typeOptions: string[];
    perPageOptions: number[];
}

const RANGE_LABELS: Record<string, string> = {
    today: 'Today',
    '7d': 'Last 7 days',
    '30d': 'Last 30 days',
    all: 'All time',
};

function statusClasses(status: string): string {
    switch (status) {
        case 'success':
            return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400';
        case 'failed':
            return 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-400';
        case 'pending':
            return 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400';
        case 'refunded':
            return 'bg-blue-100 text-blue-700 dark:bg-blue-500/15 dark:text-blue-400';
        default:
            return 'bg-neutral-100 text-neutral-600 dark:bg-neutral-500/15 dark:text-neutral-400';
    }
}

function formatMoney(amount: number, currency: string): string {
    try {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency,
            maximumFractionDigits: amount >= 1000 ? 0 : 2,
        }).format(amount);
    } catch {
        return `${currency} ${amount.toLocaleString()}`;
    }
}

function formatExact(amount: string, currency: string): string {
    try {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency,
        }).format(Number(amount));
    } catch {
        return `${currency} ${amount}`;
    }
}

function Sparkline({ data }: { data: number[] }) {
    const w = 100;
    const h = 36;

    if (!data || data.length === 0) {
        return <svg viewBox={`0 0 ${w} ${h}`} className="h-full w-full" />;
    }

    const max = Math.max(...data);
    const min = Math.min(...data);
    const flat = max === min;
    const step = data.length > 1 ? w / (data.length - 1) : w;
    const y = (v: number) =>
        flat ? h * 0.55 : h - 3 - ((v - min) / (max - min)) * (h - 6);

    const points = data.map((v, i) => [i * step, y(v)] as const);
    const line = points
        .map(
            ([x, py], i) =>
                `${i === 0 ? 'M' : 'L'}${x.toFixed(1)},${py.toFixed(1)}`,
        )
        .join(' ');
    const area = `${line} L${w},${h} L0,${h} Z`;

    return (
        <svg
            viewBox={`0 0 ${w} ${h}`}
            preserveAspectRatio="none"
            className="h-full w-full overflow-visible"
        >
            <path d={area} fill="currentColor" className="opacity-10" />
            <path
                d={line}
                fill="none"
                stroke="currentColor"
                strokeWidth={1.75}
                strokeLinecap="round"
                strokeLinejoin="round"
                vectorEffect="non-scaling-stroke"
            />
        </svg>
    );
}

function KpiCard({
    label,
    value,
    dot,
    color,
    trend,
    href,
    active,
}: {
    label: string;
    value: number;
    dot: string;
    color: string;
    trend: number[];
    href: string;
    active: boolean;
}) {
    return (
        <Link href={href} preserveScroll preserveState className="block">
            <Card
                className={cn(
                    'gap-0 p-4 transition-colors',
                    active
                        ? 'border-ring ring-1 ring-ring/30'
                        : 'border-sidebar-border/70 hover:border-neutral-400 dark:border-sidebar-border dark:hover:border-neutral-600',
                )}
            >
                <div className="flex items-center gap-1.5 text-xs font-medium text-neutral-500 dark:text-neutral-400">
                    <span className={cn('h-2 w-2 rounded-full', dot)} />
                    {label}
                </div>
                <div className="mt-2 text-2xl leading-none font-bold tabular-nums">
                    {value.toLocaleString()}
                </div>
                <div className={cn('mt-3 h-10', color)}>
                    <Sparkline data={trend} />
                </div>
            </Card>
        </Link>
    );
}

function StatusBar({
    success,
    pending,
    failed,
}: {
    success: number;
    pending: number;
    failed: number;
}) {
    const total = success + pending + failed;
    const segments = [
        { label: 'Successful', value: success, cls: 'bg-emerald-500' },
        { label: 'Pending', value: pending, cls: 'bg-amber-500' },
        { label: 'Failed', value: failed, cls: 'bg-red-500' },
    ];

    return (
        <div>
            <div className="flex h-2.5 w-full overflow-hidden rounded-full bg-neutral-100 dark:bg-neutral-800">
                {total > 0 &&
                    segments.map(
                        (s) =>
                            s.value > 0 && (
                                <div
                                    key={s.label}
                                    className={s.cls}
                                    style={{
                                        width: `${(s.value / total) * 100}%`,
                                    }}
                                    title={`${s.label}: ${s.value}`}
                                />
                            ),
                    )}
            </div>
            <div className="mt-2.5 flex flex-wrap gap-x-5 gap-y-1 text-xs text-neutral-500 dark:text-neutral-400">
                {segments.map((s) => (
                    <span key={s.label} className="flex items-center gap-1.5">
                        <span className={cn('h-2 w-2 rounded-full', s.cls)} />
                        {s.label}
                        <span className="font-semibold text-neutral-700 tabular-nums dark:text-neutral-200">
                            {total > 0
                                ? Math.round((s.value / total) * 100)
                                : 0}
                            %
                        </span>
                    </span>
                ))}
            </div>
        </div>
    );
}

function TopList({
    title,
    icon,
    rows,
    emptyText,
}: {
    title: string;
    icon: React.ReactNode;
    rows: { key: string; label: string; sub?: string; count: number }[];
    emptyText: string;
}) {
    const max = Math.max(1, ...rows.map((r) => r.count));
    const total = rows.reduce((sum, r) => sum + r.count, 0);

    return (
        <Card className="gap-0 border border-sidebar-border/70 p-5 dark:border-sidebar-border">
            <div className="mb-4 flex items-center gap-2">
                <span className="text-neutral-400">{icon}</span>
                <h2 className="text-sm font-semibold">{title}</h2>
            </div>
            {rows.length === 0 ? (
                <p className="py-8 text-center text-xs text-neutral-400">
                    {emptyText}
                </p>
            ) : (
                <div className="space-y-3.5">
                    {rows.map((row) => (
                        <div key={row.key}>
                            <div className="mb-1.5 flex items-center justify-between gap-2 text-xs">
                                <span className="flex min-w-0 items-baseline gap-1.5">
                                    <span className="font-semibold text-neutral-700 dark:text-neutral-200">
                                        {row.label}
                                    </span>
                                    {row.sub && (
                                        <span className="truncate font-normal text-neutral-400">
                                            {row.sub}
                                        </span>
                                    )}
                                </span>
                                <span className="shrink-0 tabular-nums">
                                    <span className="font-semibold text-neutral-700 dark:text-neutral-200">
                                        {row.count.toLocaleString()}
                                    </span>
                                    {total > 0 && (
                                        <span className="ml-1.5 text-[11px] text-neutral-400">
                                            {Math.round(
                                                (row.count / total) * 100,
                                            )}
                                            %
                                        </span>
                                    )}
                                </span>
                            </div>
                            <div className="h-1.5 overflow-hidden rounded-full bg-neutral-100 dark:bg-neutral-800">
                                <div
                                    className="h-full rounded-full bg-primary"
                                    style={{
                                        width: `${(row.count / max) * 100}%`,
                                    }}
                                />
                            </div>
                        </div>
                    ))}
                </div>
            )}
        </Card>
    );
}

function PrevNext({
    prevUrl,
    nextUrl,
}: {
    prevUrl: string | null;
    nextUrl: string | null;
}) {
    const base =
        'inline-flex items-center gap-1 rounded-md border px-2.5 py-1 text-xs font-medium transition-colors';
    const enabled =
        'border-sidebar-border/70 text-neutral-600 hover:bg-neutral-100 dark:border-sidebar-border dark:text-neutral-300 dark:hover:bg-neutral-800';
    const disabled =
        'cursor-not-allowed border-sidebar-border/40 text-neutral-300 dark:border-sidebar-border/60 dark:text-neutral-700';

    return (
        <div className="flex items-center gap-1.5">
            {prevUrl ? (
                <Link
                    href={prevUrl}
                    preserveScroll
                    preserveState
                    className={cn(base, enabled)}
                >
                    <ChevronLeft className="h-3.5 w-3.5" /> Previous
                </Link>
            ) : (
                <span className={cn(base, disabled)}>
                    <ChevronLeft className="h-3.5 w-3.5" /> Previous
                </span>
            )}
            {nextUrl ? (
                <Link
                    href={nextUrl}
                    preserveScroll
                    preserveState
                    className={cn(base, enabled)}
                >
                    Next <ChevronRight className="h-3.5 w-3.5" />
                </Link>
            ) : (
                <span className={cn(base, disabled)}>
                    Next <ChevronRight className="h-3.5 w-3.5" />
                </span>
            )}
        </div>
    );
}

export default function TransfersIndex({
    metrics,
    metricTrend,
    topCurrencies,
    topCountries,
    transfers: page,
    filters,
    rangeOptions,
    perPageOptions,
}: TransfersProps) {
    const [q, setQ] = useState(filters.q ?? '');
    const [selected, setSelected] = useState<TransferItem | null>(null);

    // Keep the view live while monitoring
    usePoll(20000, {
        only: [
            'metrics',
            'metricTrend',
            'topCurrencies',
            'topCountries',
            'transfers',
        ],
    });

    const baseQuery: Record<string, string | undefined> = {
        range: filters.range !== '30d' ? filters.range : undefined,
        type: filters.type !== 'all' ? filters.type : undefined,
        status: filters.status ?? undefined,
        q: filters.q ?? undefined,
        perPage:
            filters.perPage !== perPageOptions[0]
                ? String(filters.perPage)
                : undefined,
    };

    const urlWith = (overrides: Record<string, string | undefined>) => {
        const query = { ...baseQuery, ...overrides };
        Object.keys(query).forEach((k) => {
            if (query[k] === undefined || query[k] === '') {
                delete query[k];
            }
        });

        return transfersIndex.url({ query });
    };

    const visit = (overrides: Record<string, string | undefined>) =>
        router.visit(urlWith(overrides), {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });

    const submitSearch = (e: React.FormEvent) => {
        e.preventDefault();
        visit({ q: q.trim() || undefined });
    };

    return (
        <>
            <Head title="Transfers" />
            <div className="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-6">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold tracking-tight">
                            Transfers
                        </h1>
                        <p className="mt-1 text-xs text-neutral-600 dark:text-neutral-400">
                            A holistic view of outbound payouts and cross-border
                            remittances processed through your switch.
                        </p>
                    </div>
                    <Select.Root
                        value={filters.range}
                        onValueChange={(range) => visit({ range })}
                    >
                        <Select.Trigger variant="surface" />
                        <Select.Content>
                            {rangeOptions.map((value) => (
                                <Select.Item key={value} value={value}>
                                    {RANGE_LABELS[value] ?? value}
                                </Select.Item>
                            ))}
                        </Select.Content>
                    </Select.Root>
                </div>

                {/* Overview: volume + status distribution */}
                <Card className="gap-0 border border-sidebar-border/70 p-5 lg:p-6 dark:border-sidebar-border">
                    <div className="flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between">
                        {/* Hero volume */}
                        <div className="shrink-0">
                            <div className="flex items-center gap-2 text-xs font-medium tracking-wide text-neutral-500 uppercase dark:text-neutral-400">
                                <Wallet className="h-3.5 w-3.5" /> Total
                                Outbound Volume
                            </div>
                            <VolumeSummary volumes={metrics.volumes} />
                            <div className="mt-2 text-[11px] text-neutral-400 dark:text-neutral-500">
                                Successful volume, per currency
                            </div>
                            <div className="mt-1 text-xs text-neutral-500 dark:text-neutral-400">
                                {metrics.total.toLocaleString()} transactions
                                {metrics.successRate !== null && (
                                    <>
                                        {' · '}
                                        <span className="font-medium text-emerald-600 dark:text-emerald-400">
                                            {metrics.successRate}%
                                        </span>{' '}
                                        success rate
                                    </>
                                )}
                            </div>
                        </div>

                        {/* Status distribution */}
                        <div className="w-full lg:max-w-md">
                            <div className="mb-2.5 text-xs font-medium tracking-wide text-neutral-400 uppercase dark:text-neutral-500">
                                Status mix
                            </div>
                            <StatusBar
                                success={metrics.success}
                                pending={metrics.pending}
                                failed={metrics.failed}
                            />
                        </div>
                    </div>
                </Card>

                {/* KPI cards with recent-trend sparklines */}
                <div className="grid grid-cols-2 gap-6 lg:grid-cols-4">
                    <KpiCard
                        label="All"
                        value={metrics.total}
                        dot="bg-blue-500"
                        color="text-blue-500 dark:text-blue-400"
                        trend={metricTrend.total}
                        href={urlWith({ status: undefined })}
                        active={!filters.status}
                    />
                    <KpiCard
                        label="Successful"
                        value={metrics.success}
                        dot="bg-emerald-500"
                        color="text-emerald-500 dark:text-emerald-400"
                        trend={metricTrend.success}
                        href={urlWith({ status: 'success' })}
                        active={filters.status === 'success'}
                    />
                    <KpiCard
                        label="Pending"
                        value={metrics.pending}
                        dot="bg-amber-500"
                        color="text-amber-500 dark:text-amber-400"
                        trend={metricTrend.pending}
                        href={urlWith({ status: 'pending' })}
                        active={filters.status === 'pending'}
                    />
                    <KpiCard
                        label="Failed"
                        value={metrics.failed}
                        dot="bg-red-500"
                        color="text-red-500 dark:text-red-400"
                        trend={metricTrend.failed}
                        href={urlWith({ status: 'failed' })}
                        active={filters.status === 'failed'}
                    />
                </div>

                {/* Top currencies & countries */}
                <div className="grid gap-6 lg:grid-cols-2">
                    <TopList
                        title="Top Currencies"
                        icon={<Coins className="h-4 w-4" />}
                        emptyText="No transactions in this window."
                        rows={topCurrencies.map((c) => ({
                            key: c.currency,
                            label: c.currency,
                            sub:
                                c.volume > 0
                                    ? formatMoney(c.volume, c.currency)
                                    : undefined,
                            count: c.count,
                        }))}
                    />
                    <TopList
                        title="Top Destinations"
                        icon={<Globe className="h-4 w-4" />}
                        emptyText="No transactions in this window."
                        rows={topCountries.map((c) => ({
                            key: c.code,
                            label: c.name,
                            sub: c.code,
                            count: c.count,
                        }))}
                    />
                </div>

                {/* Transactions table */}
                <Card className="gap-0 border border-sidebar-border/70 p-0 dark:border-sidebar-border">
                    <div className="flex flex-col gap-3 border-b border-sidebar-border/50 p-4 sm:flex-row sm:items-center sm:justify-between dark:border-sidebar-border/70">
                        <div className="flex items-center gap-3">
                            <h2 className="text-sm font-semibold">
                                Transfers{' '}
                                {filters.status && (
                                    <span className="font-normal text-neutral-400">
                                        · {filters.status}
                                    </span>
                                )}
                            </h2>
                            <Select.Root
                                value={filters.type}
                                onValueChange={(type) => visit({ type })}
                            >
                                <Select.Trigger variant="surface" />
                                <Select.Content>
                                    <Select.Item value="all">
                                        All transfers ({metrics.total})
                                    </Select.Item>
                                    <Select.Item value="cross_border">
                                        Cross-border ({metrics.crossBorderCount}
                                        )
                                    </Select.Item>
                                    <Select.Item value="payout">
                                        Direct payouts ({metrics.payoutCount})
                                    </Select.Item>
                                </Select.Content>
                            </Select.Root>
                        </div>
                        <form
                            onSubmit={submitSearch}
                            className="flex items-center gap-2"
                        >
                            <TextField.Root
                                value={q}
                                onChange={(e) => setQ(e.target.value)}
                                placeholder="Search transfers…"
                                className="w-60"
                            >
                                <TextField.Slot>
                                    <Search className="h-4 w-4" />
                                </TextField.Slot>
                            </TextField.Root>
                            <Button type="submit">Search</Button>
                        </form>
                    </div>

                    {page.data.length === 0 ? (
                        <div className="flex flex-col items-center justify-center py-16 text-center text-sm text-neutral-400">
                            <ArrowUpRight className="mb-2 h-7 w-7" />
                            No transfers match the current filters.
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b border-sidebar-border/50 text-left text-xs text-neutral-500 dark:border-sidebar-border/70 dark:text-neutral-400">
                                        <th className="px-5 py-3 font-medium">
                                            When
                                        </th>
                                        <th className="px-5 py-3 font-medium">
                                            Reference
                                        </th>
                                        <th className="px-5 py-3 font-medium">
                                            Provider
                                        </th>
                                        <th className="px-5 py-3 font-medium">
                                            Account
                                        </th>
                                        <th className="px-5 py-3 font-medium">
                                            Name
                                        </th>
                                        <th className="px-5 py-3 font-medium">
                                            Country
                                        </th>
                                        <th className="px-5 py-3 font-medium">
                                            Status
                                        </th>
                                        <th className="px-5 py-3 text-right font-medium">
                                            Amount
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-sidebar-border/40 dark:divide-sidebar-border/60">
                                    {page.data.map((tx) => (
                                        <tr
                                            key={tx.id}
                                            onClick={() => setSelected(tx)}
                                            className="cursor-pointer hover:bg-neutral-50/60 dark:hover:bg-neutral-900/40"
                                        >
                                            <td
                                                className="px-5 py-3 whitespace-nowrap"
                                                title={
                                                    tx.createdAt ?? undefined
                                                }
                                            >
                                                <div className="text-neutral-700 dark:text-neutral-300">
                                                    {tx.createdAtHuman}
                                                </div>
                                            </td>
                                            <td className="px-5 py-3 font-mono text-xs whitespace-nowrap">
                                                <span
                                                    className="block max-w-[160px] truncate"
                                                    title={tx.reference}
                                                >
                                                    {tx.reference}
                                                </span>
                                            </td>
                                            <td className="px-5 py-3 whitespace-nowrap text-neutral-700 dark:text-neutral-300">
                                                {tx.type === 'cross_border' &&
                                                tx.fundingProvider &&
                                                tx.payoutProvider ? (
                                                    <span className="text-xs">
                                                        {tx.fundingProvider}{' '}
                                                        <span className="text-neutral-400">
                                                            →
                                                        </span>{' '}
                                                        {tx.payoutProvider}
                                                    </span>
                                                ) : (
                                                    (tx.provider ?? '—')
                                                )}
                                            </td>
                                            <td className="px-5 py-3 font-mono text-xs whitespace-nowrap text-neutral-600 dark:text-neutral-400">
                                                {tx.account ?? '—'}
                                            </td>
                                            <td className="px-5 py-3 whitespace-nowrap text-neutral-600 dark:text-neutral-400">
                                                <span
                                                    className="block max-w-[160px] truncate"
                                                    title={
                                                        tx.recipientName ??
                                                        undefined
                                                    }
                                                >
                                                    {tx.recipientName ?? '—'}
                                                </span>
                                            </td>
                                            <td className="px-5 py-3 whitespace-nowrap text-neutral-600 dark:text-neutral-400">
                                                {tx.country ? (
                                                    <span
                                                        title={
                                                            tx.countryName ??
                                                            undefined
                                                        }
                                                    >
                                                        {tx.country}
                                                    </span>
                                                ) : (
                                                    '—'
                                                )}
                                            </td>
                                            <td className="px-5 py-3 whitespace-nowrap">
                                                {tx.type === 'cross_border' &&
                                                tx.rawStatus === 'refunded' ? (
                                                    <div className="flex flex-col items-start gap-0.5">
                                                        <span className="inline-flex rounded-md bg-violet-50 px-2 py-0.5 text-xs font-medium text-violet-700 dark:bg-violet-950/40 dark:text-violet-300">
                                                            PayPal Reversed
                                                        </span>
                                                        <span className="text-[11px] font-medium text-red-600 dark:text-red-400">
                                                            {tx.targetCurrency}{' '}
                                                            payout failed
                                                        </span>
                                                    </div>
                                                ) : tx.type ===
                                                      'cross_border' &&
                                                  (tx.rawStatus ===
                                                      'payout_failed' ||
                                                      tx.rawStatus ===
                                                          'refund_failed') ? (
                                                    <div className="flex flex-col items-start gap-0.5">
                                                        <span className="inline-flex rounded-md bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700 dark:bg-red-950/40 dark:text-red-300">
                                                            Payout Failed
                                                        </span>
                                                        <span className="text-[11px] text-neutral-500">
                                                            Refunding sender…
                                                        </span>
                                                    </div>
                                                ) : (
                                                    <span
                                                        className={cn(
                                                            'inline-flex rounded-md px-2 py-0.5 text-xs font-medium capitalize',
                                                            statusClasses(
                                                                tx.status,
                                                            ),
                                                        )}
                                                    >
                                                        {tx.status}
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-5 py-3 text-right font-semibold whitespace-nowrap tabular-nums">
                                                {tx.type === 'cross_border' &&
                                                tx.rawStatus === 'refunded' ? (
                                                    <div>
                                                        <div className="text-violet-700 dark:text-violet-300">
                                                            {formatMoney(
                                                                tx.sourceAmount ??
                                                                    tx.amount,
                                                                tx.sourceCurrency ??
                                                                    tx.currency,
                                                            )}
                                                            <span className="ml-1 text-[11px] font-normal text-violet-600/80 dark:text-violet-400/80">
                                                                reversed
                                                            </span>
                                                        </div>
                                                        <div className="text-[11px] font-normal text-neutral-400 line-through">
                                                            {formatMoney(
                                                                tx.targetAmount,
                                                                tx.targetCurrency,
                                                            )}{' '}
                                                            failed
                                                        </div>
                                                    </div>
                                                ) : tx.type ===
                                                      'cross_border' &&
                                                  (tx.rawStatus ===
                                                      'payout_failed' ||
                                                      tx.rawStatus ===
                                                          'refund_failed') ? (
                                                    <div>
                                                        <div className="text-red-600 dark:text-red-400">
                                                            {formatMoney(
                                                                tx.targetAmount,
                                                                tx.targetCurrency,
                                                            )}
                                                        </div>
                                                        <div className="text-[11px] font-normal text-neutral-400">
                                                            payout failed
                                                        </div>
                                                    </div>
                                                ) : (
                                                    <div>
                                                        <div>
                                                            {formatMoney(
                                                                tx.amount,
                                                                tx.currency,
                                                            )}
                                                        </div>
                                                        {tx.type ===
                                                            'cross_border' &&
                                                            tx.sourceAmount && (
                                                                <div className="text-[11px] font-normal text-neutral-400">
                                                                    from{' '}
                                                                    {formatMoney(
                                                                        tx.sourceAmount,
                                                                        tx.sourceCurrency ??
                                                                            'USD',
                                                                    )}
                                                                </div>
                                                            )}
                                                    </div>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}

                    {/* Pagination */}
                    {page.total > 0 && (
                        <div className="flex flex-col gap-3 border-t border-sidebar-border/50 px-5 py-3 text-sm sm:flex-row sm:items-center sm:justify-between dark:border-sidebar-border/70">
                            <div className="flex items-center gap-3 text-neutral-500 dark:text-neutral-400">
                                <span>
                                    Showing{' '}
                                    <span className="font-medium text-neutral-700 tabular-nums dark:text-neutral-300">
                                        {page.from ?? 0}
                                    </span>
                                    –
                                    <span className="font-medium text-neutral-700 tabular-nums dark:text-neutral-300">
                                        {page.to ?? 0}
                                    </span>{' '}
                                    of{' '}
                                    <span className="font-medium text-neutral-700 tabular-nums dark:text-neutral-300">
                                        {page.total.toLocaleString()}
                                    </span>
                                </span>
                                <span className="flex items-center gap-1.5">
                                    <span className="text-xs">Per page</span>
                                    <Select.Root
                                        value={String(filters.perPage)}
                                        onValueChange={(value) =>
                                            visit({ perPage: value })
                                        }
                                    >
                                        <Select.Trigger variant="surface" />
                                        <Select.Content>
                                            {perPageOptions.map((option) => (
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
                            <PrevNext
                                prevUrl={page.prev_page_url}
                                nextUrl={page.next_page_url}
                            />
                        </div>
                    )}
                </Card>
            </div>

            {/* Detail dialog */}
            <Dialog
                open={!!selected}
                onOpenChange={(open) => !open && setSelected(null)}
            >
                <DialogContent className="flex max-h-[85vh] flex-col gap-0 overflow-hidden p-0 sm:max-w-xl">
                    <DialogHeader className="shrink-0 border-b border-sidebar-border/70 px-6 py-4 pr-12 text-left">
                        <DialogTitle className="flex flex-wrap items-center gap-2">
                            <span className="font-mono text-sm">
                                {selected?.reference}
                            </span>
                            {selected &&
                                (selected.type === 'cross_border' &&
                                selected.rawStatus === 'refunded' ? (
                                    <span className="inline-flex rounded-md bg-violet-50 px-2 py-0.5 text-xs font-medium text-violet-700 dark:bg-violet-950/40 dark:text-violet-300">
                                        PayPal Reversed
                                    </span>
                                ) : (
                                    <span
                                        className={cn(
                                            'inline-flex rounded-md px-2 py-0.5 text-xs font-medium capitalize',
                                            statusClasses(selected.status),
                                        )}
                                    >
                                        {selected.status}
                                    </span>
                                ))}
                        </DialogTitle>
                        <DialogDescription className="text-xs text-neutral-500">
                            {selected?.createdAt}
                        </DialogDescription>
                    </DialogHeader>

                    <div className="min-h-0 flex-1 space-y-4 overflow-y-auto px-6 py-4">
                        {selected && (
                            <dl className="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
                                {selected.type === 'cross_border' &&
                                selected.rawStatus === 'refunded' ? (
                                    <>
                                        <Detail
                                            label="PayPal Reversal"
                                            value={`${formatMoney(selected.sourceAmount ?? selected.amount, selected.sourceCurrency ?? selected.currency)} (Refunded to sender)`}
                                        />
                                        <Detail
                                            label="Failed Payout"
                                            value={`${formatMoney(selected.targetAmount, selected.targetCurrency)} (Delivery Failed)`}
                                        />
                                    </>
                                ) : (
                                    <>
                                        <Detail
                                            label="Amount"
                                            value={formatMoney(
                                                selected.amount,
                                                selected.currency,
                                            )}
                                        />
                                        <Detail
                                            label="Currency"
                                            value={selected.currency}
                                        />
                                    </>
                                )}
                                <Detail
                                    label="Type"
                                    value={selected.typeLabel}
                                />
                                <Detail
                                    label="Provider"
                                    value={
                                        selected.type === 'cross_border' &&
                                        selected.fundingProvider &&
                                        selected.payoutProvider
                                            ? `${selected.fundingProvider} → ${selected.payoutProvider}`
                                            : (selected.provider ?? '—')
                                    }
                                />
                                <Detail
                                    label="Provider Ref"
                                    value={selected.providerReference ?? '—'}
                                    mono
                                />
                                <Detail
                                    label="Account"
                                    value={selected.account ?? '—'}
                                    mono
                                />
                                <Detail
                                    label="Recipient"
                                    value={selected.recipientName ?? '—'}
                                />
                                <Detail
                                    label="Country"
                                    value={
                                        selected.countryName
                                            ? `${selected.countryName} (${selected.country})`
                                            : (selected.country ?? '—')
                                    }
                                />
                                <Detail
                                    label="Created"
                                    value={selected.createdAtHuman ?? '—'}
                                />
                            </dl>
                        )}

                        {selected && selected.type === 'cross_border' && (
                            <CrossBorderBreakdown tx={selected} />
                        )}

                        {selected &&
                            selected.type === 'payout' &&
                            selected.fees && (
                                <FeeBreakdown
                                    fees={selected.fees}
                                    amount={selected.amount}
                                    currency={selected.currency}
                                />
                            )}

                        {selected && <FailureAndRefundSection tx={selected} />}

                        {selected &&
                            selected.events &&
                            selected.events.length > 0 && (
                                <TransferTimeline events={selected.events} />
                            )}
                    </div>

                    <DialogFooter className="flex shrink-0 flex-row items-center justify-between border-t border-sidebar-border/70 bg-neutral-50/60 px-6 py-3.5 sm:justify-between dark:bg-neutral-900/40">
                        <Button
                            variant="ghost"
                            size="2"
                            onClick={() => setSelected(null)}
                            className="cursor-pointer"
                        >
                            Close
                        </Button>
                        {selected && (
                            <Button
                                variant="soft"
                                size="2"
                                asChild
                                className="cursor-pointer"
                            >
                                <a href={selected.logsUrl}>
                                    View Gateway Logs{' '}
                                    <ArrowUpRight className="ml-1 h-3.5 w-3.5" />
                                </a>
                            </Button>
                        )}
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

function CrossBorderBreakdown({ tx }: { tx: TransferItem }) {
    if (tx.sourceAmount === null || !tx.sourceCurrency) {
        return null;
    }

    const isRefunded = tx.status === 'refunded' || tx.rawStatus === 'refunded';

    const rows: Array<[string, string]> = [
        [
            'Sender paid (PayPal)',
            formatMoney(tx.sourceAmount, tx.sourceCurrency),
        ],
        [
            'Exchange rate',
            `1 ${tx.sourceCurrency} = ${tx.fxRate ?? '—'} ${tx.targetCurrency}`,
        ],
    ];

    return (
        <div className="border-t pt-4">
            <div className="mb-2 flex items-center justify-between">
                <span className="text-xs font-medium tracking-wide text-neutral-400 uppercase dark:text-neutral-500">
                    Cross-Border FX
                </span>
                {tx.fxMargin && (
                    <span className="rounded-md bg-neutral-100 px-2 py-0.5 text-[11px] font-medium text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300">
                        Margin: {tx.fxMargin}%
                    </span>
                )}
            </div>
            <dl className="space-y-1.5 text-sm">
                {rows.map(([label, value]) => (
                    <div
                        key={label}
                        className="flex items-center justify-between"
                    >
                        <dt className="text-neutral-500 dark:text-neutral-400">
                            {label}
                        </dt>
                        <dd className="tabular-nums">{value}</dd>
                    </div>
                ))}
                <div className="flex items-center justify-between border-t pt-1.5 font-semibold">
                    <dt>
                        {isRefunded
                            ? 'Attempted payout (failed)'
                            : 'Recipient receives'}
                    </dt>
                    <dd
                        className={cn(
                            'tabular-nums',
                            isRefunded &&
                                'text-neutral-400 line-through dark:text-neutral-500',
                        )}
                    >
                        {formatMoney(tx.targetAmount, tx.targetCurrency)}
                    </dd>
                </div>
                {isRefunded && (
                    <div className="flex items-center justify-between text-xs font-medium text-violet-700 dark:text-violet-300">
                        <dt>Reversed to sender (PayPal)</dt>
                        <dd className="font-semibold tabular-nums">
                            {formatMoney(tx.sourceAmount, tx.sourceCurrency)}
                        </dd>
                    </div>
                )}
            </dl>
        </div>
    );
}

function FeeBreakdown({
    fees,
    amount,
    currency,
}: {
    fees: NonNullable<TransferItem['fees']>;
    amount: number;
    currency: string;
}) {
    const rows: Array<[string, string]> = [
        ['Payout amount', formatMoney(amount, currency)],
        ['Provider fee', `− ${formatExact(fees.collection, currency)}`],
        ['Settlement fee', `− ${formatExact(fees.settlement, currency)}`],
    ];

    return (
        <div className="border-t pt-4">
            <div className="mb-2 flex items-center justify-between">
                <span className="text-xs font-medium tracking-wide text-neutral-400 uppercase dark:text-neutral-500">
                    Fees
                </span>
                <span
                    className={cn(
                        'rounded-md px-2 py-0.5 text-[11px] font-medium',
                        fees.estimated
                            ? 'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300'
                            : 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300',
                    )}
                    title={
                        fees.estimated
                            ? 'From the provider’s published fee schedule'
                            : 'Reported by the provider for this payout'
                    }
                >
                    {fees.estimated ? 'Estimated' : 'Actual'}
                </span>
            </div>
            <dl className="space-y-1.5 text-sm">
                {rows.map(([label, value]) => (
                    <div
                        key={label}
                        className="flex items-center justify-between"
                    >
                        <dt className="text-neutral-500 dark:text-neutral-400">
                            {label}
                        </dt>
                        <dd className="tabular-nums">{value}</dd>
                    </div>
                ))}
                <div className="flex items-center justify-between border-t pt-1.5 font-semibold">
                    <dt>Deducted from float</dt>
                    <dd className="tabular-nums">
                        {formatExact(fees.net, currency)}
                    </dd>
                </div>
            </dl>
        </div>
    );
}

function FailureAndRefundSection({ tx }: { tx: TransferItem }) {
    const isFailed = tx.status === 'failed' || tx.rawStatus.includes('failed');
    const isRefunded = tx.status === 'refunded' || tx.rawStatus === 'refunded';
    const isRefunding = tx.rawStatus === 'refunding';

    if (
        !isFailed &&
        !isRefunded &&
        !isRefunding &&
        !tx.failureReason &&
        !tx.providerFeedback &&
        !tx.refundLeg
    ) {
        return null;
    }

    return (
        <div className="border-t pt-4">
            <div className="mb-2 flex items-center justify-between">
                <span className="text-xs font-medium tracking-wide text-neutral-400 uppercase dark:text-neutral-500">
                    Failure & Reversal Details
                </span>
                {isRefunded && (
                    <span className="rounded-md bg-violet-50 px-2 py-0.5 text-[11px] font-medium text-violet-700 dark:bg-violet-950/40 dark:text-violet-300">
                        PayPal Reversed to Sender
                    </span>
                )}
                {isRefunding && (
                    <span className="rounded-md bg-amber-50 px-2 py-0.5 text-[11px] font-medium text-amber-700 dark:bg-amber-950/40 dark:text-amber-300">
                        Reversing…
                    </span>
                )}
            </div>

            <div className="space-y-2.5 text-xs">
                {tx.failureReason && (
                    <div className="rounded-md border border-red-200/60 bg-red-50/50 p-2.5 text-red-800 dark:border-red-900/40 dark:bg-red-950/30 dark:text-red-300">
                        <div className="flex items-center justify-between font-semibold text-red-900 dark:text-red-200">
                            <span>
                                {tx.type === 'cross_border'
                                    ? `${tx.targetCurrency} Payout Failure (${tx.payoutProvider ?? tx.provider ?? 'MNO'})`
                                    : 'Payout Failure'}
                            </span>
                            {tx.targetAmount !== null && tx.targetCurrency && (
                                <span className="text-[11px] font-normal line-through opacity-75">
                                    {formatMoney(
                                        tx.targetAmount,
                                        tx.targetCurrency,
                                    )}{' '}
                                    failed
                                </span>
                            )}
                        </div>
                        <p className="mt-1 leading-relaxed">
                            {tx.failureReason}
                        </p>
                        {tx.providerFeedback &&
                            tx.providerFeedback !== tx.failureReason && (
                                <div className="mt-1.5 border-t border-red-200/50 pt-1 text-[11px] text-red-700 dark:border-red-900/40 dark:text-red-400">
                                    <span className="font-medium">
                                        Provider feedback:
                                    </span>{' '}
                                    {tx.providerFeedback}
                                </div>
                            )}
                    </div>
                )}

                {tx.refundLeg && (
                    <div className="rounded-md border border-violet-200/60 bg-violet-50/40 p-2.5 dark:border-violet-900/40 dark:bg-violet-950/20">
                        <div className="flex items-center justify-between font-semibold text-violet-900 dark:text-violet-200">
                            <span>PayPal Payment Reversal</span>
                            <span
                                className={cn(
                                    'rounded px-1.5 py-0.5 text-[10px] font-medium capitalize',
                                    statusClasses(tx.refundLeg.status),
                                )}
                            >
                                {tx.refundLeg.status}
                            </span>
                        </div>
                        <p className="mt-0.5 text-[11px] text-violet-700/80 dark:text-violet-300/80">
                            The original funding payment was reversed back to
                            the sender because delivery to the recipient failed.
                        </p>
                        <dl className="mt-2 grid grid-cols-2 gap-x-2 gap-y-1.5 text-[11px]">
                            <div>
                                <dt className="text-neutral-500 dark:text-neutral-400">
                                    Refund TX ID
                                </dt>
                                <dd
                                    className="truncate font-mono text-neutral-800 dark:text-neutral-200"
                                    title={tx.refundLeg.id}
                                >
                                    {tx.refundLeg.id}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-neutral-500 dark:text-neutral-400">
                                    PayPal Reversal Ref
                                </dt>
                                <dd
                                    className="truncate font-mono text-neutral-800 dark:text-neutral-200"
                                    title={
                                        tx.refundLeg.providerReference ??
                                        undefined
                                    }
                                >
                                    {tx.refundLeg.providerReference ?? '—'}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-neutral-500 dark:text-neutral-400">
                                    Reversed to Sender
                                </dt>
                                <dd className="font-semibold text-violet-700 dark:text-violet-300">
                                    {formatMoney(
                                        tx.refundLeg.amount,
                                        tx.refundLeg.currency,
                                    )}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-neutral-500 dark:text-neutral-400">
                                    Delivered to Recipient
                                </dt>
                                <dd className="text-neutral-600 dark:text-neutral-400">
                                    {tx.targetCurrency} 0.00
                                </dd>
                            </div>
                        </dl>
                    </div>
                )}
            </div>
        </div>
    );
}

function TransferTimeline({ events }: { events: TransferEventItem[] }) {
    if (!events || events.length === 0) {
        return null;
    }

    return (
        <div className="border-t pt-4">
            <div className="mb-2 text-xs font-medium tracking-wide text-neutral-400 uppercase dark:text-neutral-500">
                Transfer Timeline
            </div>
            <ol className="relative ml-2 space-y-3 border-l border-sidebar-border/70 pl-3 dark:border-sidebar-border">
                {events.map((ev) => (
                    <li key={ev.id} className="relative text-xs">
                        <span className="absolute top-1.5 -left-[17px] h-2 w-2 rounded-full border border-background bg-neutral-400 dark:bg-neutral-500" />
                        <div className="flex items-baseline justify-between gap-2">
                            <span className="font-medium text-neutral-800 dark:text-neutral-200">
                                {ev.toLabel ?? ev.to ?? 'Step'}
                            </span>
                            <span
                                className="shrink-0 text-[11px] text-neutral-400"
                                title={ev.createdAt ?? undefined}
                            >
                                {ev.createdAtHuman}
                            </span>
                        </div>
                        {ev.note && (
                            <p className="mt-0.5 text-neutral-500 dark:text-neutral-400">
                                {ev.note}
                            </p>
                        )}
                    </li>
                ))}
            </ol>
        </div>
    );
}

function Detail({
    label,
    value,
    mono,
}: {
    label: string;
    value: string;
    mono?: boolean;
}) {
    return (
        <div>
            <dt className="text-xs font-medium tracking-wide text-neutral-400 uppercase dark:text-neutral-500">
                {label}
            </dt>
            <dd
                className={cn(
                    'mt-0.5 break-words text-neutral-800 dark:text-neutral-200',
                    mono && 'font-mono text-xs',
                )}
            >
                {value}
            </dd>
        </div>
    );
}

TransfersIndex.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'Transfers', href: '/transfers' },
    ],
};
