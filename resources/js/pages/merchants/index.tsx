import { Head, Link, router, useForm } from '@inertiajs/react';
import { Button } from '@radix-ui/themes';
import { Pencil, Plus, Store, TrendingDown, Wallet } from 'lucide-react';
import type { FormEvent, ReactNode } from 'react';
import { useState } from 'react';
import MerchantController from '@/actions/App/Http/Controllers/MerchantController';
import InputError from '@/components/input-error';
import { MerchantLinks } from '@/components/merchant-links';
import { MerchantMcpAccess } from '@/components/merchant-mcp-access';
import type { McpAccess } from '@/components/merchant-mcp-access';
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
import merchantsRoute from '@/routes/merchants';
import providersRoute from '@/routes/providers';

interface Merchant {
    id: number;
    name: string;
    slug: string;
    email: string | null;
    country: string;
    country_name: string;
    currency: string;
    wallet_number: string;
    wallet_name: string | null;
    network: string | null;
    commission_percent: string;
    is_active: boolean;
    pay_url: string;
    settlement_currencies: string[];
    links_count: number;
    mcp: McpAccess;
    stats: {
        payments: number;
        settled: string;
        commission: string;
        in_progress: number;
        refunded: number;
    };
}

interface Settlement {
    reference: string;
    merchant: string | null;
    status: string;
    status_label: string;
    sender_pays: { amount: string; currency: string };
    recipient_gets: { amount: string; currency: string };
    commission: string;
    created_at: string | null;
}

interface Impact {
    merchants: number;
    active_merchants: number;
    paid_merchants: number;
    payments: number;
    settled: Array<{
        currency: string;
        settled: string;
        commission: string;
        payments: number;
    }>;
    collected: Array<{
        currency: string;
        collected: string;
        fx_margin: string;
    }>;
    average_cost_percent: number | null;
    benchmark_cost_percent: number | null;
}

