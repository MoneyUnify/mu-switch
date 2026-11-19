import { router } from '@inertiajs/react';
import { Button } from '@radix-ui/themes';
import { Bot, Copy, Loader2 } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import MerchantController from '@/actions/App/Http/Controllers/MerchantController';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
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

export interface McpAccess {
    enabled: boolean;
    hint: string | null;
    issued_at: string | null;
}

/**
 * A merchant's own access to MoneyUnify over MCP: the operator issues a key,
 * and the merchant connects its apps or an AI assistant with it. The key is
 * shown once; only its hint is kept.
 */
export function MerchantMcpAccess({
    merchantId,
    merchantName,
    access,
    endpoint,
    reloadOnly,
}: {
    merchantId: number;
    merchantName: string;
    access: McpAccess;
    endpoint: string;
    reloadOnly: string[];
}) {
    const [open, setOpen] = useState(false);
    const [key, setKey] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [confirm, setConfirm] = useState<'replace' | 'revoke' | null>(null);

    const send = async (method: 'POST' | 'DELETE') => {
        setBusy(true);
        setConfirm(null);

        try {
            const url =
                method === 'POST'
                    ? MerchantController.issueMcpKey.url(merchantId)
                    : MerchantController.revokeMcpKey.url(merchantId);
            const response = await fetch(url, {
                method,
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
            });
            const json = await response.json().catch(() => ({}));

            if (!response.ok) {
                toast.error(json.message ?? 'Something went wrong.');

                return;
            }

            setKey(method === 'POST' ? (json.data?.key ?? null) : null);
            toast.success(json.message);
            router.reload({ only: reloadOnly });
        } finally {
            setBusy(false);
        }
    };

    const config = JSON.stringify(
        {
            mcpServers: {
                [merchantName.toLowerCase().replace(/[^a-z0-9]+/g, '-')]: {
                    type: 'http',
                    url: endpoint,
                    headers: {
                        Authorization: `Bearer ${key ?? 'MERCHANT_MCP_KEY'}`,
                    },
                },
            },
        },
        null,
        2,
    );

    const issuedOn = access.issued_at
        ? new Date(access.issued_at).toLocaleDateString(undefined, {
              day: 'numeric',
              month: 'short',
              year: 'numeric',
          })
        : null;

    return (
        <>
            <Button
                size="1"
                variant="soft"
                color={access.enabled ? 'blue' : 'gray'}
                onClick={() => setOpen(true)}
                className="cursor-pointer gap-1"
                data-test="merchant-mcp"
            >
                <Bot className="h-3 w-3" /> AI &amp; apps
            </Button>

            <Sheet
                open={open}
                onOpenChange={(isOpen) => {
                    setOpen(isOpen);

                    if (!isOpen) {
                        setKey(null);
                        setConfirm(null);
                    }
                }}
            >
                <SheetContent className="w-full gap-0 overflow-y-auto sm:max-w-lg">
                    <SheetHeader>
                        <SheetTitle>{merchantName} · AI &amp; apps</SheetTitle>
                        <SheetDescription>
                            Its own MCP key, to build apps or connect an AI
                            assistant. It can charge customers, follow payments
                            and settlements, and manage pay links, and nothing
                            else.
                        </SheetDescription>
                    </SheetHeader>

                    <div className="space-y-4 px-4 pb-6">
                        <div className="space-y-3 rounded-xl border border-sidebar-border/70 p-4 text-xs dark:border-sidebar-border">
                            <div className="flex items-center justify-between gap-2">
                                <p className="text-sm font-semibold">
                                    Access key
                                </p>
                                <span
                                    className={cn(
                                        'rounded-md px-1.5 py-0.5 text-[10px] font-medium',
                                        access.enabled
                                            ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300'
                                            : 'bg-neutral-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-400',
                                    )}
                                >
                                    {access.enabled ? 'Active' : 'Not issued'}
                                </span>
                            </div>

                            {key ? (
                                <div
                                    className="space-y-1.5"
                                    data-test="merchant-mcp-key"
                                >
                                    <p className="text-neutral-600 dark:text-neutral-400">
                                        Copy this key now. It won&apos;t be
                                        shown again.
                                    </p>
                                    <div className="flex items-center gap-2">
                                        <code className="min-w-0 flex-1 rounded-md bg-neutral-100 px-2 py-1.5 break-all dark:bg-neutral-800">
                                            {key}
                                        </code>
                                        <Button
                                            size="1"
                                            variant="soft"
                                            color="gray"
                                            className="cursor-pointer"
                                            onClick={() => copyText(key, 'Key')}
                                            aria-label="Copy key"
                                        >
                                            <Copy className="h-3 w-3" />
                                        </Button>
                                    </div>
                                </div>
                            ) : (
                                <p className="text-neutral-500">
                                    {access.enabled
                                        ? `${access.hint}, issued ${issuedOn}. Only this hint is kept.`
                                        : `${merchantName} can't connect until you issue a key.`}
                                </p>
                            )}

                            <div className="flex flex-wrap gap-2">
                                <Button
                                    size="2"
                                    onClick={() =>
                                        access.enabled
                                            ? setConfirm('replace')
                                            : send('POST')
                                    }
                                    disabled={busy}
                                    className="cursor-pointer gap-1.5"
                                    data-test="merchant-mcp-issue"
                                >
                                    {busy && (
                                        <Loader2 className="h-3.5 w-3.5 animate-spin" />
                                    )}
                                    {access.enabled
                                        ? 'Replace key'
                                        : 'Issue key'}
                                </Button>
                                {access.enabled && (
                                    <Button
                                        size="2"
                                        variant="soft"
                                        color="red"
                                        onClick={() => setConfirm('revoke')}
                                        disabled={busy}
                                        className="cursor-pointer"
                                    >
                                        Revoke
                                    </Button>
                                )}
                            </div>
                        </div>

                        <div className="space-y-3 rounded-xl border border-sidebar-border/70 p-4 text-xs dark:border-sidebar-border">
                            <p className="text-sm font-semibold">Connect</p>
                            <div className="space-y-1">
                                <span className="text-neutral-500">
                                    Server URL
                                </span>
                                <div className="flex items-center gap-2">
                                    <code className="min-w-0 flex-1 truncate rounded-md bg-neutral-100 px-2 py-1.5 dark:bg-neutral-800">
                                        {endpoint}
                                    </code>
                                    <Button
                                        size="1"
                                        variant="soft"
                                        color="gray"
                                        className="cursor-pointer"
                                        onClick={() =>
                                            copyText(endpoint, 'URL')
                                        }
                                        aria-label="Copy server URL"
                                    >
                                        <Copy className="h-3 w-3" />
                                    </Button>
                                </div>
                            </div>
                            <div className="space-y-1">
                                <div className="flex items-center justify-between">
                                    <span className="text-neutral-500">
                                        MCP client config
                                    </span>
                                    <Button
                                        size="1"
                                        variant="ghost"
                                        color="gray"
                                        className="cursor-pointer gap-1"
                                        onClick={() =>
                                            copyText(config, 'Config')
                                        }
                                    >
                                        <Copy className="h-3 w-3" /> Copy
                                    </Button>
                                </div>
                                <pre className="rounded-md bg-neutral-100 p-2 text-[11px] leading-relaxed break-all whitespace-pre-wrap dark:bg-neutral-800">
                                    {config}
                                </pre>
                            </div>
                        </div>
                    </div>

                    {/* Inside the sheet, so it opens above it and stays clickable. */}
                    <Dialog
                        open={confirm !== null}
                        onOpenChange={(isOpen) => !isOpen && setConfirm(null)}
                    >
                        <DialogContent className="sm:max-w-md">
                            <DialogHeader>
                                <DialogTitle>
                                    {confirm === 'replace'
                                        ? `Replace ${merchantName}'s key?`
                                        : `Revoke ${merchantName}'s key?`}
                                </DialogTitle>
                                <DialogDescription>
                                    {confirm === 'replace'
                                        ? 'The current key stops working straight away. Apps and assistants using it need the new key.'
                                        : `${merchantName}'s apps and assistants lose access straight away. You can issue a new key later.`}
                                </DialogDescription>
                            </DialogHeader>
                            <DialogFooter className="gap-2">
                                <Button
                                    variant="soft"
                                    color="gray"
                                    onClick={() => setConfirm(null)}
                                    className="cursor-pointer"
                                >
                                    Cancel
                                </Button>
                                <Button
                                    color={
                                        confirm === 'revoke' ? 'red' : 'blue'
                                    }
                                    onClick={() =>
                                        send(
                                            confirm === 'revoke'
                                                ? 'DELETE'
                                                : 'POST',
                                        )
                                    }
                                    disabled={busy}
                                    className="cursor-pointer"
                                    data-test="merchant-mcp-confirm"
                                >
                                    {confirm === 'replace'
                                        ? 'Replace key'
                                        : 'Revoke key'}
                                </Button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>
                </SheetContent>
            </Sheet>
        </>
    );
}
