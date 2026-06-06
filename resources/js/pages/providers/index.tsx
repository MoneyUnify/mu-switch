import { Head, useForm } from '@inertiajs/react';
import { Plus, Settings, Trash2, CheckCircle2, XCircle, Globe, Key, FileCode } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Badge } from '@/components/ui/badge';
import providersRoute from '@/routes/providers';

interface PaymentProvider {
    id: number;
    name: string;
    class: string;
    config: {
        api_key?: string;
        supported_countries?: string | string[];
    } | null;
    logo_url: string | null;
    is_active: boolean;
}

interface IndexProps {
    providers: PaymentProvider[];
}

export default function Index({ providers }: IndexProps) {
    const [isCreateOpen, setIsCreateOpen] = useState(false);
    const [editingProvider, setEditingProvider] = useState<PaymentProvider | null>(null);

    const {
        data,
        setData,
        post,
        put,
        delete: destroy,
        processing,
        errors,
        reset,
    } = useForm({
        name: '',
        class: 'App\\Http\\Controllers\\Providers\\LencoController',
        api_key: '',
        supported_countries: 'ZM,MW',
        logo_url: '',
        is_active: true,
    });

    const openCreateModal = () => {
        reset();
        setIsCreateOpen(true);
    };

    const openEditModal = (provider: PaymentProvider) => {
        setEditingProvider(provider);
        const countries = provider.config?.supported_countries;
        setData({
            name: provider.name,
            class: provider.class,
            api_key: provider.config?.api_key || '',
            supported_countries: Array.isArray(countries) ? countries.join(',') : (countries || 'ZM,MW'),
            logo_url: provider.logo_url || '',
            is_active: provider.is_active,
        });
    };

    const handleCreate = (e: React.FormEvent) => {
        e.preventDefault();
        post(providersRoute.store.url(), {
            onSuccess: () => {
                setIsCreateOpen(false);
                toast.success('Provider created successfully');
                reset();
            },
            onError: () => {
                toast.error('Failed to create provider');
            },
        });
    };

    const handleUpdate = (e: React.FormEvent) => {
        e.preventDefault();
        if (!editingProvider) return;

        put(providersRoute.update.url(editingProvider.id), {
            onSuccess: () => {
                setEditingProvider(null);
                toast.success('Provider updated successfully');
            },
            onError: () => {
                toast.error('Failed to update provider');
            },
        });
    };

    const handleDelete = (id: number) => {
        if (confirm('Are you sure you want to delete this provider?')) {
            destroy(providersRoute.destroy.url(id), {
                onSuccess: () => {
                    toast.success('Provider deleted successfully');
                },
                onError: () => {
                    toast.error('Failed to delete provider');
                },
            });
        }
    };

    return (
        <>
            <Head title="Payment Providers" />
            <div className="flex h-full flex-1 flex-col gap-6 p-6 overflow-x-auto rounded-xl">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">Payment Providers</h1>
                        <p className="text-sm text-neutral-600 dark:text-neutral-400 mt-1">
                            Configure payment gateways, credentials, and regional availability for your transaction switch.
                        </p>
                    </div>
                    <Button onClick={openCreateModal} className="cursor-pointer gap-2">
                        <Plus className="h-4 w-4" /> Add Provider
                    </Button>
                </div>

                <div className="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
                    {providers.map((provider) => {
                        const countries = provider.config?.supported_countries;
                        const countryString = Array.isArray(countries) ? countries.join(', ') : (countries || 'ZM, MW');

                        return (
                            <Card key={provider.id} className="flex flex-col border border-sidebar-border/70 dark:border-sidebar-border shadow-sm hover:shadow-md transition-shadow">
                                <CardHeader className="flex flex-row items-center justify-between pb-2 space-y-0">
                                    <div className="flex items-center space-x-3">
                                        {provider.logo_url ? (
                                            <img src={provider.logo_url} alt={provider.name} className="h-10 w-10 object-contain rounded-md" />
                                        ) : (
                                            <div className="h-10 w-10 bg-neutral-100 dark:bg-neutral-800 rounded-md flex items-center justify-center">
                                                <Settings className="h-5 w-5 text-neutral-500" />
                                            </div>
                                        )}
                                        <div>
                                            <CardTitle className="text-base font-semibold">{provider.name}</CardTitle>
                                            <CardDescription className="text-xs font-mono mt-0.5 truncate max-w-[180px]">
                                                {provider.class.split('\\').pop()}
                                            </CardDescription>
                                        </div>
                                    </div>
                                    <Badge variant={provider.is_active ? 'default' : 'secondary'} className="gap-1">
                                        {provider.is_active ? (
                                            <>
                                                <CheckCircle2 className="h-3 w-3" /> Active
                                            </>
                                        ) : (
                                            <>
                                                <XCircle className="h-3 w-3" /> Inactive
                                            </>
                                        )}
                                    </Badge>
                                </CardHeader>
                                <CardContent className="flex-1 space-y-3 pt-4 border-t border-sidebar-border/30 dark:border-sidebar-border/50 text-sm">
                                    <div className="flex items-center text-xs text-neutral-600 dark:text-neutral-400 gap-2">
                                        <FileCode className="h-4.5 w-4.5 opacity-80" />
                                        <span className="truncate" title={provider.class}>{provider.class}</span>
                                    </div>

                                    <div className="flex items-center text-xs text-neutral-600 dark:text-neutral-400 gap-2">
                                        <Globe className="h-4.5 w-4.5 opacity-80" />
                                        <span>Countries: <span className="font-semibold text-neutral-800 dark:text-neutral-200">{countryString}</span></span>
                                    </div>

                                    <div className="flex items-center text-xs text-neutral-600 dark:text-neutral-400 gap-2">
                                        <Key className="h-4.5 w-4.5 opacity-80" />
                                        <span>API Key: <span className="font-mono bg-neutral-100 dark:bg-neutral-800 px-1 py-0.5 rounded text-neutral-800 dark:text-neutral-200">••••••••</span></span>
                                    </div>
                                </CardContent>
                                <CardFooter className="flex justify-end gap-2 border-t border-sidebar-border/30 dark:border-sidebar-border/50 pt-3">
                                    <Button variant="outline" size="sm" onClick={() => openEditModal(provider)} className="cursor-pointer">
                                        Edit
                                    </Button>
                                    <Button variant="destructive" size="sm" onClick={() => handleDelete(provider.id)} className="cursor-pointer">
                                        <Trash2 className="h-4 w-4" />
                                    </Button>
                                </CardFooter>
                            </Card>
                        );
                    })}

                    {providers.length === 0 && (
                        <div className="col-span-full border border-dashed border-neutral-300 dark:border-neutral-700 rounded-xl p-12 text-center">
                            <Settings className="h-12 w-12 text-neutral-400 mx-auto mb-4" />
                            <h3 className="font-semibold text-neutral-900 dark:text-neutral-100">No payment providers</h3>
                            <p className="text-sm text-neutral-500 dark:text-neutral-400 mt-1 mb-4">
                                Get started by adding your first payment gateway credentials.
                            </p>
                            <Button onClick={openCreateModal} className="cursor-pointer">Add Provider</Button>
                        </div>
                    )}
                </div>
            </div>

            {/* Create Modal */}
            <Dialog open={isCreateOpen} onOpenChange={setIsCreateOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Add Payment Provider</DialogTitle>
                        <DialogDescription>
                            Configure a new integration class and assign appropriate credentials.
                        </DialogDescription>
                    </DialogHeader>
                    <form onSubmit={handleCreate} className="space-y-4 py-2">
                        <div className="space-y-1">
                            <Label htmlFor="name">Provider Name</Label>
                            <Input
                                id="name"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                placeholder="e.g. Lenco Production"
                                required
                            />
                            {errors.name && <p className="text-xs text-red-500">{errors.name}</p>}
                        </div>

                        <div className="space-y-1">
                            <Label htmlFor="class">Driver Class Name</Label>
                            <Input
                                id="class"
                                value={data.class}
                                onChange={(e) => setData('class', e.target.value)}
                                placeholder="App\Http\Controllers\Providers\LencoController"
                                required
                            />
                            {errors.class && <p className="text-xs text-red-500">{errors.class}</p>}
                        </div>

                        <div className="space-y-1">
                            <Label htmlFor="api_key">API Key / Token</Label>
                            <Input
                                id="api_key"
                                type="password"
                                value={data.api_key}
                                onChange={(e) => setData('api_key', e.target.value)}
                                placeholder="sk_live_..."
                                required
                            />
                            {errors.api_key && <p className="text-xs text-red-500">{errors.api_key}</p>}
                        </div>

                        <div className="space-y-1">
                            <Label htmlFor="supported_countries">Supported Countries (Comma separated)</Label>
                            <Input
                                id="supported_countries"
                                value={data.supported_countries}
                                onChange={(e) => setData('supported_countries', e.target.value)}
                                placeholder="ZM,MW"
                                required
                            />
                            {errors.supported_countries && <p className="text-xs text-red-500">{errors.supported_countries}</p>}
                        </div>

                        <div className="space-y-1">
                            <Label htmlFor="logo_url">Logo URL (Optional)</Label>
                            <Input
                                id="logo_url"
                                type="url"
                                value={data.logo_url}
                                onChange={(e) => setData('logo_url', e.target.value)}
                                placeholder="https://..."
                            />
                            {errors.logo_url && <p className="text-xs text-red-500">{errors.logo_url}</p>}
                        </div>

                        <div className="flex items-center space-x-2 pt-2">
                            <Checkbox
                                id="is_active"
                                checked={data.is_active}
                                onCheckedChange={(checked) => setData('is_active', !!checked)}
                            />
                            <Label htmlFor="is_active" className="text-sm font-medium cursor-pointer">
                                Active (enable this provider in the switch routing queue)
                            </Label>
                        </div>

                        <DialogFooter className="pt-4">
                            <Button type="button" variant="outline" onClick={() => setIsCreateOpen(false)} className="cursor-pointer">
                                Cancel
                            </Button>
                            <Button type="submit" disabled={processing} className="cursor-pointer">
                                Create Provider
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Edit Modal */}
            <Dialog open={!!editingProvider} onOpenChange={(open) => !open && setEditingProvider(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Edit Payment Provider</DialogTitle>
                        <DialogDescription>
                            Update gateway credentials and routing configs.
                        </DialogDescription>
                    </DialogHeader>
                    <form onSubmit={handleUpdate} className="space-y-4 py-2">
                        <div className="space-y-1">
                            <Label htmlFor="edit-name">Provider Name</Label>
                            <Input
                                id="edit-name"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                required
                            />
                            {errors.name && <p className="text-xs text-red-500">{errors.name}</p>}
                        </div>

                        <div className="space-y-1">
                            <Label htmlFor="edit-class">Driver Class Name</Label>
                            <Input
                                id="edit-class"
                                value={data.class}
                                onChange={(e) => setData('class', e.target.value)}
                                required
                            />
                            {errors.class && <p className="text-xs text-red-500">{errors.class}</p>}
                        </div>

                        <div className="space-y-1">
                            <Label htmlFor="edit-api_key">API Key / Token (leave blank to keep current)</Label>
                            <Input
                                id="edit-api_key"
                                type="password"
                                value={data.api_key}
                                onChange={(e) => setData('api_key', e.target.value)}
                                placeholder="••••••••"
                            />
                            {errors.api_key && <p className="text-xs text-red-500">{errors.api_key}</p>}
                        </div>

                        <div className="space-y-1">
                            <Label htmlFor="edit-supported_countries">Supported Countries (Comma separated)</Label>
                            <Input
                                id="edit-supported_countries"
                                value={data.supported_countries}
                                onChange={(e) => setData('supported_countries', e.target.value)}
                                required
                            />
                            {errors.supported_countries && <p className="text-xs text-red-500">{errors.supported_countries}</p>}
                        </div>

                        <div className="space-y-1">
                            <Label htmlFor="edit-logo_url">Logo URL (Optional)</Label>
                            <Input
                                id="edit-logo_url"
                                type="url"
                                value={data.logo_url}
                                onChange={(e) => setData('logo_url', e.target.value)}
                            />
                            {errors.logo_url && <p className="text-xs text-red-500">{errors.logo_url}</p>}
                        </div>

                        <div className="flex items-center space-x-2 pt-2">
                            <Checkbox
                                id="edit-is_active"
                                checked={data.is_active}
                                onCheckedChange={(checked) => setData('is_active', !!checked)}
                            />
                            <Label htmlFor="edit-is_active" className="text-sm font-medium cursor-pointer">
                                Active (enable this provider in the switch routing queue)
                            </Label>
                        </div>

                        <DialogFooter className="pt-4">
                            <Button type="button" variant="outline" onClick={() => setEditingProvider(null)} className="cursor-pointer">
                                Cancel
                            </Button>
                            <Button type="submit" disabled={processing} className="cursor-pointer">
                                Save Changes
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}

Index.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: '/dashboard',
        },
        {
            title: 'Providers',
            href: '/providers',
        },
    ],
};
