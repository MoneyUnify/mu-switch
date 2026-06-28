import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, ArrowLeft, ChevronLeft, ChevronRight, ScrollText } from 'lucide-react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import providersRoute from '@/routes/providers';
import { cn } from '@/lib/utils';

interface ProviderLogRow {
    id: number;
    method: string;
    url: string;
    host: string | null;
    path: string;
    status: number | null;
    durationMs: number | null;
    failed: boolean;
    errorMessage: string | null;
    requestHeaders: Record<string, string[]> | null;
    requestBody: string | null;
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
    prev_page_url: string | null;
    next_page_url: string | null;
    links: { url: string | null; label: string; active: boolean }[];
}

interface ProviderLogsProps {
    provider: { id: number; name: string; class: string; logo_url: string | null; is_active: boolean };
    logs: Paginated<ProviderLogRow>;
    stats: { total: number; successful: number; failed: number };
}

function statusClasses(status: number | null, failed: boolean): string {
    if (failed || status === null) return 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-400';
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

function PrevNext({ prevUrl, nextUrl }: { prevUrl: string | null; nextUrl: string | null }) {
    const base = 'inline-flex items-center gap-1 rounded-md border px-2.5 py-1 text-xs font-medium transition-colors';
    const enabled = 'border-sidebar-border/70 text-neutral-600 hover:bg-neutral-100 dark:border-sidebar-border dark:text-neutral-300 dark:hover:bg-neutral-800';
    const disabled = 'cursor-not-allowed border-sidebar-border/40 text-neutral-300 dark:border-sidebar-border/60 dark:text-neutral-700';

    return (
        <div className="flex items-center gap-1.5">
            {prevUrl ? (
                <Link href={prevUrl} preserveScroll preserveState className={cn(base, enabled)}>
                    <ChevronLeft className="h-3.5 w-3.5" /> Previous
                </Link>
            ) : (
                <span className={cn(base, disabled)}>
                    <ChevronLeft className="h-3.5 w-3.5" /> Previous
                </span>
            )}
            {nextUrl ? (
                <Link href={nextUrl} preserveScroll preserveState className={cn(base, enabled)}>
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

export default function ProviderLogs({ provider, logs: page, stats }: ProviderLogsProps) {
    const [selected, setSelected] = useState<ProviderLogRow | null>(null);

    return (
        <>
            <Head title={`${provider.name} · Call Logs`} />
            <div className="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-6">
                <div className="flex flex-col gap-2">
                    <Link
                        href={providersRoute.index().url}
                        className="inline-flex w-fit items-center gap-1 text-xs text-neutral-500 hover:text-neutral-800 dark:text-neutral-400 dark:hover:text-neutral-200"
                    >
                        <ArrowLeft className="h-3.5 w-3.5" /> Back to providers
                    </Link>
                    <div className="flex flex-wrap items-center gap-3">
                        {provider.logo_url && (
                            <img src={provider.logo_url} alt="" className="h-8 w-8 rounded object-contain" />
                        )}
                        <div>
                            <h1 className="text-lg font-semibold tracking-tight">{provider.name} — Call Logs</h1>
                            <p className="mt-0.5 text-xs text-neutral-600 dark:text-neutral-400">
                                Every outgoing request the switch made to this gateway, with its response and timing.
                            </p>
                        </div>
                        <Badge variant={provider.is_active ? 'default' : 'secondary'} className="ml-auto">
                            {provider.is_active ? 'Active' : 'Inactive'}
                        </Badge>
                    </div>
                </div>

                {/* Summary */}
                <div className="grid gap-6 sm:grid-cols-3">
                    <Card className="gap-0 border border-sidebar-border/70 p-5 dark:border-sidebar-border">
                        <span className="flex items-center gap-2 text-sm font-medium text-neutral-500 dark:text-neutral-400">
                            <span className="h-2 w-2 rounded-full bg-neutral-400" /> Total Calls
                        </span>
                        <div className="mt-2 text-xl font-bold tabular-nums">{stats.total.toLocaleString()}</div>
                    </Card>
                    <Card className="gap-0 border border-sidebar-border/70 p-5 dark:border-sidebar-border">
                        <span className="flex items-center gap-2 text-sm font-medium text-neutral-500 dark:text-neutral-400">
                            <span className="h-2 w-2 rounded-full bg-emerald-500" /> Successful (2xx)
                        </span>
                        <div className="mt-2 text-xl font-bold tabular-nums">{stats.successful.toLocaleString()}</div>
                    </Card>
                    <Card className="gap-0 border border-sidebar-border/70 p-5 dark:border-sidebar-border">
                        <span className="flex items-center gap-2 text-sm font-medium text-neutral-500 dark:text-neutral-400">
                            <span className="h-2 w-2 rounded-full bg-red-500" /> Failed Calls
                        </span>
                        <div className="mt-2 text-xl font-bold tabular-nums">{stats.failed.toLocaleString()}</div>
                    </Card>
                </div>

                {/* Calls table */}
                <Card className="gap-0 border border-sidebar-border/70 p-0 dark:border-sidebar-border">
                    {page.data.length === 0 ? (
                        <div className="flex flex-col items-center justify-center py-16 text-center text-sm text-neutral-400">
                            <ScrollText className="mb-2 h-7 w-7" />
                            No calls have been made to this provider yet.
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b border-sidebar-border/50 text-left text-xs text-neutral-500 dark:border-sidebar-border/70 dark:text-neutral-400">
                                        <th className="px-5 py-3 font-medium">When</th>
                                        <th className="px-5 py-3 font-medium">Method</th>
                                        <th className="px-5 py-3 font-medium">Endpoint</th>
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
                                                    {log.failed && <AlertTriangle className="h-3.5 w-3.5 shrink-0 text-red-500" />}
                                                    <span className="truncate" title={log.url}>
                                                        {log.path}
                                                    </span>
                                                </span>
                                                {log.host && <div className="mt-0.5 text-[11px] text-neutral-400 dark:text-neutral-500">{log.host}</div>}
                                            </td>
                                            <td className="px-5 py-3">
                                                <span className={cn('inline-flex rounded-md px-2 py-0.5 text-xs font-medium tabular-nums', statusClasses(log.status, log.failed))}>
                                                    {log.status ?? 'ERR'}
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
                    {page.total > 0 && (
                        <div className="flex flex-col gap-3 border-t border-sidebar-border/50 px-5 py-3 text-sm sm:flex-row sm:items-center sm:justify-between dark:border-sidebar-border/70">
                            <span className="text-neutral-500 dark:text-neutral-400">
                                Showing <span className="font-medium text-neutral-700 tabular-nums dark:text-neutral-300">{page.from ?? 0}</span>–
                                <span className="font-medium text-neutral-700 tabular-nums dark:text-neutral-300">{page.to ?? 0}</span> of{' '}
                                <span className="font-medium text-neutral-700 tabular-nums dark:text-neutral-300">{page.total.toLocaleString()}</span>
                            </span>

                            <div className="flex flex-wrap items-center gap-1.5">
                                {page.last_page > 1 && (
                                    <div className="flex flex-wrap items-center gap-1">
                                        {page.links.slice(1, -1).map((link, i) =>
                                            link.url ? (
                                                <Link
                                                    key={i}
                                                    href={link.url}
                                                    preserveScroll
                                                    preserveState
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
                                <PrevNext prevUrl={page.prev_page_url} nextUrl={page.next_page_url} />
                            </div>
                        </div>
                    )}
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
                        <DialogDescription className="truncate">
                            {selected?.createdAt} · {selected?.durationMs !== null ? `${selected?.durationMs} ms` : 'no timing'} · {selected?.url}
                        </DialogDescription>
                    </DialogHeader>

                    {selected && (
                        <div className="-mr-2 flex-1 space-y-4 overflow-x-hidden overflow-y-auto pr-2">
                            <div className="flex flex-wrap items-center gap-2">
                                <span className={cn('inline-flex rounded-md px-2 py-0.5 text-xs font-medium', statusClasses(selected.status, selected.failed))}>
                                    {selected.status !== null ? `HTTP ${selected.status}` : 'No response'}
                                </span>
                            </div>

                            {selected.failed && selected.errorMessage && (
                                <div className="rounded-md border border-red-200 bg-red-50 p-3 dark:border-red-500/30 dark:bg-red-500/10">
                                    <div className="flex items-center gap-2 text-sm font-semibold text-red-700 dark:text-red-400">
                                        <AlertTriangle className="h-4 w-4" /> Call failed
                                    </div>
                                    <p className="mt-1 text-xs break-words text-red-700/90 dark:text-red-300">{selected.errorMessage}</p>
                                </div>
                            )}

                            {selected.requestHeaders && Object.keys(selected.requestHeaders).length > 0 && (
                                <Section title="Request Headers">{JSON.stringify(selected.requestHeaders, null, 2)}</Section>
                            )}
                            {selected.requestBody && <Section title="Request Body">{prettyJson(selected.requestBody)}</Section>}
                            {selected.responseBody && <Section title="Response Body">{prettyJson(selected.responseBody)}</Section>}
                        </div>
                    )}
                </DialogContent>
            </Dialog>
        </>
    );
}

ProviderLogs.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'Providers', href: '/providers' },
        { title: 'Call Logs', href: '#' },
    ],
};
