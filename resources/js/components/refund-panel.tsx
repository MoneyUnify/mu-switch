import { router } from '@inertiajs/react';
import { Button } from '@radix-ui/themes';
import { Undo2 } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import payments from '@/routes/payments';

export interface RefundSummary {
    refunded: string;
    refundable: string;
    canRefund: boolean;
    blockedReason: string | null;
    items: Array<{
        id: string;
        amount: string;
        status: string;
        reason: string | null;
        fromPaypal: boolean;
        createdAt: string | null;
    }>;
}

const statusTone: Record<string, string> = {
    success:
        'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300',
    pending:
        'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300',
    failed: 'bg-red-50 text-red-700 dark:bg-red-950/40 dark:text-red-300',
};

/**
 * A payment's refunds, and a form to refund it in full or in part.
 */
export function RefundPanel({
    reference,
    currency,
    refund,
    onlyReload,
}: {
    reference: string;
    currency: string;
    refund: RefundSummary;
    onlyReload: string[];
}) {
    const [amount, setAmount] = useState(refund.refundable);
    const [reason, setReason] = useState('');
    const [isConfirming, setIsConfirming] = useState(false);
    const [processing, setProcessing] = useState(false);

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (!isConfirming) {
            setIsConfirming(true);

            return;
        }

        router.post(
            payments.refund.url(reference),
            { amount, reason: reason || null },
            {
                only: onlyReload,
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => {
                    setProcessing(false);
                    setIsConfirming(false);
                },
            },
        );
    };

    return (
        <div
            className="mt-4 border-t border-sidebar-border/60 pt-4"
            data-test="refund-panel"
        >
            <div className="flex items-baseline justify-between">
                <h3 className="text-sm font-semibold">Refunds</h3>
                <span className="text-xs text-neutral-500 tabular-nums">
                    Refunded {currency} {refund.refunded} · refundable{' '}
                    {currency} {refund.refundable}
                </span>
            </div>

            {refund.items.length > 0 && (
                <ul className="mt-2 space-y-1.5">
                    {refund.items.map((item) => (
                        <li
                            key={item.id}
                            className="flex items-center justify-between gap-3 text-xs"
                        >
                            <span className="min-w-0 truncate text-neutral-600 dark:text-neutral-400">
                                {currency} {item.amount}
                                {item.reason && ` · ${item.reason}`}
                                {item.fromPaypal && ' · made in PayPal'}
                                {item.createdAt && ` · ${item.createdAt}`}
                            </span>
                            <span
                                className={cn(
                                    'shrink-0 rounded-md px-1.5 py-0.5 text-[10px] font-medium capitalize',
                                    statusTone[item.status] ??
                                        statusTone.pending,
                                )}
                            >
                                {item.status}
                            </span>
                        </li>
                    ))}
                </ul>
            )}

            {refund.canRefund ? (
                <form onSubmit={submit} className="mt-3 space-y-2.5">
                    <div className="grid grid-cols-5 gap-2">
                        <div className="col-span-2 space-y-1">
                            <Label htmlFor="refund-amount">
                                Amount ({currency})
                            </Label>
                            <Input
                                id="refund-amount"
                                type="number"
                                min="0"
                                step="any"
                                max={refund.refundable}
                                value={amount}
                                onChange={(e) => {
                                    setAmount(e.target.value);
                                    setIsConfirming(false);
                                }}
                            />
                        </div>
                        <div className="col-span-3 space-y-1">
                            <Label htmlFor="refund-reason">
                                Reason (optional)
                            </Label>
                            <Input
                                id="refund-reason"
                                value={reason}
                                maxLength={255}
                                onChange={(e) => setReason(e.target.value)}
                                placeholder="Shown to the payer"
                            />
                        </div>
                    </div>
                    <Button
                        type="submit"
                        color={isConfirming ? 'red' : 'gray'}
                        variant={isConfirming ? 'solid' : 'soft'}
                        disabled={processing || !(Number(amount) > 0)}
                        className="cursor-pointer gap-1.5"
                    >
                        <Undo2 className="h-3.5 w-3.5" />
                        {isConfirming
                            ? `Confirm refund of ${currency} ${amount}`
                            : 'Refund'}
                    </Button>
                </form>
            ) : (
                <p className="mt-2 text-xs text-neutral-500">
                    {refund.blockedReason ??
                        'This payment has been refunded in full.'}
                </p>
            )}
        </div>
    );
}
