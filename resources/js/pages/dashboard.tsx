import { Head, Link, router } from '@inertiajs/react';
import {
    Activity,
    ArrowDownLeft,
    ArrowDownRight,
    ArrowUpRight,
    CheckCircle2,
    CreditCard,
    Layers,
    Plus,
    Receipt,
    Search,
    TrendingUp,
    XCircle,
} from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { toast } from 'sonner';
import { Button, Select, TextField } from '@radix-ui/themes';
import { ActivityAreaChart } from '@/components/activity-area-chart';
import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { dashboard } from '@/routes';
import providersRoute from '@/routes/providers';
import { cn } from '@/lib/utils';

interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    from: number | null;
    to: number | null;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
}

interface Stat {
    value: number;
    change: number | null;
}

interface DashboardProps {
    apiToken: string | null;
    currency: string;
    hasProviders: boolean;
    stats: {
        volume: Stat;
        transactions: Stat;
        successRate: Stat;
        activeProviders: { value: number; total: number };
        failed: Stat;
    };
    volumeTrend: {
        date: string;
        label: string;
        count: number;
        volume: number;
    }[];
    statusBreakdown: Record<string, number>;
    providerPerformance: Paginated<{
        id: number;
        name: string;
        logo_url: string | null;
        is_active: boolean;
        total: number;
        success: number;
        failed: number;
        pending: number;
        successRate: number | null;
        volume: number;
        lastActivity: string | null;
    }>;
    recentTransactions: Paginated<{
        id: number;
        reference: string;
        provider: string | null;
        customer: string | null;
        amount: number;
        currency: string;
        status: string;
        direction: string;
        isFx: boolean;
        createdAt: string | null;
    }>;
    transactionFilters: { q: string | null };
    providerFilters: { q: string | null; status: string | null };
    perPageOptions: number[];
}

const STATUS_STYLES: Record<
    string,
    { label: string; badge: string; dot: string }
> = {
    success: {
        label: 'Success',
        badge: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400',
        dot: 'bg-emerald-500',
    },
    pending: {
        label: 'Pending',
        badge: 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400',
        dot: 'bg-amber-500',
    },
    failed: {
        label: 'Failed',
        badge: 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-400',
        dot: 'bg-red-500',
    },
    draft: {
        label: 'Draft',
        badge: 'bg-neutral-100 text-neutral-600 dark:bg-neutral-500/15 dark:text-neutral-400',
        dot: 'bg-neutral-400',
    },
};

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

function formatNumber(value: number): string {
    return value.toLocaleString();
}

interface PageMeta {
    from: number | null;
    to: number | null;
    total: number;
    per_page: number;
    last_page: number;
    links: { url: string | null; label: string; active: boolean }[];
}

/**
 * Pagination footer for a dashboard table: a results summary, an adjustable
 * per-page selector, and numbered page links — all via partial Inertia reloads
 * so the two tables page independently without reloading the whole dashboard.
 */
function TableFooter({
    page,
    only,
    perPageKey,
    pageName,
    perPageOptions,
}: {
    page: PageMeta;
    only: string[];
    perPageKey: string;
    pageName: string;
    perPageOptions: number[];
}) {
    if (page.total === 0) {
        return null;
    }

    const changePerPage = (value: string) => {
        const params = new URLSearchParams(window.location.search);
        params.set(perPageKey, value);
        params.delete(pageName); // back to the first page at the new size
        router.get(
            `${window.location.pathname}?${params.toString()}`,
            {},
            { only, preserveState: true, preserveScroll: true, replace: true },
        );
    };

    return (
        <div className="mt-4 flex flex-col gap-3 border-t border-sidebar-border/50 pt-3 text-sm sm:flex-row sm:items-center sm:justify-between dark:border-sidebar-border/70">
            <div className="flex items-center gap-3 text-xs text-neutral-500 dark:text-neutral-400">
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
                    <span>Per page</span>
                    <Select.Root
                        value={String(page.per_page)}
                        onValueChange={changePerPage}
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

            {page.last_page > 1 && (
                <div className="flex flex-wrap items-center gap-1">
                    {page.links.map((link, i) =>
                        link.url ? (
                            <Link
                                key={i}
                                href={link.url}
                                only={only}
                                preserveState
                                preserveScroll
                                className={cn(
                                    'min-w-8 rounded-md px-2.5 py-1 text-center text-xs transition-colors',
                                    link.active
                                        ? 'bg-primary text-primary-foreground'
                                        : 'text-neutral-600 hover:bg-neutral-100 dark:text-neutral-300 dark:hover:bg-neutral-800',
                                )}
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ) : (
                            <span
                                key={i}
                                className="min-w-8 px-2.5 py-1 text-center text-xs text-neutral-300 dark:text-neutral-700"
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ),
                    )}
                </div>
            )}
        </div>
    );
}

