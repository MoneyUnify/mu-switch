import { Head, useForm } from '@inertiajs/react';
import { Eye, Route as RouteIcon } from 'lucide-react';
import { toast } from 'sonner';
import { Button, RadioCards } from '@radix-ui/themes';
import { Card } from '@/components/ui/card';
import feePolicy from '@/routes/fee-policy';
import { cn } from '@/lib/utils';

interface FeePolicyProps {
    policy: string;
}

const POLICIES = [
    {
        value: 'transparent',
        title: 'Transparent Net',
        badge: 'Default',
        icon: Eye,
        summary:
            'Report the real net per transaction and which provider handled it.',
        detail: 'The switch never promises a single fixed figure. Each payment records its actual collection fee, settlement fee and net — so when a fallback provider charges differently, you and your clients see the exact amount and why it differs. The most honest, audit-friendly option.',
    },
    {
        value: 'cost_aware',
        title: 'Cost-Aware Routing',
        icon: RouteIcon,
        summary: 'Always try the cheapest reliable provider first.',
        detail: 'The switch ranks eligible providers by the total fee they would charge for each payment and tries the cheapest first. Fallback (and any fee difference) becomes the rare exception rather than the norm — and it is still fully recorded when it happens.',
    },
];

export default function FeePolicyPage({ policy }: FeePolicyProps) {
    const { data, setData, put, processing } = useForm<{ policy: string }>({
        policy,
    });

    const save = () => {
        put(feePolicy.update.url(), {
            preserveScroll: true,
            onSuccess: () => toast.success('Fee policy updated'),
            onError: () => toast.error('Could not update fee policy'),
        });
    };

    const dirty = data.policy !== policy;

    return (
        <>
            <Head title="Fee Policy" />
            <div className="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-6">
                <div>
                    <h1 className="text-lg font-semibold tracking-tight">
                        Fee Policy
                    </h1>
                    <p className="mt-1 max-w-2xl text-xs text-neutral-600 dark:text-neutral-400">
                        Providers charge different fees for collections and
                        settlements, so the net a merchant receives can vary
                        when the switch fails over. Choose how the switch
                        handles that difference. Either way, every transaction
                        stores its full fee breakdown for auditing.
                    </p>
                </div>

                <Card className="gap-0 border border-sidebar-border/70 p-5 dark:border-sidebar-border">
                    <RadioCards.Root
                        value={data.policy}
                        onValueChange={(value) => setData('policy', value)}
                        columns={{ initial: '1', sm: '2' }}
                        gap="4"
                    >
                        {POLICIES.map((option) => {
                            const Icon = option.icon;
                            const active = data.policy === option.value;
                            return (
                                <RadioCards.Item
                                    key={option.value}
                                    value={option.value}
                                    className="items-start"
                                >
                                    <div className="flex flex-col gap-2 text-left">
                                        <div className="flex items-center gap-2">
                                            <Icon
                                                className={cn(
                                                    'h-4 w-4',
                                                    active
                                                        ? 'text-primary'
                                                        : 'text-neutral-500',
                                                )}
                                            />
                                            <span className="font-semibold">
                                                {option.title}
                                            </span>
                                            {option.badge && (
                                                <span className="rounded-full bg-primary/10 px-2 py-0.5 text-[10px] font-medium text-primary">
                                                    {option.badge}
                                                </span>
                                            )}
                                        </div>
                                        <p className="text-sm text-neutral-700 dark:text-neutral-300">
                                            {option.summary}
                                        </p>
                                        <p className="text-xs leading-relaxed text-neutral-500 dark:text-neutral-400">
                                            {option.detail}
                                        </p>
                                    </div>
                                </RadioCards.Item>
                            );
                        })}
                    </RadioCards.Root>

                    <div className="mt-5 flex items-center justify-end gap-3 border-t border-sidebar-border/50 pt-4 dark:border-sidebar-border/70">
                        <span className="text-xs text-neutral-400">
                            {dirty ? 'Unsaved changes' : 'Active policy saved'}
                        </span>
                        <Button
                            onClick={save}
                            disabled={!dirty || processing}
                            className="cursor-pointer"
                        >
                            Save policy
                        </Button>
                    </div>
                </Card>
            </div>
        </>
    );
}

FeePolicyPage.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'Fee Policy', href: '/fee-policy' },
    ],
};
