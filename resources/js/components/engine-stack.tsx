import { cn } from '@/lib/utils';

interface Layer {
    label: string;
    caption: string;
    items: string[];
    engine?: boolean;
}

/**
 * MoneyUnify as a layer of infrastructure: the products a business builds on
 * top, the engine in the middle, and the payment rails underneath, with
 * payments flowing between them.
 */
export function EngineStack({ providers }: { providers: number }) {
    const layers: Layer[] = [
        {
            label: 'Your products',
            caption: 'Under your brand',
            items: [
                'Wallet app',
                'Remittances',
                'Merchant checkout',
                'Payroll',
                'Grants and benefits',
            ],
        },
        {
            label: 'MoneyUnify engine',
            caption: 'Self-hosted, open source',
            items: [
                'Routing and fallback',
                'FX quotes',
                'Double-entry ledger',
                'Refunds',
                'Webhooks',
                'AI agents and MCP',
            ],
            engine: true,
        },
        {
            label: 'Payment rails',
            caption: `${providers} providers and growing`,
            items: [
                'PayPal',
                'Cards',
                'MTN MoMo',
                'Airtel Money',
                'M-Pesa',
                'and more',
            ],
        },
    ];

    return (
        <div className="relative">
            <style>{`
                @keyframes mu-flow { 0% { transform: translateY(-100%); opacity: 0; } 30%, 70% { opacity: 1; } 100% { transform: translateY(100%); opacity: 0; } }
            `}</style>
            {layers.map((layer, index) => (
                <div key={layer.label}>
                    {index > 0 && <Connector delay={index * 0.6} />}
                    <div
                        className={cn(
                            'rounded-2xl border p-5',
                            layer.engine
                                ? 'border-primary bg-primary text-white shadow-lg shadow-primary/25'
                                : 'border-neutral-200 bg-white dark:border-neutral-800 dark:bg-neutral-900',
                        )}
                    >
                        <div className="flex items-baseline justify-between gap-3">
                            <p
                                className={cn(
                                    'font-semibold',
                                    layer.engine
                                        ? 'text-white'
                                        : 'text-neutral-900 dark:text-white',
                                )}
                            >
                                {layer.label}
                            </p>
                            <p
                                className={cn(
                                    'text-xs',
                                    layer.engine
                                        ? 'text-white/75'
                                        : 'text-neutral-500 dark:text-neutral-400',
                                )}
                            >
                                {layer.caption}
                            </p>
                        </div>
                        <ul className="mt-3 flex flex-wrap gap-1.5">
                            {layer.items.map((item) => (
                                <li
                                    key={item}
                                    className={cn(
                                        'rounded-full px-2.5 py-1 text-xs',
                                        layer.engine
                                            ? 'bg-white/15 text-white'
                                            : 'border border-neutral-200 text-neutral-600 dark:border-neutral-700 dark:text-neutral-300',
                                    )}
                                >
                                    {item}
                                </li>
                            ))}
                        </ul>
                    </div>
                </div>
            ))}
        </div>
    );
}

/** The link between two layers, with a dot travelling along it. */
function Connector({ delay }: { delay: number }) {
    return (
        <div className="flex justify-center gap-16 py-1.5" aria-hidden>
            {[0, 1].map((line) => (
                <div
                    key={line}
                    className="relative h-6 w-px overflow-hidden bg-primary/25"
                >
                    <span
                        className="absolute inset-x-[-2px] top-0 h-2.5 rounded-full bg-primary motion-reduce:hidden"
                        style={{
                            animation: `mu-flow 2.4s ease-in-out ${delay + line * 1.2}s infinite`,
                        }}
                    />
                </div>
            ))}
        </div>
    );
}
