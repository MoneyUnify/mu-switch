import { Head, Link, router, usePoll } from '@inertiajs/react';
import { AlertTriangle, Search, ScrollText } from 'lucide-react';
import { useState } from 'react';
import { Button, Select, TextField } from '@radix-ui/themes';
import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { StatusAreaChart, type StatusPoint } from '@/components/status-area-chart';
import logs from '@/routes/logs';
import { cn } from '@/lib/utils';

interface LogRow {
    id: number;
    method: string;
    path: string;
    url: string;
    route: string | null;
    status: number | null;
    durationMs: number | null;
    ipAddress: string | null;
    userAgent: string | null;
    hasException: boolean;
    exceptionClass: string | null;
    exceptionMessage: string | null;
    exceptionTrace: string | null;
    requestHeaders: Record<string, string[]> | null;
    requestBody: Record<string, unknown> | null;
    responseBody: string | null;
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
    links: { url: string | null; label: string; active: boolean }[];
}

interface Filters {
    status: string | null;
    range: string;
    q: string | null;
    field: string;
    from: string;
    to: string;
}

interface LogsProps {
    logs: Paginated<LogRow>;
    stats: { total: number; success: number; clientError: number; serverError: number };
    chart: { points: StatusPoint[]; grain: string };
    filters: Filters;
    fieldOptions: string[];
}

const RANGE_LABELS: Record<string, string> = {
    today: 'Today',
    yesterday: 'Yesterday',
    month: 'This month',
    custom: 'Custom range',
};

const FIELD_LABELS: Record<string, string> = {
    all: 'All fields',
    path: 'Endpoint',
    ip: 'IP address',
    method: 'Method',
    status: 'Status code',
    exception: 'Exception',
};

function statusClasses(status: number | null): string {
    if (status === null) return 'bg-neutral-100 text-neutral-600 dark:bg-neutral-500/15 dark:text-neutral-400';
    if (status < 300) return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400';
    if (status < 400) return 'bg-blue-100 text-blue-700 dark:bg-blue-500/15 dark:text-blue-400';
    if (status < 500) return 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400';
    return 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-400';
}

function methodClasses(method: string): string {
    switch (method) {
        case 'GET':
            return 'text-blue-600 dark:text-blue-400';
        case 'POST':
            return 'text-emerald-600 dark:text-emerald-400';
        case 'PUT':
        case 'PATCH':
            return 'text-amber-600 dark:text-amber-400';
        case 'DELETE':
            return 'text-red-600 dark:text-red-400';
        default:
            return 'text-neutral-600 dark:text-neutral-400';
    }
}

function SummaryCard({ label, value, href, active, accent }: { label: string; value: number; href: string; active: boolean; accent: string }) {
    return (
        <Link href={href} preserveScroll>
            <Card
                className={cn(
                    'gap-0 border p-5 transition-colors hover:border-neutral-400 dark:hover:border-neutral-600',
                    active ? 'border-ring ring-1 ring-ring/40' : 'border-sidebar-border/70 dark:border-sidebar-border',
                )}
            >
                <div className="flex items-center gap-2">
                    <span className={cn('h-2 w-2 rounded-full', accent)} />
                    <span className="text-sm font-medium text-neutral-500 dark:text-neutral-400">{label}</span>
                </div>
                <div className="mt-2 text-xl font-bold tabular-nums">{value.toLocaleString()}</div>
            </Card>
        </Link>
    );
}

function prettyJson(value: string): string {
    try {
        return JSON.stringify(JSON.parse(value), null, 2);
    } catch {
        return value;
    }
}

function Section({ title, children }: { title: string; children: React.ReactNode }) {
    return (
        <div>
            <h3 className="mb-1 text-xs font-semibold tracking-wide text-neutral-500 uppercase dark:text-neutral-400">{title}</h3>
            <pre className="max-h-64 overflow-y-auto rounded-md bg-neutral-50 p-3 text-xs whitespace-pre-wrap break-all text-neutral-800 dark:bg-neutral-900 dark:text-neutral-200">
                {children}
            </pre>
        </div>
    );
}

