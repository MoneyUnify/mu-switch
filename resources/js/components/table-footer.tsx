import { Link, router } from '@inertiajs/react';
import { Select } from '@radix-ui/themes';
import { DisabledTooltip } from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';

export interface PageMeta {
    from: number | null;
    to: number | null;
    total: number;
    per_page: number;
    last_page: number;
    links: { url: string | null; label: string; active: boolean }[];
}

/**
 * Pagination footer for a table: a results summary, an adjustable per-page
 * selector, and numbered page links, all via partial Inertia reloads so each
 * table on a page pages independently.
 */
export function TableFooter({
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
                {perPageOptions.length > 1 && (
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
                )}
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
                            <DisabledTooltip
                                key={i}
                                disabled={true}
                                content={
                                    link.label
                                        .toLowerCase()
                                        .includes('previous')
                                        ? 'You are on the first page'
                                        : link.label
                                                .toLowerCase()
                                                .includes('next')
                                          ? 'You are on the last page'
                                          : undefined
                                }
                            >
                                <span
                                    className="min-w-8 cursor-not-allowed px-2.5 py-1 text-center text-xs text-neutral-300 select-none dark:text-neutral-700"
                                    dangerouslySetInnerHTML={{
                                        __html: link.label,
                                    }}
                                />
                            </DisabledTooltip>
                        ),
                    )}
                </div>
            )}
        </div>
    );
}
