import { cn } from '@/lib/utils';

export interface CurrencyVolume {
    currency: string;
    amount: number;
    /** Successful transactions in this currency. */
    count: number;
}

/** The amount in the currency's own precision, without its code or symbol. */
function formatAmount(amount: number, currency: string): string {
    try {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency,
            currencyDisplay: 'code',
        })
            .format(amount)
            .replace(new RegExp(`^${currency}[\\s\\u00A0]*`), '');
    } catch {
        return amount.toLocaleString();
    }
}

/**
 * Volume in each currency, side by side like the dashboard's Volume Processed
 * card: never added together, so every amount keeps its own unit. Currencies
 * with no volume yet are shown muted.
 */
export function VolumeSummary({ volumes }: { volumes: CurrencyVolume[] }) {
    return (
        <dl className="mt-2.5 grid grid-cols-2 gap-x-6 gap-y-1.5 sm:grid-cols-3">
            {volumes.map((volume) => {
                const hasVolume = volume.amount > 0;

                return (
                    <div
                        key={volume.currency}
                        className="flex min-w-0 items-baseline justify-between gap-2"
                        title={`${volume.count.toLocaleString()} successful in ${volume.currency}`}
                    >
                        <dt className="shrink-0 font-mono text-[11px] font-semibold text-neutral-400 dark:text-neutral-500">
                            {volume.currency}
                        </dt>
                        <dd
                            className={cn(
                                'truncate text-base tabular-nums',
                                hasVolume
                                    ? 'font-bold text-neutral-900 dark:text-neutral-100'
                                    : 'font-normal text-neutral-400 dark:text-neutral-500',
                            )}
                        >
                            {formatAmount(volume.amount, volume.currency)}
                        </dd>
                    </div>
                );
            })}
        </dl>
    );
}
