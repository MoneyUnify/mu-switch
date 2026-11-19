import { router } from '@inertiajs/react';
import { Button } from '@radix-ui/themes';
import {
    Check,
    Copy,
    ExternalLink,
    Link2,
    Loader2,
    Plus,
    Trash2,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';
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
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { copyText } from '@/lib/clipboard';
import { csrfToken } from '@/lib/csrf';
import { cn } from '@/lib/utils';
import linksRoute from '@/routes/merchants/links';

export interface PaymentLinkItem {
    id: number;
    code: string;
    label: string | null;
    url: string;
    pay_currencies: string[] | null;
    settle_currency: string;
    amount: string | null;
    reference: string | null;
    is_active: boolean;
    created_at: string | null;
    stats: {
        payments: number;
        in_progress: number;
        settled: string;
        has_payments: boolean;
    };
}

async function call<T>(
    url: string,
    method: string,
    body?: unknown,
): Promise<{
    ok: boolean;
    data: T | null;
    message: string | null;
    errors: Record<string, string[]> | null;
}> {
    const response = await fetch(url, {
        method,
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
        body: body === undefined ? undefined : JSON.stringify(body),
    });
    const json = await response.json().catch(() => ({}));

    return {
        ok: response.ok,
        data: (json.data ?? null) as T | null,
        message: json.message ?? null,
        errors: json.errors ?? null,
    };
}

function formatAmount(amount: string, currency: string): string {
    const [whole, fraction] = amount.split('.');

    return `${currency} ${Number(whole).toLocaleString('en-US')}${fraction ? `.${fraction}` : ''}`;
}

/**
 * Create a pay link: which currencies the customer may pay in, the
 * settlement currency, an optional fixed price and reference, and a label.
 */
function NewLinkForm({
    merchantId,
    payCurrencies,
    settlementCurrencies,
    onCreated,
}: {
    merchantId: number;
    payCurrencies: string[];
    settlementCurrencies: string[];
    onCreated: (link: PaymentLinkItem) => void;
}) {
    const [label, setLabel] = useState('');
    const [payIn, setPayIn] = useState<string[]>(() =>
        payCurrencies.includes('USD') ? ['USD'] : payCurrencies.slice(0, 1),
    );
    const [settleIn, setSettleIn] = useState(settlementCurrencies[0] ?? '');
    const [amount, setAmount] = useState('');
    const [reference, setReference] = useState('');
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [saving, setSaving] = useState(false);

    const toggle = (currency: string) =>
        setPayIn((current) =>
            current.includes(currency)
                ? current.filter((c) => c !== currency)
                : [...current, currency].sort(),
        );

    const create = async () => {
        setSaving(true);
        const result = await call<PaymentLinkItem>(
            linksRoute.store.url(merchantId),
            'POST',
            {
                label: label || null,
                pay_currencies: payIn,
                settle_currency: settleIn,
                amount: amount || null,
                reference: reference || null,
            },
        );
        setSaving(false);

        if (!result.ok || !result.data) {
            setErrors(result.errors ?? {});
            toast.error(result.message ?? 'Could not create the link.');

            return;
        }

        setErrors({});
        setLabel('');
        setAmount('');
        setReference('');
        onCreated(result.data);
        void copyText(result.data.url, 'New link');
    };

    const error = (key: string) => errors[key]?.[0];

    return (
        <div
            className="space-y-3 rounded-xl border border-sidebar-border/70 p-4 text-xs dark:border-sidebar-border"
            data-test="new-pay-link"
        >
            <p className="text-sm font-semibold">New link</p>

            <div className="space-y-1">
                <Label htmlFor={`link-label-${merchantId}`}>
                    Name (optional)
                </Label>
                <Input
                    id={`link-label-${merchantId}`}
                    value={label}
                    maxLength={120}
                    onChange={(e) => setLabel(e.target.value)}
                    placeholder="e.g. Diaspora orders, Invoice 42"
                    className="h-8 text-xs"
                />
            </div>

            <fieldset className="space-y-1.5">
                <legend className="font-medium text-neutral-700 dark:text-neutral-300">
                    Customer pays with PayPal in
                </legend>
                {payCurrencies.length === 0 ? (
                    <p className="text-amber-700 dark:text-amber-300">
                        Add an active PayPal account to accept payments.
                    </p>
                ) : (
                    <div className="flex flex-wrap gap-1.5">
                        {payCurrencies.map((currency) => {
                            const on = payIn.includes(currency);

                            return (
                                <button
                                    key={currency}
                                    type="button"
                                    role="checkbox"
                                    aria-checked={on}
                                    onClick={() => toggle(currency)}
                                    className={cn(
                                        'inline-flex cursor-pointer items-center gap-1 rounded-full border px-2.5 py-1 font-medium transition-colors',
                                        on
                                            ? 'border-neutral-900 bg-neutral-900 text-white dark:border-neutral-100 dark:bg-neutral-100 dark:text-neutral-900'
                                            : 'border-sidebar-border/70 text-neutral-600 hover:bg-neutral-50 dark:border-sidebar-border dark:text-neutral-300 dark:hover:bg-neutral-900',
                                    )}
                                >
                                    {on && <Check className="h-3 w-3" />}
                                    {currency}
                                </button>
                            );
                        })}
                    </div>
                )}
                <p className="text-neutral-500">
                    {payIn.length === 0
                        ? 'None chosen: customers can pay in any of them.'
                        : `Customers choose from ${payIn.join(', ')}.`}
                </p>
            </fieldset>

            <fieldset className="space-y-1.5">
                <legend className="font-medium text-neutral-700 dark:text-neutral-300">
                    Settle the merchant in
                </legend>
                <div className="flex flex-wrap gap-1.5" role="radiogroup">
                    {settlementCurrencies.map((currency) => (
                        <button
                            key={currency}
                            type="button"
                            role="radio"
                            aria-checked={settleIn === currency}
                            onClick={() => setSettleIn(currency)}
                            className={cn(
                                'cursor-pointer rounded-full border px-2.5 py-1 font-medium transition-colors',
                                settleIn === currency
                                    ? 'border-emerald-600 bg-emerald-50 text-emerald-800 dark:border-emerald-500 dark:bg-emerald-950/40 dark:text-emerald-200'
                                    : 'border-sidebar-border/70 text-neutral-600 hover:bg-neutral-50 dark:border-sidebar-border dark:text-neutral-300',
                            )}
                        >
                            {currency}
                        </button>
                    ))}
                </div>
                {settlementCurrencies.length === 1 && (
                    <p className="text-neutral-500">
                        Your payout providers settle this country in{' '}
                        {settlementCurrencies[0]} only.
                    </p>
                )}
            </fieldset>

            <div className="grid grid-cols-2 gap-2">
                <div className="space-y-1">
                    <Label htmlFor={`link-amount-${merchantId}`}>
                        Fixed price ({settleIn}, optional)
                    </Label>
                    <Input
                        id={`link-amount-${merchantId}`}
                        type="number"
                        min="0"
                        step="any"
                        value={amount}
                        onChange={(e) => setAmount(e.target.value)}
                        placeholder="Customer enters it"
                        className="h-8 text-xs"
                    />
                    {error('amount') && (
                        <p className="text-red-600">{error('amount')}</p>
                    )}
                </div>
                <div className="space-y-1">
                    <Label htmlFor={`link-reference-${merchantId}`}>
                        Reference (optional)
                    </Label>
                    <Input
                        id={`link-reference-${merchantId}`}
                        value={reference}
                        maxLength={100}
                        onChange={(e) => setReference(e.target.value)}
                        placeholder="Invoice #"
                        className="h-8 text-xs"
                    />
                </div>
            </div>

            <Button
                size="2"
                onClick={create}
                disabled={saving || payCurrencies.length === 0 || !settleIn}
                style={{ width: '100%' }}
                className="cursor-pointer gap-1.5"
            >
                {saving ? (
                    <Loader2 className="h-3.5 w-3.5 animate-spin" />
                ) : (
                    <Plus className="h-3.5 w-3.5" />
                )}
                Create and copy link
            </Button>
        </div>
    );
}

/**
 * A merchant's pay links: create new ones, and open, copy, disable, enable
 * or delete existing ones. Deleting a link that took payments archives it.
 */
export function MerchantLinks({
    merchantId,
    merchantName,
    linkCount,
    payCurrencies,
    settlementCurrencies,
    reloadOnly,
}: {
    merchantId: number;
    merchantName: string;
    linkCount: number;
    payCurrencies: string[];
    settlementCurrencies: string[];
    reloadOnly: string[];
}) {
    const [open, setOpen] = useState(false);
    const [links, setLinks] = useState<PaymentLinkItem[] | null>(null);
    const [busy, setBusy] = useState<number | null>(null);
    const [removing, setRemoving] = useState<PaymentLinkItem | null>(null);

    const load = useCallback(async () => {
        const result = await call<{ links: PaymentLinkItem[] }>(
            linksRoute.index.url(merchantId),
            'GET',
        );
        setLinks(result.data?.links ?? []);
    }, [merchantId]);

    useEffect(() => {
        if (!open) {
            return;
        }

        const timer = setTimeout(() => void load(), 0);

        return () => clearTimeout(timer);
    }, [open, load]);

    const refreshCounts = () => router.reload({ only: reloadOnly });

    const setActive = async (link: PaymentLinkItem, isActive: boolean) => {
        setBusy(link.id);
        const result = await call<PaymentLinkItem>(
            linksRoute.update.url({ merchant: merchantId, link: link.code }),
            'PATCH',
            { is_active: isActive },
        );
        setBusy(null);

        if (result.ok && result.data) {
            const updated = result.data;
            setLinks((current) =>
                (current ?? []).map((item) =>
                    item.id === updated.id ? updated : item,
                ),
            );
            toast.success(result.message ?? 'Saved.');
            refreshCounts();
        } else {
            toast.error(result.message ?? 'Could not update the link.');
        }
    };

    const remove = async (link: PaymentLinkItem) => {
        setBusy(link.id);
        const result = await call<{ outcome: string }>(
            linksRoute.destroy.url({ merchant: merchantId, link: link.code }),
            'DELETE',
        );
        setBusy(null);
        setRemoving(null);

        if (result.ok) {
            setLinks((current) =>
                (current ?? []).filter((item) => item.id !== link.id),
            );
            toast.success(result.message ?? 'Removed.');
            refreshCounts();
        } else {
            toast.error(result.message ?? 'Could not remove the link.');
        }
    };

    const active = useMemo(
        () => (links ?? []).filter((link) => link.is_active).length,
        [links],
    );

    return (
        <>
            <Button
                size="1"
                variant="soft"
                onClick={() => setOpen(true)}
                className="cursor-pointer gap-1"
            >
                <Link2 className="h-3 w-3" /> Links ({linkCount})
            </Button>

            <Sheet open={open} onOpenChange={setOpen}>
                <SheetContent className="w-full gap-0 overflow-y-auto sm:max-w-lg">
                    <SheetHeader>
                        <SheetTitle>{merchantName} · pay links</SheetTitle>
                        <SheetDescription>
                            {links === null
                                ? 'Loading…'
                                : `${links.length} link${links.length === 1 ? '' : 's'}, ${active} active. Each link has its own currencies and price.`}
                        </SheetDescription>
                    </SheetHeader>

                    <div className="space-y-4 px-4 pb-6">
                        <NewLinkForm
                            merchantId={merchantId}
                            payCurrencies={payCurrencies}
                            settlementCurrencies={settlementCurrencies}
                            onCreated={(link) => {
                                setLinks((current) => [
                                    link,
                                    ...(current ?? []),
                                ]);
                                refreshCounts();
                            }}
                        />

                        {links === null ? (
                            <p className="flex items-center gap-2 text-xs text-neutral-500">
                                <Loader2 className="h-3.5 w-3.5 animate-spin" />
                                Loading links…
                            </p>
                        ) : links.length === 0 ? (
                            <p className="py-6 text-center text-xs text-neutral-500">
                                No links yet. Create one above.
                            </p>
                        ) : (
                            <ul
                                className="divide-y divide-sidebar-border/50 dark:divide-sidebar-border/70"
                                data-test="pay-links"
                            >
                                {links.map((link) => (
                                    <li
                                        key={link.id}
                                        className="space-y-2 py-3"
                                    >
                                        <div className="flex items-start justify-between gap-3">
                                            <div className="min-w-0">
                                                <div className="flex flex-wrap items-center gap-2 text-sm">
                                                    <span className="truncate font-medium">
                                                        {link.label ??
                                                            'Untitled link'}
                                                    </span>
                                                    <span
                                                        className={cn(
                                                            'rounded-md px-1.5 py-0.5 text-[10px] font-medium',
                                                            link.is_active
                                                                ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300'
                                                                : 'bg-neutral-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-400',
                                                        )}
                                                    >
                                                        {link.is_active
                                                            ? 'Active'
                                                            : 'Disabled'}
                                                    </span>
                                                </div>
                                                <div className="mt-0.5 text-xs text-neutral-500">
                                                    Pays in{' '}
                                                    {link.pay_currencies?.join(
                                                        ', ',
                                                    ) ?? 'any currency'}{' '}
                                                    · settles{' '}
                                                    {link.settle_currency}
                                                    {link.amount &&
                                                        ` · ${formatAmount(link.amount, link.settle_currency)} fixed`}
                                                    {link.reference &&
                                                        ` · ${link.reference}`}
                                                </div>
                                            </div>
                                            <div className="shrink-0 text-right text-xs">
                                                <div className="font-semibold tabular-nums">
                                                    {formatAmount(
                                                        link.stats.settled,
                                                        link.settle_currency,
                                                    )}
                                                </div>
                                                <div className="text-neutral-500">
                                                    {link.stats.payments} paid
                                                    {link.stats.in_progress >
                                                        0 &&
                                                        ` · ${link.stats.in_progress} in progress`}
                                                </div>
                                            </div>
                                        </div>

                                        <div className="flex items-center gap-1.5">
                                            <Input
                                                readOnly
                                                value={link.url}
                                                onFocus={(e) =>
                                                    e.target.select()
                                                }
                                                aria-label={`URL of ${link.label ?? 'this link'}`}
                                                className="h-7 flex-1 font-mono text-[11px]"
                                            />
                                            <Button
                                                size="1"
                                                variant="soft"
                                                onClick={() =>
                                                    copyText(link.url)
                                                }
                                                aria-label="Copy link"
                                                className="cursor-pointer"
                                            >
                                                <Copy className="h-3 w-3" />
                                            </Button>
                                            <Button
                                                size="1"
                                                variant="soft"
                                                color="gray"
                                                asChild
                                            >
                                                <a
                                                    href={link.url}
                                                    target="_blank"
                                                    rel="noreferrer"
                                                    aria-label="Open link"
                                                >
                                                    <ExternalLink className="h-3 w-3" />
                                                </a>
                                            </Button>
                                            <Button
                                                size="1"
                                                variant="soft"
                                                color={
                                                    link.is_active
                                                        ? 'gray'
                                                        : 'green'
                                                }
                                                disabled={busy === link.id}
                                                onClick={() =>
                                                    setActive(
                                                        link,
                                                        !link.is_active,
                                                    )
                                                }
                                                className="cursor-pointer"
                                            >
                                                {link.is_active
                                                    ? 'Disable'
                                                    : 'Enable'}
                                            </Button>
                                            <Button
                                                size="1"
                                                variant="soft"
                                                color="red"
                                                disabled={busy === link.id}
                                                onClick={() =>
                                                    setRemoving(link)
                                                }
                                                aria-label="Delete link"
                                                className="cursor-pointer"
                                            >
                                                <Trash2 className="h-3 w-3" />
                                            </Button>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                    {/* Inside the sheet, so it opens above it and stays clickable. */}
                    <Dialog
                        open={removing !== null}
                        onOpenChange={(isOpen) => !isOpen && setRemoving(null)}
                    >
                        <DialogContent className="sm:max-w-md">
                            <DialogHeader>
                                <DialogTitle>
                                    Delete “{removing?.label ?? 'this link'}”?
                                </DialogTitle>
                                <DialogDescription>
                                    {removing?.stats.has_payments
                                        ? 'This link has taken payments, so it will be archived: it stops working and leaves this list, and its payments keep their full history.'
                                        : 'This link has never taken a payment. It will be deleted and stop working.'}
                                </DialogDescription>
                            </DialogHeader>
                            <DialogFooter className="gap-2">
                                <Button
                                    variant="soft"
                                    color="gray"
                                    onClick={() => setRemoving(null)}
                                    className="cursor-pointer"
                                >
                                    Keep it
                                </Button>
                                <Button
                                    color="red"
                                    onClick={() => removing && remove(removing)}
                                    disabled={busy !== null}
                                    className="cursor-pointer"
                                >
                                    {removing?.stats.has_payments
                                        ? 'Archive link'
                                        : 'Delete link'}
                                </Button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>
                </SheetContent>
            </Sheet>
        </>
    );
}
