import { Head, Link, router, useForm } from '@inertiajs/react';
import { AlertDialog, Button, Flex } from '@radix-ui/themes';
import {
    ArrowDown,
    ArrowUp,
    Bot,
    Copy,
    Pencil,
    Plug,
    Plus,
    Trash2,
} from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import AiCredentialController from '@/actions/App/Http/Controllers/AiCredentialController';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
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
import { copyText } from '@/lib/clipboard';
import { index as aiIndex } from '@/routes/ai';
import merchantsRoute from '@/routes/merchants';
import providersRoute from '@/routes/providers';

type KeySource = 'dashboard' | 'env' | null;

interface ModelOption {
    id: string;
    note: string;
}

interface ProviderOption {
    value: string;
    label: string;
    envKey: string;
    models: ModelOption[];
    defaultModel: string;
}

interface Credential {
    id: number;
    provider: string;
    label: string;
    model: string | null;
    defaultModel: string;
    maskedKey: string | null;
    keySource: KeySource;
    envKey: string;
}

interface Props {
    providers: ProviderOption[];
    credentials: Credential[];
    envDefault: { provider: string; label: string; hasKey: boolean };
    paypalMcp: {
        source: KeySource | 'disabled';
        provider: string | null;
        environment: string | null;
    };
    moneyunifyMcp: { operator: string; merchant: string };
}

const selectClassName =
    'flex h-9 w-full rounded-full border border-input bg-transparent px-3.5 py-1 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

const CUSTOM_MODEL = '__custom';

/**
 * Choose a suggested model, the provider's default, or type any other model
 * the provider offers.
 */
function ModelPicker({
    id,
    provider,
    value,
    onChange,
    error,
}: {
    id: string;
    provider: ProviderOption | undefined;
    value: string;
    onChange: (model: string) => void;
    error?: string;
}) {
    const models = provider?.models ?? [];
    const [isCustom, setIsCustom] = useState(
        value !== '' && !models.some((model) => model.id === value),
    );

    return (
        <div className="space-y-1">
            <Label htmlFor={id}>Model</Label>
            <select
                id={id}
                value={isCustom ? CUSTOM_MODEL : value}
                onChange={(e) => {
                    const choice = e.target.value;
                    setIsCustom(choice === CUSTOM_MODEL);
                    onChange(choice === CUSTOM_MODEL ? '' : choice);
                }}
                className={selectClassName}
            >
                <option value="">
                    Provider default
                    {provider ? ` (${provider.defaultModel})` : ''}
                </option>
                {models.map((model) => (
                    <option key={model.id} value={model.id}>
                        {model.id} · {model.note}
                    </option>
                ))}
                <option value={CUSTOM_MODEL}>Other model…</option>
            </select>
            {isCustom && (
                <Input
                    id={`${id}-custom`}
                    value={value}
                    onChange={(e) => onChange(e.target.value.trim())}
                    placeholder="Model ID, exactly as the provider names it"
                    autoComplete="off"
                    autoFocus
                />
            )}
            <InputError message={error} />
            <p className="text-xs text-neutral-500">
                If a model is busy or unavailable, pick another or choose Other
                model to enter any model your provider offers.
            </p>
        </div>
    );
}

function KeyBadge({ credential }: { credential: Credential }) {
    if (credential.keySource === 'dashboard') {
        return <Badge variant="secondary">Key {credential.maskedKey}</Badge>;
    }

    if (credential.keySource === 'env') {
        return (
            <Badge variant="outline">Key from .env ({credential.envKey})</Badge>
        );
    }

    return <Badge variant="destructive">No key, skipped</Badge>;
}

/**
 * How to connect an AI assistant or an app to MoneyUnify over MCP: the
 * operator server with the account's API token, and the merchant server
 * with each merchant's own key.
 */