function TrendPill({
    change,
    invert = false,
}: {
    change: number | null;
    invert?: boolean;
}) {
    if (change === null) {
        return <span className="text-xs text-neutral-400">No prior data</span>;
    }

    const isUp = change > 0;
    const isFlat = change === 0;
    // For "failed" metrics a rise is bad, so colours invert.
    const isGood = isFlat ? true : invert ? !isUp : isUp;

    return (
        <span
            className={cn(
                'inline-flex items-center gap-0.5 text-xs font-medium',
                isFlat
                    ? 'text-neutral-400'
                    : isGood
                      ? 'text-emerald-600 dark:text-emerald-400'
                      : 'text-red-600 dark:text-red-400',
            )}
        >
            {!isFlat &&
                (isUp ? (
                    <ArrowUpRight className="h-3 w-3" />
                ) : (
                    <ArrowDownRight className="h-3 w-3" />
                ))}
            {Math.abs(change)}%
        </span>
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

export default function Dashboard({
    apiToken,
    currency,
    hasProviders,
    stats,
    volumeTrend,
    statusBreakdown,
    providerPerformance,
    recentTransactions,
    transactionFilters,
    providerFilters,
    perPageOptions,
}: DashboardProps) {
    const [loading, setLoading] = useState(false);
    const [showToken, setShowToken] = useState(false);
    const [txSearch, setTxSearch] = useState(transactionFilters.q ?? '');
    const [provSearch, setProvSearch] = useState(providerFilters.q ?? '');

    const submitTxSearch = (e: React.FormEvent) => {
        e.preventDefault();
        router.get(
            '/dashboard',
            { tx: txSearch.trim() || undefined },
            {
                only: ['recentTransactions', 'transactionFilters'],
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );
    };

    // Reload only the Provider Performance table with the given filter changes,
    // preserving the rest of the dashboard (and resetting to its first page).
    const applyProviderFilter = (
        updates: Record<string, string | undefined>,
    ) => {
        const params = new URLSearchParams(window.location.search);
        Object.entries(updates).forEach(([key, value]) => {
            if (value === undefined || value === '') {
                params.delete(key);
            } else {
                params.set(key, value);
            }
        });
        params.delete('providers');
        router.get(
            `${window.location.pathname}?${params.toString()}`,
            {},
            {
                only: ['providerPerformance', 'providerFilters'],
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );
    };

    const submitProvSearch = (e: React.FormEvent) => {
        e.preventDefault();
        applyProviderFilter({ pq: provSearch.trim() || undefined });
    };

    const copyToClipboard = () => {
        if (apiToken) {
            navigator.clipboard.writeText(apiToken);
            toast.success('API token copied to clipboard');
        }
    };

    const regenerateToken = () => {
        router.post(
            '/api-token/regenerate',
            {},
            {
                onBefore: () => setLoading(true),
                onSuccess: () => {
                    setShowToken(true);
                    toast.success('API token regenerated successfully');
                },
                onError: () => {
                    toast.error('Failed to regenerate API token');
                },
                onFinish: () => setLoading(false),
                preserveState: true,
            },
        );
    };

    const totalStatus = Object.values(statusBreakdown).reduce(
        (sum, n) => sum + n,
        0,
    );
    const hasTrend = volumeTrend.some((d) => d.count > 0);
    const isSearching = Boolean(transactionFilters.q);

    return (
        <>
            <Head title="Dashboard" />
            <div className="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-6">
                {/* Onboarding banner when no providers configured */}
                {!hasProviders && (
                    <Card className="flex flex-col items-start gap-3 border border-sidebar-border/70 bg-gradient-to-br from-neutral-50 to-white p-6 sm:flex-row sm:items-center sm:justify-between dark:border-sidebar-border dark:from-neutral-900 dark:to-neutral-950">
                        <div className="flex items-start gap-3">
                            <div className="rounded-lg bg-primary/10 p-2 text-primary">
                                <Layers className="h-5 w-5" />
                            </div>
                            <div>
                                <h2 className="text-sm font-semibold">
                                    Connect your first payment provider
                                </h2>
                                <p className="mt-1 text-sm text-neutral-600 dark:text-neutral-400">
                                    Add a gateway to start routing transactions
                                    through your switch and unlock live metrics.
                                </p>
                            </div>
                        </div>
                        <Button asChild className="gap-2">
                            <Link href={providersRoute.index.url()}>
                                <Plus className="h-4 w-4" /> Add Provider
                            </Link>
                        </Button>
                    </Card>
                )}

                {/* KPI cards */}
                <div className="grid auto-rows-min gap-6 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard
                        title="Volume Processed"
                        value={formatMoney(stats.volume.value, currency)}
                        icon={<TrendingUp className="h-4 w-4" />}
                        footer={
                            <>
                                <TrendPill change={stats.volume.change} />
                                <span>vs previous 30d</span>
                            </>
                        }
                    />
                    <StatCard
                        title="Transactions"
                        value={formatNumber(stats.transactions.value)}
                        icon={<Receipt className="h-4 w-4" />}
                        footer={
                            <>
                                <TrendPill change={stats.transactions.change} />
                                <span>vs previous 30d</span>
                            </>
                        }
                    />
                    <StatCard
                        title="Success Rate"
                        value={`${stats.successRate.value}%`}
                        icon={<CheckCircle2 className="h-4 w-4" />}
                        footer={
                            <>
                                <TrendPill change={stats.successRate.change} />
                                <span>pts vs previous 30d</span>
                            </>
                        }
                    />
                    <StatCard
                        title="Active Providers"
                        value={`${stats.activeProviders.value}/${stats.activeProviders.total}`}
                        icon={<CreditCard className="h-4 w-4" />}
                        footer={
                            <Link
                                href={providersRoute.index.url()}
                                className="text-primary hover:underline"
                            >
                                Manage providers &rarr;
                            </Link>
                        }
                    />
                </div>

                {/* Activity chart + status breakdown */}
                <div className="grid gap-6 lg:grid-cols-3">
                    <Card className="gap-0 border border-sidebar-border/70 p-5 lg:col-span-2 dark:border-sidebar-border">
                        <div className="flex items-center justify-between">
                            <div>
                                <h2 className="text-sm font-semibold">
                                    Transaction Activity
                                </h2>
                                <p className="text-xs text-neutral-500 dark:text-neutral-400">
                                    Daily transaction count — last 14 days
                                </p>
                            </div>
                            <Activity className="h-4 w-4 text-neutral-400" />
                        </div>
                        {!hasTrend ? (
                            <div className="flex h-44 flex-col items-center justify-center text-center text-sm text-neutral-400">
                                <Activity className="mb-2 h-6 w-6" />
                                No transactions in this window yet.
                            </div>
                        ) : (
                            <div className="mt-4">
                                <ActivityAreaChart
                                    points={volumeTrend}
                                    formatVolume={(v) =>
                                        formatMoney(v, currency)
                                    }
                                />
                            </div>
                        )}
                    </Card>

                    <Card className="gap-0 border border-sidebar-border/70 p-5 dark:border-sidebar-border">
                        <h2 className="text-sm font-semibold">
                            Status Breakdown
                        </h2>
                        <p className="text-xs text-neutral-500 dark:text-neutral-400">
                            Last 30 days
                        </p>
                        {totalStatus === 0 ? (
                            <div className="flex h-44 flex-col items-center justify-center text-center text-sm text-neutral-400">
                                <Layers className="mb-2 h-6 w-6" />
                                No data to display.
                            </div>
                        ) : (
                            <div className="mt-5 space-y-4">
                                {Object.entries(statusBreakdown).map(
                                    ([status, count]) => {
                                        const style =
                                            STATUS_STYLES[status] ??
                                            STATUS_STYLES.draft;
                                        const pct =
                                            totalStatus > 0
                                                ? (count / totalStatus) * 100
                                                : 0;
                                        return (
                                            <div key={status}>
                                                <div className="mb-1 flex items-center justify-between text-sm">
                                                    <span className="flex items-center gap-2">
                                                        <span
                                                            className={cn(
                                                                'h-2 w-2 rounded-full',
                                                                style.dot,
                                                            )}
                                                        />
                                                        {style.label}
                                                    </span>
                                                    <span className="font-medium tabular-nums">
                                                        {formatNumber(count)}{' '}
                                                        <span className="text-xs text-neutral-400">
                                                            ({pct.toFixed(0)}%)
                                                        </span>
                                                    </span>
                                                </div>
                                                <div className="h-1.5 w-full overflow-hidden rounded-full bg-neutral-100 dark:bg-neutral-800">
                                                    <div
                                                        className={cn(
                                                            'h-full rounded-full',
                                                            style.dot,
                                                        )}
                                                        style={{
                                                            width: `${pct}%`,
                                                        }}
                                                    />
                                                </div>
                                            </div>
                                        );
                                    },
                                )}
                            </div>
                        )}
                    </Card>
                </div>

                {/* Provider performance */}
                <Card className="gap-0 border border-sidebar-border/70 p-5 dark:border-sidebar-border">
                    <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <h2 className="text-sm font-semibold">
                                Provider Performance
                            </h2>
                            <p className="text-xs text-neutral-500 dark:text-neutral-400">
                                Reliability and throughput over the last 30 days
                            </p>
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            <Select.Root
                                value={providerFilters.status ?? 'all'}
                                onValueChange={(value) =>
                                    applyProviderFilter({
                                        pstatus:
                                            value === 'all' ? undefined : value,
                                    })
                                }
                            >
                                <Select.Trigger
                                    variant="surface"
                                    placeholder="Status"
                                />
                                <Select.Content>
                                    <Select.Item value="all">
                                        All statuses
                                    </Select.Item>
                                    <Select.Item value="active">
                                        Active
                                    </Select.Item>
                                    <Select.Item value="inactive">
                                        Inactive
                                    </Select.Item>
                                </Select.Content>
                            </Select.Root>
                            <form onSubmit={submitProvSearch}>
                                <TextField.Root
                                    value={provSearch}
                                    onChange={(e) =>
                                        setProvSearch(e.target.value)
                                    }
                                    placeholder="Search providers…"
                                    className="w-full sm:w-48"
                                >
                                    <TextField.Slot>
                                        <Search className="h-4 w-4" />
                                    </TextField.Slot>
                                </TextField.Root>
                            </form>
                            <Button asChild variant="outline" size="1">
                                <Link href={providersRoute.index.url()}>
                                    Manage
                                </Link>
                            </Button>
                        </div>
                    </div>

                    {providerPerformance.data.length === 0 ? (
                        <div className="flex flex-col items-center justify-center py-10 text-center text-sm text-neutral-400">
                            <CreditCard className="mb-2 h-6 w-6" />
                            {providerFilters.q || providerFilters.status
                                ? 'No providers match your filters.'
                                : 'No providers configured yet.'}
                        </div>
                    ) : (
                        <div className="mt-4 space-y-3">
                            {providerPerformance.data.map((provider) => {
                                const rate = provider.successRate;
                                const rateColor =
                                    rate === null
                                        ? 'bg-neutral-300 dark:bg-neutral-700'
                                        : rate >= 95
                                          ? 'bg-emerald-500'
                                          : rate >= 80
                                            ? 'bg-amber-500'
                                            : 'bg-red-500';

                                return (
                                    <div
                                        key={provider.id}
                                        className="flex flex-col gap-3 rounded-lg border border-sidebar-border/50 p-3 sm:flex-row sm:items-center sm:gap-4 dark:border-sidebar-border/70"
                                    >
                                        <div className="flex min-w-0 flex-1 items-center gap-3">
                                            {provider.logo_url ? (
                                                <img
                                                    src={provider.logo_url}
                                                    alt={provider.name}
                                                    className="h-9 w-9 shrink-0 rounded-md object-contain"
                                                />
                                            ) : (
                                                <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-neutral-100 dark:bg-neutral-800">
                                                    <CreditCard className="h-4 w-4 text-neutral-500" />
                                                </div>
                                            )}
                                            <div className="min-w-0">
                                                <div className="flex items-center gap-2">
                                                    <span className="truncate font-medium">
                                                        {provider.name}
                                                    </span>
                                                    <Badge
                                                        variant={
                                                            provider.is_active
                                                                ? 'default'
                                                                : 'secondary'
                                                        }
                                                        className="gap-1 text-[10px]"
                                                    >
                                                        {provider.is_active ? (
                                                            <CheckCircle2 className="h-2.5 w-2.5" />
                                                        ) : (
                                                            <XCircle className="h-2.5 w-2.5" />
                                                        )}
                                                        {provider.is_active
                                                            ? 'Active'
                                                            : 'Inactive'}
                                                    </Badge>
                                                </div>
                                                <span className="text-xs text-neutral-400">
                                                    {provider.lastActivity
                                                        ? `Last activity ${provider.lastActivity}`
                                                        : 'No activity yet'}
                                                </span>
                                            </div>
                                        </div>

                                        <div className="flex items-center gap-4 sm:gap-6">
                                            <div className="hidden text-right sm:block">
                                                <div className="text-sm font-semibold tabular-nums">
                                                    {formatMoney(
                                                        provider.volume,
                                                        currency,
                                                    )}
                                                </div>
                                                <div className="text-xs text-neutral-400">
                                                    volume
                                                </div>
                                            </div>
                                            <div className="hidden text-right sm:block">
                                                <div className="text-sm font-semibold tabular-nums">
                                                    {formatNumber(
                                                        provider.total,
                                                    )}
                                                </div>
                                                <div className="text-xs text-neutral-400">
                                                    txns
                                                </div>
                                            </div>
                                            <div className="w-32 shrink-0">
                                                <div className="mb-1 flex items-center justify-between text-xs">
                                                    <span className="text-neutral-400">
                                                        Success
                                                    </span>
                                                    <span className="font-medium tabular-nums">
                                                        {rate === null
                                                            ? '—'
                                                            : `${rate}%`}
                                                    </span>
                                                </div>
                                                <div className="h-1.5 w-full overflow-hidden rounded-full bg-neutral-100 dark:bg-neutral-800">
                                                    <div
                                                        className={cn(
                                                            'h-full rounded-full',
                                                            rateColor,
                                                        )}
                                                        style={{
                                                            width: `${rate ?? 0}%`,
                                                        }}
                                                    />
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                );
                            })}

                            <TableFooter
                                page={providerPerformance}
                                only={['providerPerformance']}
                                perPageKey="provPer"
                                pageName="providers"
                                perPageOptions={perPageOptions}
                            />
                        </div>
                    )}
                </Card>

                {/* Recent transactions */}
                <Card className="gap-0 border border-sidebar-border/70 p-5 dark:border-sidebar-border">
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h2 className="text-sm font-semibold">
                                Recent Transactions
                            </h2>
                            <p className="text-xs text-neutral-500 dark:text-neutral-400">
                                Latest activity across all providers
                            </p>
                        </div>
                        <form onSubmit={submitTxSearch}>
                            <TextField.Root
                                value={txSearch}
                                onChange={(e) => setTxSearch(e.target.value)}
                                placeholder="Search transactions…"
                                className="w-full sm:w-64"
                            >
                                <TextField.Slot>
                                    <Search className="h-4 w-4" />
                                </TextField.Slot>
                            </TextField.Root>
                        </form>
                    </div>

                    {recentTransactions.data.length === 0 ? (
                        <div className="flex flex-col items-center justify-center py-10 text-center text-sm text-neutral-400">
                            <Receipt className="mb-2 h-6 w-6" />
                            {isSearching
                                ? 'No transactions match your search.'
                                : 'No transactions yet. They will appear here as your switch processes payments.'}
                        </div>
                    ) : (
                        <>
                            <div className="mt-4 overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b border-sidebar-border/50 text-left text-xs text-neutral-500 dark:border-sidebar-border/70 dark:text-neutral-400">
                                            <th className="pr-3 pb-2 font-medium">
                                                Reference
                                            </th>
                                            <th className="pr-3 pb-2 font-medium">
                                                Provider
                                            </th>
                                            <th className="pr-3 pb-2 font-medium">
                                                Customer
                                            </th>
                                            <th className="pr-6 pb-2 text-right font-medium">
                                                Amount
                                            </th>
                                            <th className="pr-3 pb-2 font-medium">
                                                Status
                                            </th>
                                            <th className="pb-2 text-right font-medium">
                                                When
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-sidebar-border/40 dark:divide-sidebar-border/60">
                                        {recentTransactions.data.map((tx) => {
                                            const style =
                                                STATUS_STYLES[tx.status] ??
                                                STATUS_STYLES.draft;
                                            const isCredit =
                                                tx.direction === 'credit';
                                            return (
                                                <tr
                                                    key={tx.id}
                                                    className="hover:bg-neutral-50/60 dark:hover:bg-neutral-900/40"
                                                >
                                                    <td className="py-3 pr-3 font-mono text-xs text-neutral-600 dark:text-neutral-400">
                                                        <span className="flex items-center gap-1.5">
                                                            {isCredit ? (
                                                                <ArrowDownLeft className="h-3.5 w-3.5 text-emerald-500" />
                                                            ) : (
                                                                <ArrowUpRight className="h-3.5 w-3.5 text-neutral-400" />
                                                            )}
                                                            <span
                                                                className="truncate"
                                                                title={
                                                                    tx.reference
                                                                }
                                                            >
                                                                {tx.reference.slice(
                                                                    0,
                                                                    12,
                                                                )}
                                                                …
                                                            </span>
                                                        </span>
                                                    </td>
                                                    <td className="py-3 pr-3">
                                                        {tx.provider ?? '—'}
                                                    </td>
                                                    <td className="py-3 pr-3 text-neutral-600 dark:text-neutral-400">
                                                        {tx.customer ?? '—'}
                                                    </td>
                                                    <td className="py-3 pr-6 text-right font-medium whitespace-nowrap tabular-nums">
                                                        {formatMoney(
                                                            tx.amount,
                                                            tx.currency,
                                                        )}
                                                        {tx.isFx && (
                                                            <span className="ml-1 text-[10px] text-neutral-400">
                                                                FX
                                                            </span>
                                                        )}
                                                    </td>
                                                    <td className="py-3 pr-3">
                                                        <span
                                                            className={cn(
                                                                'inline-flex rounded-md px-2 py-0.5 text-xs font-medium',
                                                                style.badge,
                                                            )}
                                                        >
                                                            {style.label}
                                                        </span>
                                                    </td>
                                                    <td className="py-3 text-right text-xs whitespace-nowrap text-neutral-400">
                                                        {tx.createdAt ?? '—'}
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>

                            <TableFooter
                                page={recentTransactions}
                                only={[
                                    'recentTransactions',
                                    'transactionFilters',
                                ]}
                                perPageKey="txnPer"
                                pageName="txns"
                                perPageOptions={perPageOptions}
                            />
                        </>
                    )}
                </Card>

                {/* API Token */}
                <Card className="border border-sidebar-border/70 dark:border-sidebar-border">
                    <div className="p-6">
                        <h2 className="mb-4 text-sm font-semibold">
                            API Token
                        </h2>
                        <p className="mb-4 text-sm text-neutral-600 dark:text-neutral-400">
                            Use this token to authenticate API requests. Keep it
                            secret and never share it publicly.
                        </p>

                        <div className="space-y-4">
                            <div>
                                <Label
                                    htmlFor="api-token"
                                    className="mb-2 block text-sm font-medium"
                                >
                                    Your API Token
                                </Label>
                                <div className="flex items-center gap-2">
                                    <Input
                                        id="api-token"
                                        type={showToken ? 'text' : 'password'}
                                        value={apiToken || ''}
                                        readOnly
                                        className="flex-1"
                                        placeholder={
                                            loading
                                                ? 'Regenerating...'
                                                : 'No token available'
                                        }
                                    />
                                    <Button
                                        onClick={copyToClipboard}
                                        disabled={!apiToken || loading}
                                        variant="outline"
                                    >
                                        Copy
                                    </Button>
                                    <Button
                                        onClick={() => setShowToken(!showToken)}
                                        disabled={!apiToken || loading}
                                        variant="outline"
                                    >
                                        {showToken ? 'Hide' : 'Show'}
                                    </Button>
                                </div>
                            </div>

                            <div>
                                <Button
                                    onClick={regenerateToken}
                                    disabled={loading}
                                    color="red"
                                >
                                    {loading
                                        ? 'Regenerating...'
                                        : 'Regenerate Token'}
                                </Button>
                                <p className="mt-2 text-xs text-neutral-500 dark:text-neutral-400">
                                    Regenerating will invalidate your current
                                    token.
                                </p>
                            </div>
                        </div>
                    </div>
                </Card>
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
    ],
};