export default function Index({ logs: page, stats, chart, filters, fieldOptions }: LogsProps) {
    const [selected, setSelected] = useState<LogRow | null>(null);
    const [q, setQ] = useState(filters.q ?? '');
    const [field, setField] = useState(filters.field);
    const [from, setFrom] = useState(filters.from);
    const [to, setTo] = useState(filters.to);

    // Keep the view live: refresh logs, summary, and chart in the background.
    usePoll(15000, { only: ['logs', 'stats', 'chart'] });

    // Current filter state as a query object, merged with overrides for navigation.
    const baseQuery: Record<string, string | undefined> = {
        status: filters.status ?? undefined,
        range: filters.range,
        q: filters.q ?? undefined,
        field: filters.field !== 'all' ? filters.field : undefined,
        from: filters.range === 'custom' ? filters.from : undefined,
        to: filters.range === 'custom' ? filters.to : undefined,
    };

    const urlWith = (overrides: Record<string, string | undefined>) => {
        const query = { ...baseQuery, ...overrides };
        Object.keys(query).forEach((k) => {
            if (query[k] === undefined || query[k] === '') delete query[k];
        });
        return logs.index.url({ query });
    };

    const visit = (overrides: Record<string, string | undefined>) =>
        router.visit(urlWith(overrides), { preserveScroll: true, preserveState: true, replace: true });

    const onRangeChange = (range: string) => {
        if (range === 'custom') {
            visit({ range: 'custom', from, to });
        } else {
            visit({ range, from: undefined, to: undefined });
        }
    };

    const submitSearch = (e: React.FormEvent) => {
        e.preventDefault();
        visit({ q: q.trim() || undefined, field: field !== 'all' ? field : undefined });
    };

    return (
        <>
            <Head title="API Logs" />
            <div className="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-6">
                <div>
                    <h1 className="text-lg font-semibold tracking-tight">API Logs</h1>
                    <p className="mt-1 text-xs text-neutral-600 dark:text-neutral-400">
                        Every request to your payment API — captured with its response, timing, and failure traces.
                    </p>
                </div>

                {/* Summary cards */}
                <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                    <SummaryCard label="Total Requests" value={stats.total} href={urlWith({ status: undefined })} active={!filters.status} accent="bg-neutral-400" />
                    <SummaryCard label="Success (2xx)" value={stats.success} href={urlWith({ status: 'success' })} active={filters.status === 'success'} accent="bg-emerald-500" />
                    <SummaryCard label="Client Errors (4xx)" value={stats.clientError} href={urlWith({ status: 'client' })} active={filters.status === 'client'} accent="bg-amber-500" />
                    <SummaryCard label="Server Errors (5xx)" value={stats.serverError} href={urlWith({ status: 'server' })} active={filters.status === 'server'} accent="bg-red-500" />
                </div>

                {/* Logs table */}
                <Card className="gap-0 border border-sidebar-border/70 p-0 dark:border-sidebar-border">
                    {/* Filter bar — scoped to the records below */}
                    <div className="flex flex-col gap-3 border-b border-sidebar-border/50 p-4 lg:flex-row lg:items-center lg:justify-between dark:border-sidebar-border/70">
                        <div className="flex flex-wrap items-center gap-2">
                            <Select.Root value={filters.range} onValueChange={onRangeChange}>
                                <Select.Trigger variant="surface" />
                                <Select.Content>
                                    {Object.entries(RANGE_LABELS).map(([value, label]) => (
                                        <Select.Item key={value} value={value}>
                                            {label}
                                        </Select.Item>
                                    ))}
                                </Select.Content>
                            </Select.Root>

                            {filters.range === 'custom' && (
                                <div className="flex items-center gap-2">
                                    <TextField.Root type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
                                    <span className="text-xs text-neutral-400">to</span>
                                    <TextField.Root type="date" value={to} onChange={(e) => setTo(e.target.value)} />
                                    <Button variant="soft" onClick={() => visit({ range: 'custom', from, to })}>
                                        Apply
                                    </Button>
                                </div>
                            )}
                        </div>

                        <form onSubmit={submitSearch} className="flex items-center gap-2">
                            <Select.Root value={field} onValueChange={setField}>
                                <Select.Trigger variant="surface" />
                                <Select.Content>
                                    {fieldOptions.map((value) => (
                                        <Select.Item key={value} value={value}>
                                            {FIELD_LABELS[value] ?? value}
                                        </Select.Item>
                                    ))}
                                </Select.Content>
                            </Select.Root>
                            <TextField.Root
                                value={q}
                                onChange={(e) => setQ(e.target.value)}
                                placeholder="Search logs…"
                                className="w-56"
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
                            <ScrollText className="mb-2 h-7 w-7" />
                            No API requests match the current filters.
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b border-sidebar-border/50 text-left text-xs text-neutral-500 dark:border-sidebar-border/70 dark:text-neutral-400">
                                        <th className="px-5 py-3 font-medium">When</th>
                                        <th className="px-5 py-3 font-medium">Method</th>
                                        <th className="px-5 py-3 font-medium">Endpoint</th>
                                        <th className="px-5 py-3 font-medium">IP</th>
                                        <th className="px-5 py-3 font-medium">Status</th>
                                        <th className="px-5 py-3 text-right font-medium">Duration</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-sidebar-border/40 dark:divide-sidebar-border/60">
                                    {page.data.map((log) => (
                                        <tr
                                            key={log.id}
                                            onClick={() => setSelected(log)}
                                            className="cursor-pointer hover:bg-neutral-50/60 dark:hover:bg-neutral-900/40"
                                        >
                                            <td className="px-5 py-3 whitespace-nowrap" title={log.createdAt ?? undefined}>
                                                <div className="text-neutral-700 dark:text-neutral-300">{log.createdAtHuman}</div>
                                                <div className="text-xs text-neutral-400 tabular-nums dark:text-neutral-500">{log.createdAt}</div>
                                            </td>
                                            <td className={cn('px-5 py-3 font-mono text-xs font-semibold', methodClasses(log.method))}>{log.method}</td>
                                            <td className="px-5 py-3">
                                                <span className="flex items-center gap-1.5 font-mono text-xs">
                                                    {log.hasException && <AlertTriangle className="h-3.5 w-3.5 shrink-0 text-red-500" />}
                                                    <span className="truncate" title={log.path}>
                                                        {log.path}
                                                    </span>
                                                </span>
                                            </td>
                                            <td className="px-5 py-3 font-mono text-xs text-neutral-500 dark:text-neutral-400">{log.ipAddress ?? '—'}</td>
                                            <td className="px-5 py-3">
                                                <span className={cn('inline-flex rounded-md px-2 py-0.5 text-xs font-medium tabular-nums', statusClasses(log.status))}>
                                                    {log.status ?? '—'}
                                                </span>
                                            </td>
                                            <td className="px-5 py-3 text-right tabular-nums text-neutral-500 dark:text-neutral-400">
                                                {log.durationMs !== null ? `${log.durationMs} ms` : '—'}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}

                    {/* Pagination */}
                    {page.last_page > 1 && (
                        <div className="flex items-center justify-between border-t border-sidebar-border/50 px-5 py-3 text-sm dark:border-sidebar-border/70">
                            <span className="text-neutral-500 dark:text-neutral-400">
                                Showing {page.from ?? 0}–{page.to ?? 0} of {page.total.toLocaleString()}
                            </span>
                            <div className="flex flex-wrap items-center gap-1">
                                {page.links.map((link, i) =>
                                    link.url ? (
                                        <Link
                                            key={i}
                                            href={link.url}
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
                        </div>
                    )}
                </Card>

                {/* Status-code timeline */}
                <Card className="gap-0 border border-sidebar-border/70 p-5 dark:border-sidebar-border">
                    <div className="mb-2">
                        <h2 className="text-sm font-semibold">Response Status Timeline</h2>
                        <p className="text-xs text-neutral-500 dark:text-neutral-400">
                            Responses by status class over {RANGE_LABELS[filters.range]?.toLowerCase() ?? 'the selected range'} ({chart.grain === 'hour' ? 'hourly' : 'daily'})
                        </p>
                    </div>
                    <StatusAreaChart points={chart.points} />
                </Card>
            </div>

            {/* Detail dialog */}
            <Dialog open={!!selected} onOpenChange={(open) => !open && setSelected(null)}>
                <DialogContent className="flex max-h-[85vh] flex-col overflow-hidden sm:max-w-2xl">
                    <DialogHeader className="min-w-0 shrink-0 border-b pr-8 pb-4">
                        <DialogTitle className="flex min-w-0 items-center gap-2">
                            <span className={cn('shrink-0 font-mono text-sm', methodClasses(selected?.method ?? ''))}>{selected?.method}</span>
                            <span className="min-w-0 flex-1 truncate font-mono text-sm">{selected?.path}</span>
                        </DialogTitle>
                        <DialogDescription>
                            {selected?.createdAt} · {selected?.durationMs} ms · IP {selected?.ipAddress ?? 'unknown'}
                        </DialogDescription>
                    </DialogHeader>

                    {selected && (
                        <div className="-mr-2 flex-1 space-y-4 overflow-x-hidden overflow-y-auto pr-2">
                            <div className="flex flex-wrap items-center gap-2">
                                <span className={cn('inline-flex rounded-md px-2 py-0.5 text-xs font-medium', statusClasses(selected.status))}>
                                    HTTP {selected.status ?? '—'}
                                </span>
                                {selected.route && <Badge variant="outline">{selected.route}</Badge>}
                            </div>

                            {selected.hasException && (
                                <div className="rounded-md border border-red-200 bg-red-50 p-3 dark:border-red-500/30 dark:bg-red-500/10">
                                    <div className="flex items-center gap-2 text-sm font-semibold text-red-700 dark:text-red-400">
                                        <AlertTriangle className="h-4 w-4" /> {selected.exceptionClass}
                                    </div>
                                    {selected.exceptionMessage && (
                                        <p className="mt-1 text-xs break-words text-red-700/90 dark:text-red-300">{selected.exceptionMessage}</p>
                                    )}
                                </div>
                            )}

                            {selected.requestBody && Object.keys(selected.requestBody).length > 0 && (
                                <Section title="Request Body">{JSON.stringify(selected.requestBody, null, 2)}</Section>
                            )}
                            {selected.requestHeaders && <Section title="Request Headers">{JSON.stringify(selected.requestHeaders, null, 2)}</Section>}
                            {selected.responseBody && <Section title="Response Body">{prettyJson(selected.responseBody)}</Section>}
                            {selected.exceptionTrace && <Section title="Stack Trace">{selected.exceptionTrace}</Section>}
                        </div>
                    )}
                </DialogContent>
            </Dialog>
        </>
    );
}

Index.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: '/dashboard',
        },
        {
            title: 'Logs',
            href: '/logs',
        },
    ],
};