function MoneyUnifyMcpCard({
    endpoints,
}: {
    endpoints: { operator: string; merchant: string };
}) {
    const config = JSON.stringify(
        {
            mcpServers: {
                moneyunify: {
                    type: 'http',
                    url: endpoints.operator,
                    headers: { Authorization: 'Bearer YOUR_API_TOKEN' },
                },
            },
        },
        null,
        2,
    );

    return (
        <Card data-test="moneyunify-mcp">
            <CardHeader>
                <CardTitle>MoneyUnify MCP</CardTitle>
                <CardDescription>
                    Connect any AI assistant or app to your switch. Agents can
                    collect payments, start transfers, pay out, refund, run your
                    merchants and read FX and treasury, through the same rules
                    as the API.
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4 text-sm text-neutral-600 dark:text-neutral-400">
                <div className="space-y-1.5">
                    <div className="text-xs font-medium text-neutral-900 dark:text-neutral-100">
                        Operator server
                    </div>
                    <div className="flex items-center gap-2">
                        <code className="min-w-0 flex-1 truncate rounded-md bg-neutral-100 px-2 py-1.5 text-xs dark:bg-neutral-800">
                            {endpoints.operator}
                        </code>
                        <Button
                            size="1"
                            variant="soft"
                            color="gray"
                            className="cursor-pointer"
                            onClick={() => copyText(endpoints.operator, 'URL')}
                        >
                            <Copy className="h-3 w-3" />
                        </Button>
                    </div>
                    <p className="text-xs">
                        Sign in with your API token (on the dashboard) as a
                        Bearer token. For example, in an MCP client&apos;s
                        config:
                    </p>
                    <pre className="overflow-x-auto rounded-md bg-neutral-100 p-2 text-[11px] leading-relaxed dark:bg-neutral-800">
                        {config}
                    </pre>
                </div>
                <div className="space-y-1.5">
                    <div className="text-xs font-medium text-neutral-900 dark:text-neutral-100">
                        Merchant server
                    </div>
                    <code className="block truncate rounded-md bg-neutral-100 px-2 py-1.5 text-xs dark:bg-neutral-800">
                        {endpoints.merchant}
                    </code>
                    <p className="text-xs">
                        Each merchant connects with its own key, so it only
                        reaches its own payments, pay links and settlements.
                        Issue keys from the{' '}
                        <Link
                            href={merchantsRoute.index.url()}
                            className="text-foreground underline underline-offset-4"
                        >
                            Merchants
                        </Link>{' '}
                        page.
                    </p>
                </div>
            </CardContent>
        </Card>
    );
}

