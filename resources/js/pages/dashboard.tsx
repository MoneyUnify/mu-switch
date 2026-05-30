import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { PlaceholderPattern } from '@/components/ui/placeholder-pattern';
import { dashboard } from '@/routes';

interface DashboardProps {
    apiToken: string | null;
}

export default function Dashboard({ apiToken }: DashboardProps) {
    const [loading, setLoading] = useState(false);
    const [showToken, setShowToken] = useState(false);

    const copyToClipboard = () => {
        if (apiToken) {
            navigator.clipboard.writeText(apiToken);
            toast.success('API token copied to clipboard');
        }
    };

    const regenerateToken = () => {
        // Explicitly use the string path matching web.php
        router.post('/api-token/regenerate', {}, {
            onBefore: () => setLoading(true),
            onSuccess: () => {
                setShowToken(true);
                toast.success('API token regenerated successfully');
            },
            onError: () => {
                toast.error('Failed to regenerate API token');
            },
            onFinish: () => setLoading(false),
            preserveState: true,
        });
    };

    return (
        <>
            <Head title="Dashboard" />
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">

                {/* API Token Section */}
                <Card className="border border-sidebar-border/70 dark:border-sidebar-border">
                    <div className="p-6">
                        <h2 className="text-lg font-semibold mb-4">API Token</h2>
                        <p className="text-sm text-neutral-600 dark:text-neutral-400 mb-4">
                            Use this token to authenticate API requests. Keep it secret and never share it publicly.
                        </p>

                        <div className="space-y-4">
                            <div>
                                <Label htmlFor="api-token" className="text-sm font-medium mb-2 block">
                                    Your API Token
                                </Label>
                                <div className="flex gap-2">
                                    <Input
                                        id="api-token"
                                        type={showToken ? 'text' : 'password'}
                                        value={apiToken || ''}
                                        readOnly
                                        className="flex-1"
                                        placeholder={loading ? 'Regenerating...' : 'No token available'}
                                    />
                                    <Button
                                        onClick={copyToClipboard}
                                        disabled={!apiToken || loading}
                                        variant="outline"
                                    >
                                        Copy
                                    </Button>
                                    <Button
                                        onClick={() => setShowToken(!showToken)}
                                        disabled={!apiToken || loading}
                                        variant="outline"
                                    >
                                        {showToken ? 'Hide' : 'Show'}
                                    </Button>
                                </div>
                            </div>

                            <div>
                                <Button
                                    onClick={regenerateToken}
                                    disabled={loading}
                                    variant="destructive"
                                >
                                    {loading ? 'Regenerating...' : 'Regenerate Token'}
                                </Button>
                                <p className="text-xs text-neutral-500 dark:text-neutral-400 mt-2">
                                    Regenerating will invalidate your current token.
                                </p>
                            </div>
                        </div>
                    </div>
                </Card>
                
                {/* Rest of your grid placeholders remain unchanged */}
                <div className="grid auto-rows-min gap-4 md:grid-cols-3">
                    <div className="relative aspect-video overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                        <PlaceholderPattern className="absolute inset-0 size-full stroke-neutral-900/20 dark:stroke-neutral-100/20" />
                    </div>
                    <div className="relative aspect-video overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                        <PlaceholderPattern className="absolute inset-0 size-full stroke-neutral-900/20 dark:stroke-neutral-100/20" />
                    </div>
                    <div className="relative aspect-video overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                        <PlaceholderPattern className="absolute inset-0 size-full stroke-neutral-900/20 dark:stroke-neutral-100/20" />
                    </div>
                </div>

                <div className="relative min-h-[100vh] flex-1 overflow-hidden rounded-xl border border-sidebar-border/70 md:min-h-min dark:border-sidebar-border">
                    <PlaceholderPattern className="absolute inset-0 size-full stroke-neutral-900/20 dark:stroke-neutral-100/20" />
                </div>
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
    ],
};