interface Props {
    merchants: PageMeta & {
        data: Merchant[];
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    filters: { q: string | null };
    payCurrencies: string[];
    impact: Impact;
    recent: Settlement[];
    countries: Array<{ code: string; name: string; currency: string | null }>;
    mcpEndpoint: string;
}

const ONLY = ['merchants', 'filters', 'impact', 'recent', 'payCurrencies'];

const selectClassName =
    'flex h-9 w-full rounded-full border border-input bg-transparent px-3.5 py-1 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

function money(amount: string, currency: string): string {
    const [whole, fraction] = amount.split('.');

    return `${currency} ${Number(whole).toLocaleString('en-US')}${fraction ? `.${fraction}` : ''}`;
}

function Stat({
    label,
    icon,
    children,
    footer,
}: {
    label: string;
    icon: ReactNode;
    children: ReactNode;
    footer?: ReactNode;
}) {
    return (
        <Card className="gap-0 border border-sidebar-border/70 p-4 dark:border-sidebar-border">
            <div className="flex items-center justify-between text-xs text-neutral-500">
                {label}
                <span className="text-neutral-400">{icon}</span>
            </div>
            <div className="mt-2 text-lg font-semibold tabular-nums">
                {children}
            </div>
            {footer && (
                <div className="mt-1 text-[11px] text-neutral-500">
                    {footer}
                </div>
            )}
        </Card>
    );
}

const emptyForm = {
    name: '',
    email: '',
    country: '',
    wallet_number: '',
    wallet_name: '',
    network: '',
    commission_percent: '2',
    is_active: true,
};

export default function Merchants({
    merchants,
    filters,
    payCurrencies,
    impact,
    recent,
    countries,
    mcpEndpoint,
}: Props) {
    const [editing, setEditing] = useState<Merchant | 'new' | null>(null);
    const form = useForm({ ...emptyForm, country: countries[0]?.code ?? '' });

    const open = (merchant: Merchant | 'new') => {
        form.clearErrors();
        form.setData(
            merchant === 'new'
                ? { ...emptyForm, country: countries[0]?.code ?? '' }
                : {
                      name: merchant.name,
                      email: merchant.email ?? '',
                      country: merchant.country,
                      wallet_number: merchant.wallet_number,
                      wallet_name: merchant.wallet_name ?? '',
                      network: merchant.network ?? '',
                      commission_percent: merchant.commission_percent,
                      is_active: merchant.is_active,
                  },
        );
        setEditing(merchant);
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const options = {
            preserveScroll: true,
            onSuccess: () => setEditing(null),
        };

        if (editing === 'new') {
            form.post(MerchantController.store.url(), options);
        } else if (editing) {
            form.put(MerchantController.update.url(editing.id), options);
        }
    };

    const toggle = (merchant: Merchant) =>
        router.put(
            MerchantController.update.url(merchant.id),
            { is_active: !merchant.is_active },
            { preserveScroll: true, only: ONLY },
        );

    const saving =
        impact.average_cost_percent !== null &&
        impact.benchmark_cost_percent !== null
            ? impact.benchmark_cost_percent - impact.average_cost_percent
            : null;

    return (
        <>
            <Head title="Merchants" />
            <div className="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-lg font-semibold tracking-tight">
                            Merchants
                        </h1>
                        <p className="mt-1 max-w-2xl text-xs text-neutral-600 dark:text-neutral-400">
                            Businesses you collect for. Their customers pay from
                            anywhere with PayPal, and each merchant is settled
                            straight to its mobile-money wallet, in its own
                            currency, minus your commission.
                        </p>
                    </div>
                    <Button
                        onClick={() => open('new')}
                        disabled={countries.length === 0}
                        className="shrink-0 cursor-pointer gap-2"
                    >
                        <Plus className="h-4 w-4" /> Add merchant
                    </Button>
                </div>

                {countries.length === 0 && (
                    <div className="flex flex-col gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm sm:flex-row sm:items-center sm:justify-between dark:border-amber-900/60 dark:bg-amber-950/30">
                        <p className="text-amber-900 dark:text-amber-200">
                            Add a payout provider (e.g. Lenco or pawaPay) so
                            merchants can be settled to mobile money.
                        </p>
                        <Button asChild size="1" className="shrink-0">
                            <Link href={providersRoute.index.url()}>
                                Open Providers
                            </Link>
                        </Button>
                    </div>
                )}

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <Stat
                        label="Merchants"
                        icon={<Store className="h-4 w-4" />}
                        footer={`${impact.active_merchants} active · ${impact.paid_merchants} paid so far`}
                    >
                        {impact.merchants}
                    </Stat>
                    <Stat
                        label="Payments settled"
                        icon={<Wallet className="h-4 w-4" />}
                        footer="Delivered to merchants' wallets"
                    >
                        {impact.payments}
                    </Stat>
                    <Stat
                        label="Settled to mobile money"
                        icon={<Wallet className="h-4 w-4" />}
                        footer={
                            impact.settled.length > 0
                                ? impact.settled
                                      .map(
                                          (row) =>
                                              `${money(row.commission, row.currency)} commission`,
                                      )
                                      .join(' · ')
                                : 'Nothing settled yet'
                        }
                    >
                        {impact.settled.length > 0
                            ? impact.settled
                                  .map((row) =>
                                      money(row.settled, row.currency),
                                  )
                                  .join(' · ')
                            : '—'}
                    </Stat>
                    <Stat
                        label="Average cost to merchants"
                        icon={<TrendingDown className="h-4 w-4" />}
                        footer={
                            saving !== null
                                ? `${saving > 0 ? `${saving.toFixed(2)} pts below` : `${Math.abs(saving).toFixed(2)} pts above`} the ${impact.benchmark_cost_percent}% benchmark`
                                : 'FX margin and commission, all in'
                        }
                    >
                        {impact.average_cost_percent !== null
                            ? `${impact.average_cost_percent}%`
                            : '—'}
                    </Stat>
                </div>

                <Card className="gap-0 border border-sidebar-border/70 p-5 dark:border-sidebar-border">
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h2 className="text-sm font-semibold">
                                Your merchants
                            </h2>
                            <p className="mt-0.5 text-xs text-neutral-500">
                                Share a merchant&apos;s pay link, or charge its
                                customers through the API.
                            </p>
                        </div>
                        <TableSearch
                            param="q"
                            pageName="page"
                            only={ONLY}
                            initial={filters.q}
                            placeholder="Search merchants…"
                        />
                    </div>

                    {merchants.data.length === 0 ? (
                        <div className="flex flex-col items-center py-12 text-center text-sm text-neutral-500">
                            <Store className="mb-2 h-6 w-6 text-neutral-400" />
                            {filters.q
                                ? `No merchants match “${filters.q}”.`
                                : 'No merchants yet. Add one to get its pay link.'}
                        </div>
                    ) : (
                        <ul className="mt-4 divide-y divide-sidebar-border/50 dark:divide-sidebar-border/70">
                            {merchants.data.map((merchant) => (
                                <li
                                    key={merchant.id}
                                    className="flex flex-col gap-3 py-3 lg:flex-row lg:items-center"
                                >
                                    <div className="min-w-0 flex-1">
                                        <div className="flex flex-wrap items-center gap-2 text-sm">
                                            <span className="font-medium">
                                                {merchant.name}
                                            </span>
                                            <span
                                                className={cn(
                                                    'rounded-md px-1.5 py-0.5 text-[10px] font-medium',
                                                    merchant.is_active
                                                        ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300'
                                                        : 'bg-neutral-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-400',
                                                )}
                                            >
                                                {merchant.is_active
                                                    ? 'Accepting payments'
                                                    : 'Paused'}
                                            </span>
                                        </div>
                                        <div className="mt-0.5 text-xs text-neutral-500">
                                            <span aria-hidden>
                                                {countryFlag(merchant.country)}
                                            </span>{' '}
                                            Settles {merchant.currency} to{' '}
                                            {merchant.wallet_number}
                                            {merchant.network &&
                                                ` (${merchant.network})`}{' '}
                                            · {merchant.commission_percent}%
                                            commission
                                        </div>
                                    </div>
                                    <div className="grid shrink-0 grid-cols-3 gap-4 text-right text-xs">
                                        <div>
                                            <div className="text-neutral-500">
                                                Payments
                                            </div>
                                            <div className="font-semibold tabular-nums">
                                                {merchant.stats.payments}
                                                {merchant.stats.in_progress >
                                                    0 &&
                                                    ` (+${merchant.stats.in_progress})`}
                                            </div>
                                        </div>
                                        <div>
                                            <div className="text-neutral-500">
                                                Settled
                                            </div>
                                            <div className="font-semibold tabular-nums">
                                                {money(
                                                    merchant.stats.settled,
                                                    merchant.currency,
                                                )}
                                            </div>
                                        </div>
                                        <div>
                                            <div className="text-neutral-500">
                                                Commission
                                            </div>
                                            <div className="font-semibold tabular-nums">
                                                {money(
                                                    merchant.stats.commission,
                                                    merchant.currency,
                                                )}
                                            </div>
                                        </div>
                                    </div>
                                    <div className="flex shrink-0 flex-wrap gap-1.5">
                                        <MerchantLinks
                                            merchantId={merchant.id}
                                            merchantName={merchant.name}
                                            linkCount={merchant.links_count}
                                            payCurrencies={payCurrencies}
                                            settlementCurrencies={
                                                merchant.settlement_currencies
                                            }
                                            reloadOnly={ONLY}
                                        />
                                        <MerchantMcpAccess
                                            merchantId={merchant.id}
                                            merchantName={merchant.name}
                                            access={merchant.mcp}
                                            endpoint={mcpEndpoint}
                                            reloadOnly={['merchants']}
                                        />
                                        <Button
                                            size="1"
                                            variant="soft"
                                            color="gray"
                                            onClick={() => open(merchant)}
                                            className="cursor-pointer gap-1"
                                        >
                                            <Pencil className="h-3 w-3" /> Edit
                                        </Button>
                                        <Button
                                            size="1"
                                            variant="soft"
                                            color={
                                                merchant.is_active
                                                    ? 'gray'
                                                    : 'green'
                                            }
                                            onClick={() => toggle(merchant)}
                                            className="cursor-pointer"
                                        >
                                            {merchant.is_active
                                                ? 'Pause'
                                                : 'Resume'}
                                        </Button>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}

                    {merchants.last_page > 1 && (
                        <div className="mt-4 flex items-center justify-between text-xs text-neutral-500">
                            <span>
                                {merchants.from}–{merchants.to} of{' '}
                                {merchants.total}
                            </span>
                            <div className="flex gap-2">
                                {merchants.prev_page_url && (
                                    <Link
                                        href={merchants.prev_page_url}
                                        only={ONLY}
                                        preserveScroll
                                        className="hover:text-neutral-800"
                                    >
                                        Previous
                                    </Link>
                                )}
                                {merchants.next_page_url && (
                                    <Link
                                        href={merchants.next_page_url}
                                        only={ONLY}
                                        preserveScroll
                                        className="hover:text-neutral-800"
                                    >
                                        Next
                                    </Link>
                                )}
                            </div>
                        </div>
                    )}
                </Card>

                <Card className="gap-0 border border-sidebar-border/70 p-5 dark:border-sidebar-border">
                    <h2 className="text-sm font-semibold">
                        Recent merchant payments
                    </h2>
                    <p className="mt-0.5 text-xs text-neutral-500">
                        Paid with PayPal, settled to each merchant&apos;s
                        wallet. Failed settlements are refunded to the customer
                        automatically.
                    </p>
                    {recent.length === 0 ? (
                        <p className="py-8 text-center text-sm text-neutral-500">
                            No merchant payments yet.
                        </p>
                    ) : (
                        <ul className="mt-3 divide-y divide-sidebar-border/50 text-sm dark:divide-sidebar-border/70">
                            {recent.map((payment) => (
                                <li
                                    key={payment.reference}
                                    className="flex items-center justify-between gap-3 py-2"
                                >
                                    <div className="min-w-0">
                                        <div className="truncate font-medium">
                                            {payment.merchant}
                                        </div>
                                        <div className="text-xs text-neutral-500">
                                            {money(
                                                payment.sender_pays.amount,
                                                payment.sender_pays.currency,
                                            )}{' '}
                                            via PayPal · {payment.status_label}
                                        </div>
                                    </div>
                                    <div className="shrink-0 text-right">
                                        <div className="font-semibold tabular-nums">
                                            {money(
                                                payment.recipient_gets.amount,
                                                payment.recipient_gets.currency,
                                            )}
                                        </div>
                                        <div className="text-[11px] text-neutral-500 tabular-nums">
                                            +
                                            {money(
                                                payment.commission,
                                                payment.recipient_gets.currency,
                                            )}{' '}
                                            commission
                                        </div>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
            </div>

            <Dialog
                open={editing !== null}
                onOpenChange={(isOpen) => !isOpen && setEditing(null)}
            >
                <DialogContent className="sm:max-w-[520px]">
                    <form onSubmit={submit} className="space-y-4">
                        <DialogHeader>
                            <DialogTitle>
                                {editing === 'new' || editing === null
                                    ? 'Add merchant'
                                    : `Edit ${editing.name}`}
                            </DialogTitle>
                            <DialogDescription>
                                Payments to this merchant settle to the wallet
                                below, in its country&apos;s currency.
                            </DialogDescription>
                        </DialogHeader>

                        <div className="grid grid-cols-2 gap-3">
                            <div className="col-span-2 space-y-1">
                                <Label htmlFor="merchant-name">
                                    Business name
                                </Label>
                                <Input
                                    id="merchant-name"
                                    value={form.data.name}
                                    onChange={(e) =>
                                        form.setData('name', e.target.value)
                                    }
                                />
                                <InputError message={form.errors.name} />
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="merchant-country">
                                    Settle in
                                </Label>
                                <select
                                    id="merchant-country"
                                    value={form.data.country}
                                    onChange={(e) =>
                                        form.setData('country', e.target.value)
                                    }
                                    className={selectClassName}
                                >
                                    {countries.map((country) => (
                                        <option
                                            key={country.code}
                                            value={country.code}
                                        >
                                            {countryFlag(country.code)}{' '}
                                            {country.name} ({country.currency})
                                        </option>
                                    ))}
                                </select>
                                <InputError message={form.errors.country} />
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="merchant-commission">
                                    Your commission (%)
                                </Label>
                                <Input
                                    id="merchant-commission"
                                    type="number"
                                    min="0"
                                    max="20"
                                    step="0.01"
                                    value={form.data.commission_percent}
                                    onChange={(e) =>
                                        form.setData(
                                            'commission_percent',
                                            e.target.value,
                                        )
                                    }
                                />
                                <InputError
                                    message={form.errors.commission_percent}
                                />
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="merchant-wallet">
                                    Mobile-money number
                                </Label>
                                <Input
                                    id="merchant-wallet"
                                    value={form.data.wallet_number}
                                    onChange={(e) =>
                                        form.setData(
                                            'wallet_number',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="0977 123 456"
                                />
                                <InputError
                                    message={form.errors.wallet_number}
                                />
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="merchant-network">
                                    Network (optional)
                                </Label>
                                <Input
                                    id="merchant-network"
                                    value={form.data.network}
                                    onChange={(e) =>
                                        form.setData('network', e.target.value)
                                    }
                                    placeholder="Detected from the number"
                                />
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="merchant-wallet-name">
                                    Wallet name (optional)
                                </Label>
                                <Input
                                    id="merchant-wallet-name"
                                    value={form.data.wallet_name}
                                    onChange={(e) =>
                                        form.setData(
                                            'wallet_name',
                                            e.target.value,
                                        )
                                    }
                                />
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="merchant-email">
                                    Email (optional)
                                </Label>
                                <Input
                                    id="merchant-email"
                                    type="email"
                                    value={form.data.email}
                                    onChange={(e) =>
                                        form.setData('email', e.target.value)
                                    }
                                />
                                <InputError message={form.errors.email} />
                            </div>
                        </div>

                        <DialogFooter>
                            <Button
                                type="button"
                                variant="soft"
                                color="gray"
                                onClick={() => setEditing(null)}
                                className="cursor-pointer"
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                disabled={form.processing}
                                className="cursor-pointer"
                            >
                                {editing === 'new' ? 'Add merchant' : 'Save'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}

Merchants.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'Merchants', href: merchantsRoute.index.url() },
    ],
};