export default function AiAgents({
    providers,
    credentials,
    envDefault,
    paypalMcp,
    moneyunifyMcp,
}: Props) {
    const [isAddOpen, setIsAddOpen] = useState(false);
    const [editing, setEditing] = useState<Credential | null>(null);
    const [removing, setRemoving] = useState<Credential | null>(null);

    const addForm = useForm({ provider: '', model: '', api_key: '' });
    const editForm = useForm({ model: '', api_key: '' });

    const available = providers.filter(
        (p) => !credentials.some((c) => c.provider === p.value),
    );
    const selectedOption = providers.find(
        (p) => p.value === addForm.data.provider,
    );

    const openAdd = () => {
        addForm.reset();
        addForm.clearErrors();
        addForm.setData('provider', available[0]?.value ?? '');
        setIsAddOpen(true);
    };

    const submitAdd = (event: FormEvent) => {
        event.preventDefault();
        addForm.post(AiCredentialController.store.url(), {
            preserveScroll: true,
            onSuccess: () => setIsAddOpen(false),
        });
    };

    const openEdit = (credential: Credential) => {
        editForm.setData({ model: credential.model ?? '', api_key: '' });
        editForm.clearErrors();
        setEditing(credential);
    };

    const submitEdit = (event: FormEvent) => {
        event.preventDefault();

        if (!editing) {
            return;
        }

        editForm.put(AiCredentialController.update.url(editing.id), {
            preserveScroll: true,
            onSuccess: () => setEditing(null),
        });
    };

    // Swap a provider with its neighbour and save the new failover order.
    const move = (index: number, direction: -1 | 1) => {
        const ids = credentials.map((c) => c.id);
        const target = index + direction;
        [ids[index], ids[target]] = [ids[target], ids[index]];

        router.put(
            AiCredentialController.reorder.url(),
            { ids },
            { preserveScroll: true },
        );
    };

    return (
        <>
            <Head title="AI & Agents" />
            <div className="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-6">
                <div className="flex items-center justify-between gap-4">
                    <div>
                        <h1 className="text-lg font-semibold tracking-tight">
                            AI & Agents
                        </h1>
                        <p className="mt-1 text-xs text-neutral-600 dark:text-neutral-400">
                            The AI providers your agents use, and how they reach
                            PayPal&apos;s tools.
                        </p>
                    </div>
                    <Button
                        onClick={openAdd}
                        disabled={available.length === 0}
                        className="shrink-0 cursor-pointer gap-2"
                    >
                        <Plus className="h-4 w-4" /> Add AI provider
                    </Button>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Failover order</CardTitle>
                        <CardDescription>
                            Agents use the first provider and move down the list
                            on rate limits, outages or exhausted credit. A blank
                            key uses that provider&apos;s key from .env.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {credentials.length === 0 ? (
                            <div className="flex flex-col items-center justify-center rounded-xl border border-dashed border-neutral-200 py-10 text-center text-sm text-neutral-500 dark:border-neutral-800">
                                <Bot className="mb-3 h-8 w-8 text-neutral-400" />
                                <p>No AI providers yet.</p>
                                <p className="mt-1">
                                    Agents use the server default:{' '}
                                    <span className="font-medium text-neutral-700 dark:text-neutral-300">
                                        {envDefault.label}
                                    </span>
                                    {envDefault.hasKey
                                        ? ' (key from .env).'
                                        : ', which has no key in .env.'}
                                </p>
                            </div>
                        ) : (
                            <ol className="space-y-3">
                                {credentials.map((credential, index) => (
                                    <li
                                        key={credential.id}
                                        className="flex flex-col gap-3 rounded-lg border border-sidebar-border/50 p-3 sm:flex-row sm:items-center dark:border-sidebar-border/70"
                                    >
                                        <div className="flex min-w-0 flex-1 items-center gap-3">
                                            <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-neutral-100 text-xs font-semibold text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300">
                                                {index + 1}
                                            </span>
                                            <div className="min-w-0">
                                                <div className="truncate font-medium">
                                                    {credential.label}
                                                </div>
                                                <div className="truncate text-xs text-neutral-500">
                                                    {credential.model ??
                                                        `Default (${credential.defaultModel})`}
                                                </div>
                                            </div>
                                        </div>
                                        <div className="flex flex-wrap items-center gap-2">
                                            <KeyBadge credential={credential} />
                                            <Button
                                                variant="ghost"
                                                size="1"
                                                aria-label={`Move ${credential.label} up`}
                                                disabled={index === 0}
                                                onClick={() => move(index, -1)}
                                                className="cursor-pointer"
                                            >
                                                <ArrowUp className="h-4 w-4" />
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="1"
                                                aria-label={`Move ${credential.label} down`}
                                                disabled={
                                                    index ===
                                                    credentials.length - 1
                                                }
                                                onClick={() => move(index, 1)}
                                                className="cursor-pointer"
                                            >
                                                <ArrowDown className="h-4 w-4" />
                                            </Button>
                                            <Button
                                                variant="soft"
                                                size="1"
                                                onClick={() =>
                                                    router.post(
                                                        AiCredentialController.test.url(
                                                            credential.id,
                                                        ),
                                                        {},
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                                className="cursor-pointer"
                                            >
                                                <Plug className="h-3 w-3" />{' '}
                                                Test
                                            </Button>
                                            <Button
                                                variant="soft"
                                                size="1"
                                                color="gray"
                                                onClick={() =>
                                                    openEdit(credential)
                                                }
                                                className="cursor-pointer"
                                            >
                                                <Pencil className="h-3 w-3" />{' '}
                                                Edit
                                            </Button>
                                            <Button
                                                variant="soft"
                                                size="1"
                                                color="red"
                                                aria-label={`Remove ${credential.label}`}
                                                onClick={() =>
                                                    setRemoving(credential)
                                                }
                                                className="cursor-pointer"
                                            >
                                                <Trash2 className="h-3 w-3" />
                                            </Button>
                                        </div>
                                    </li>
                                ))}
                            </ol>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>PayPal Agent Toolkit</CardTitle>
                        <CardDescription>
                            Lets your agents use PayPal&apos;s own tools, such
                            as orders, refunds, transactions and disputes,
                            through PayPal&apos;s MCP server.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="text-sm text-neutral-600 dark:text-neutral-400">
                        {paypalMcp.source === 'dashboard' && (
                            <>
                                Connected through your PayPal provider “
                                {paypalMcp.provider}” ({paypalMcp.environment}).
                                Access tokens refresh automatically.
                            </>
                        )}
                        {paypalMcp.source === 'env' && (
                            <>
                                Using MCP_PAYPAL_URL and MCP_PAYPAL_TOKEN from
                                .env.
                            </>
                        )}
                        {paypalMcp.source === 'disabled' && (
                            <>
                                Turned off on your PayPal provider “
                                {paypalMcp.provider}”, so agents get no PayPal
                                tools. Turn it back on from the{' '}
                                <Link
                                    href={providersRoute.index.url()}
                                    className="text-foreground underline underline-offset-4"
                                >
                                    Providers
                                </Link>{' '}
                                page.
                            </>
                        )}
                        {paypalMcp.source === null && (
                            <>
                                Not connected. Add an active PayPal provider on
                                the{' '}
                                <Link
                                    href={providersRoute.index.url()}
                                    className="text-foreground underline underline-offset-4"
                                >
                                    Providers
                                </Link>{' '}
                                page, or set MCP_PAYPAL_TOKEN in .env.
                            </>
                        )}
                    </CardContent>
                </Card>

                <MoneyUnifyMcpCard endpoints={moneyunifyMcp} />
            </div>

            <Dialog open={isAddOpen} onOpenChange={setIsAddOpen}>
                <DialogContent className="sm:max-w-[450px]">
                    <form onSubmit={submitAdd} className="space-y-4">
                        <DialogHeader>
                            <DialogTitle>Add AI provider</DialogTitle>
                            <DialogDescription>
                                It&apos;s added to the end of the failover
                                order.
                            </DialogDescription>
                        </DialogHeader>

                        <div className="space-y-1">
                            <Label htmlFor="ai-provider">Provider</Label>
                            <select
                                id="ai-provider"
                                value={addForm.data.provider}
                                onChange={(e) =>
                                    addForm.setData((data) => ({
                                        ...data,
                                        provider: e.target.value,
                                        model: '',
                                    }))
                                }
                                className={selectClassName}
                            >
                                {available.map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                            <InputError message={addForm.errors.provider} />
                        </div>

                        <ModelPicker
                            key={addForm.data.provider}
                            id="ai-model"
                            provider={selectedOption}
                            value={addForm.data.model}
                            onChange={(model) =>
                                addForm.setData('model', model)
                            }
                            error={addForm.errors.model}
                        />

                        <div className="space-y-1">
                            <Label htmlFor="ai-key">API key (optional)</Label>
                            <Input
                                id="ai-key"
                                type="password"
                                value={addForm.data.api_key}
                                onChange={(e) =>
                                    addForm.setData('api_key', e.target.value)
                                }
                                placeholder={
                                    selectedOption
                                        ? `Leave blank to use ${selectedOption.envKey} from .env`
                                        : ''
                                }
                                autoComplete="off"
                            />
                            <InputError message={addForm.errors.api_key} />
                            <p className="text-xs text-neutral-500">
                                Keys are encrypted at rest and never shown again
                                after saving.
                            </p>
                        </div>

                        <DialogFooter>
                            <Button
                                type="button"
                                variant="soft"
                                color="gray"
                                onClick={() => setIsAddOpen(false)}
                                className="cursor-pointer"
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                disabled={addForm.processing}
                                className="cursor-pointer"
                            >
                                Add provider
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog
                open={editing !== null}
                onOpenChange={(open) => !open && setEditing(null)}
            >
                <DialogContent className="sm:max-w-[450px]">
                    <form onSubmit={submitEdit} className="space-y-4">
                        <DialogHeader>
                            <DialogTitle>Edit {editing?.label}</DialogTitle>
                            <DialogDescription>
                                Leave the key blank to keep the current one.
                            </DialogDescription>
                        </DialogHeader>

                        {editing && (
                            <ModelPicker
                                key={editing.id}
                                id="edit-model"
                                provider={providers.find(
                                    (p) => p.value === editing.provider,
                                )}
                                value={editForm.data.model}
                                onChange={(model) =>
                                    editForm.setData('model', model)
                                }
                                error={editForm.errors.model}
                            />
                        )}

                        <div className="space-y-1">
                            <Label htmlFor="edit-key">API key</Label>
                            <Input
                                id="edit-key"
                                type="password"
                                value={editForm.data.api_key}
                                onChange={(e) =>
                                    editForm.setData('api_key', e.target.value)
                                }
                                placeholder={
                                    editing?.maskedKey
                                        ? `Saved (${editing.maskedKey}). Leave blank to keep.`
                                        : `Leave blank to use ${editing?.envKey} from .env`
                                }
                                autoComplete="off"
                            />
                            <InputError message={editForm.errors.api_key} />
                            {editing?.maskedKey && (
                                <button
                                    type="button"
                                    onClick={() => {
                                        router.delete(
                                            AiCredentialController.destroyKey.url(
                                                editing.id,
                                            ),
                                            {
                                                preserveScroll: true,
                                                onSuccess: () =>
                                                    setEditing(null),
                                            },
                                        );
                                    }}
                                    className="cursor-pointer text-xs font-medium text-red-600 underline underline-offset-4"
                                >
                                    Remove saved key and use .env
                                </button>
                            )}
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
                                disabled={editForm.processing}
                                className="cursor-pointer"
                            >
                                Save
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <AlertDialog.Root
                open={removing !== null}
                onOpenChange={(open) => !open && setRemoving(null)}
            >
                <AlertDialog.Content maxWidth="450px">
                    <AlertDialog.Title>Remove AI provider</AlertDialog.Title>
                    <AlertDialog.Description size="2">
                        Remove <strong>{removing?.label}</strong> and its saved
                        key from your failover order?
                    </AlertDialog.Description>
                    <Flex gap="3" mt="4" justify="end">
                        <AlertDialog.Cancel>
                            <Button
                                variant="soft"
                                color="gray"
                                className="cursor-pointer"
                            >
                                Cancel
                            </Button>
                        </AlertDialog.Cancel>
                        <AlertDialog.Action>
                            <Button
                                color="red"
                                onClick={() =>
                                    removing &&
                                    router.delete(
                                        AiCredentialController.destroy.url(
                                            removing.id,
                                        ),
                                        { preserveScroll: true },
                                    )
                                }
                                className="cursor-pointer"
                            >
                                <Trash2 className="h-3 w-3" /> Remove
                            </Button>
                        </AlertDialog.Action>
                    </Flex>
                </AlertDialog.Content>
            </AlertDialog.Root>
        </>
    );
}

AiAgents.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'AI & Agents', href: aiIndex.url() },
    ],
};
