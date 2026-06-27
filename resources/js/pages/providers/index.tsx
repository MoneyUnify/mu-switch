import { Head, useForm } from '@inertiajs/react';
import { Plus, Settings, Trash2, CheckCircle2, XCircle, Globe, Key, FileCode } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { Button, HoverCard } from '@radix-ui/themes';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Badge } from '@/components/ui/badge';
import providersRoute from '@/routes/providers';
import { cn } from '@/lib/utils';

interface PaymentProvider {
    id: number;
    name: string;
    class: string;
    config: {
        supported_countries?: string | string[];
        [key: string]: unknown;
    } | null;
    logo_url: string | null;
    is_active: boolean;
}

interface ConfigField {
    key: string;
    label: string;
    type: string;
    options?: string[];
}

interface MarketOption {
    code: string;
    name: string;
    currency: string;
}

interface AvailableDriver {
    name: string;
    class: string;
    supported_country_options: MarketOption[];
    default_countries: string[];
    default_logo: string | null;
    config_fields: ConfigField[];
}

const FALLBACK_CONFIG_FIELDS: ConfigField[] = [{ key: 'api_key', label: 'API Key / Token', type: 'password' }];

/**
 * Compact summary of supported markets: lists them inline when few, otherwise a
 * count with a Radix hover-card revealing all of them (so the card never breaks).
 */
function MarketsSummary({ labels }: { labels: string[] }) {
    if (labels.length === 0) {
        return <span className="font-medium text-neutral-700 dark:text-neutral-300">—</span>;
    }

    if (labels.length <= 4) {
        return <span className="font-medium text-neutral-700 dark:text-neutral-300">{labels.join(', ')}</span>;
    }

    return (
        <HoverCard.Root>
            <HoverCard.Trigger>
                <span className="cursor-default font-medium text-neutral-700 underline decoration-dotted underline-offset-2 dark:text-neutral-300">
                    {labels.length} countries
                </span>
            </HoverCard.Trigger>
            <HoverCard.Content size="1" maxWidth="280px">
                <div className="flex flex-wrap gap-1">
                    {labels.map((label) => (
                        <span key={label} className="rounded bg-neutral-100 px-1.5 py-0.5 text-xs text-neutral-700 dark:bg-neutral-800 dark:text-neutral-200">
                            {label}
                        </span>
                    ))}
                </div>
            </HoverCard.Content>
        </HoverCard.Root>
    );
}

interface IndexProps {
    providers: PaymentProvider[];
    availableDrivers: AvailableDriver[];
}

export default function Index({ providers, availableDrivers = [] }: IndexProps) {
    const [isCreateOpen, setIsCreateOpen] = useState(false);
    const [editingProvider, setEditingProvider] = useState<PaymentProvider | null>(null);
    const [selectedDriver, setSelectedDriver] = useState<AvailableDriver | null>(null);

    const {
        data,
        setData,
        post,
        put,
        delete: destroy,
        processing,
        errors,
        reset,
    } = useForm<{
        name: string;
        class: string;
        config: Record<string, string>;
        supported_countries: string[];
        logo_url: string;
        is_active: boolean;
    }>({
        name: '',
        class: '',
        config: {},
        supported_countries: [],
        logo_url: '',
        is_active: true,
    });

    const toggleCountry = (code: string) =>
        setData(
            'supported_countries',
            data.supported_countries.includes(code) ? data.supported_countries.filter((c) => c !== code) : [...data.supported_countries, code],
        );

    const fieldErrors = errors as Record<string, string | undefined>;
    const setConfigField = (key: string, value: string) => setData('config', { ...data.config, [key]: value });
    const editDriver = availableDrivers.find((d) => d.class === editingProvider?.class);

    // Seed config values: existing value, else the first option for selects, else blank.
    const initialConfig = (fields: ConfigField[], existing: Record<string, unknown> = {}): Record<string, string> =>
        Object.fromEntries(fields.map((f) => [f.key, String(existing[f.key] ?? f.options?.[0] ?? '')]));

    // Renders a credential input — a dropdown for fields with fixed options, a text/password input otherwise.
    const configFieldRow = (field: ConfigField, idPrefix: string, required: boolean, labelSuffix = '') => (
        <div key={field.key} className="space-y-1">
            <Label htmlFor={`${idPrefix}-${field.key}`}>
                {field.label}
                {labelSuffix}
            </Label>
            {field.options ? (
                <select
                    id={`${idPrefix}-${field.key}`}
                    value={data.config[field.key] || ''}
                    onChange={(e) => setConfigField(field.key, e.target.value)}
                    required={required}
                    className="border-input focus-visible:border-ring focus-visible:ring-ring/50 flex h-9 w-full rounded-full border bg-transparent px-3.5 py-1 text-sm shadow-xs outline-none focus-visible:ring-[3px]"
                >
                    {field.options.map((opt) => (
                        <option key={opt} value={opt}>
                            {opt}
                        </option>
                    ))}
                </select>
            ) : (
                <Input
                    id={`${idPrefix}-${field.key}`}
                    type={field.type}
                    value={data.config[field.key] || ''}
                    onChange={(e) => setConfigField(field.key, e.target.value)}
                    placeholder={field.type === 'password' ? '••••••••' : ''}
                    required={required}
                />
            )}
            {fieldErrors[`config.${field.key}`] && <p className="text-xs text-red-500">{fieldErrors[`config.${field.key}`]}</p>}
        </div>
    );

    // Renders the supported-markets checkbox grid (name + ISO code + currency).
    const marketsCheckboxes = (options: MarketOption[]) => (
        <div className="space-y-1">
            <Label>Supported Countries</Label>
            {options.length === 0 ? (
                <p className="text-xs text-neutral-400">This driver has no configurable markets.</p>
            ) : (
                <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                    {options.map((market) => {
                        const checked = data.supported_countries.includes(market.code);
                        return (
                            <label
                                key={market.code}
                                className={cn(
                                    'flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 text-sm transition-colors',
                                    checked ? 'border-primary bg-primary/5' : 'border-neutral-200 hover:border-neutral-300 dark:border-neutral-800',
                                )}
                            >
                                <Checkbox checked={checked} onCheckedChange={() => toggleCountry(market.code)} />
                                <span className="flex flex-col leading-tight">
                                    <span className="font-medium">{market.name}</span>
                                    <span className="text-xs text-neutral-400">
                                        {market.code} · {market.currency}
                                    </span>
                                </span>
                            </label>
                        );
                    })}
                </div>
            )}
            {fieldErrors['supported_countries'] && <p className="text-xs text-red-500">{fieldErrors['supported_countries']}</p>}
        </div>
    );

    const openCreateModal = () => {
        reset();
        setSelectedDriver(null);
        setIsCreateOpen(true);
    };

    const openEditModal = (provider: PaymentProvider) => {
        setEditingProvider(provider);
        const driver = availableDrivers.find((d) => d.class === provider.class);
        const fields = driver?.config_fields?.length ? driver.config_fields : FALLBACK_CONFIG_FIELDS;
        const countries = provider.config?.supported_countries;
        setData({
            name: provider.name,
            class: provider.class,
            config: initialConfig(fields, provider.config ?? {}),
            supported_countries: Array.isArray(countries) ? countries : countries ? [countries] : [],
            logo_url: provider.logo_url || '',
            is_active: provider.is_active,
        });
    };

    const handleCreate = (e: React.FormEvent) => {
        e.preventDefault();
        post(providersRoute.store.url(), {
            onSuccess: () => {
                setIsCreateOpen(false);
                setSelectedDriver(null);
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
                        <h1 className="text-lg font-semibold tracking-tight">Payment Providers</h1>
                        <p className="text-xs text-neutral-600 dark:text-neutral-400 mt-1">
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
                        const codes = Array.isArray(countries) ? countries : countries ? [countries] : [];
                        const driverOptions = availableDrivers.find((d) => d.class === provider.class)?.supported_country_options ?? [];
                        const countryLabels = codes.map((code) => driverOptions.find((o) => o.code === code)?.name ?? code);

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
                                            <CardTitle className="text-sm font-semibold">{provider.name}</CardTitle>
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

                                    <div className="flex items-center gap-2 text-xs text-neutral-600 dark:text-neutral-400">
                                        <Globe className="h-4.5 w-4.5 shrink-0 opacity-80" />
                                        <span className="flex items-center gap-1">
                                            Countries: <MarketsSummary labels={countryLabels} />
                                        </span>
                                    </div>

                                    <div className="flex items-center text-xs text-neutral-600 dark:text-neutral-400 gap-2">
                                        <Key className="h-4.5 w-4.5 opacity-80" />
                                        <span>Credentials: <span className="font-mono bg-neutral-100 dark:bg-neutral-800 px-1 py-0.5 rounded text-neutral-800 dark:text-neutral-200">••••••••</span></span>
                                    </div>
                                </CardContent>
                                <CardFooter className="flex justify-end gap-2 border-t border-sidebar-border/30 dark:border-sidebar-border/50 pt-3">
                                    <Button variant="outline" size="1" onClick={() => openEditModal(provider)} className="cursor-pointer">
                                        Edit
                                    </Button>
                                    <Button color="red" size="1" onClick={() => handleDelete(provider.id)} className="cursor-pointer">
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
            <Dialog open={isCreateOpen} onOpenChange={(open) => {
                setIsCreateOpen(open);
                if (!open) setSelectedDriver(null);
            }}>
                <DialogContent className={selectedDriver === null ? "sm:max-w-[500px]" : "sm:max-w-[450px]"}>
                    <DialogHeader>
                        <DialogTitle>
                            {selectedDriver === null ? "Select Provider Driver" : `Configure ${selectedDriver.name}`}
                        </DialogTitle>
                        <DialogDescription>
                            {selectedDriver === null
                                ? "Choose which payment provider driver you want to configure for the switch."
                                : `Configure settings for the ${selectedDriver.name} driver.`}
                        </DialogDescription>
                    </DialogHeader>

                    {selectedDriver === null ? (
                        <div className="space-y-4 py-2">
                            <div className="grid gap-3 grid-cols-1 sm:grid-cols-2 mt-2 max-h-[350px] overflow-y-auto pr-1">
                                {availableDrivers.map((driver) => (
                                    <div
                                        key={driver.class}
                                        onClick={() => {
                                            setSelectedDriver(driver);
                                            setData((d) => ({
                                                ...d,
                                                class: driver.class,
                                                name: d.name || driver.name,
                                                supported_countries: d.supported_countries.length ? d.supported_countries : driver.default_countries,
                                                logo_url: d.logo_url || driver.default_logo || '',
                                                config: initialConfig(driver.config_fields?.length ? driver.config_fields : FALLBACK_CONFIG_FIELDS),
                                            }));
                                        }}
                                        className="group flex flex-col justify-between p-4 rounded-xl border border-neutral-200 dark:border-neutral-800 hover:border-primary bg-neutral-50/50 dark:bg-neutral-900/50 cursor-pointer transition-all duration-200 shadow-sm hover:shadow-md"
                                    >
                                        <div className="space-y-1">
                                            <div className="font-semibold text-neutral-900 dark:text-neutral-100 group-hover:text-primary flex items-center gap-2">
                                                {driver.default_logo ? (
                                                    <img
                                                        src={driver.default_logo}
                                                        alt={driver.name}
                                                        className="h-4 w-4 object-contain"
                                                        onError={(e) => (e.currentTarget.style.display = 'none')}
                                                    />
                                                ) : (
                                                    <FileCode className="h-4 w-4 text-neutral-500" />
                                                )}
                                                {driver.name}
                                            </div>
                                            <div className="text-xs text-neutral-500 dark:text-neutral-400 font-mono truncate max-w-[200px]" title={driver.class}>
                                                {driver.class.split('\\').pop()}
                                            </div>
                                        </div>
                                        <div className="text-xs text-neutral-500 mt-4 flex items-center gap-1">
                                            <Globe className="h-3 w-3 shrink-0" />
                                            Supports: <MarketsSummary labels={driver.supported_country_options.map((m) => m.name)} />
                                        </div>
                                    </div>
                                ))}

                                {availableDrivers.length === 0 && (
                                    <div className="col-span-full text-center py-6 text-sm text-neutral-500">
                                        No drivers discovered in app/Http/Controllers/Providers/
                                    </div>
                                )}
                            </div>

                            <DialogFooter className="pt-4 border-t border-neutral-100 dark:border-neutral-900">
                                <Button type="button" variant="outline" onClick={() => setIsCreateOpen(false)} className="cursor-pointer">
                                    Cancel
                                </Button>
                            </DialogFooter>
                        </div>
                    ) : (
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
                                    disabled
                                    className="bg-neutral-100 dark:bg-neutral-900 cursor-not-allowed opacity-80"
                                    required
                                />
                                {errors.class && <p className="text-xs text-red-500">{errors.class}</p>}
                            </div>

                            {(selectedDriver.config_fields?.length ? selectedDriver.config_fields : FALLBACK_CONFIG_FIELDS).map((field) =>
                                configFieldRow(field, 'cfg', true),
                            )}

                            {marketsCheckboxes(selectedDriver.supported_country_options)}

                            <div className="space-y-1">
                                <Label htmlFor="logo_url">Logo URL (Optional)</Label>
                                <div className="flex items-center gap-2">
                                    {data.logo_url && (
                                        <img
                                            src={data.logo_url}
                                            alt="Logo preview"
                                            className="h-9 w-9 shrink-0 rounded-md border border-neutral-200 object-contain p-0.5 dark:border-neutral-800"
                                            onError={(e) => (e.currentTarget.style.visibility = 'hidden')}
                                            onLoad={(e) => (e.currentTarget.style.visibility = 'visible')}
                                        />
                                    )}
                                    <Input
                                        id="logo_url"
                                        type="text"
                                        value={data.logo_url}
                                        onChange={(e) => setData('logo_url', e.target.value)}
                                        placeholder="https://..."
                                        className="flex-1"
                                    />
                                </div>
                                <p className="text-xs text-neutral-400">A default logo is pre-filled for the driver — change it if you'd like.</p>
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

                            <DialogFooter className="pt-4 border-t border-neutral-100 dark:border-neutral-900 flex flex-row justify-between sm:justify-between items-center gap-2">
                                <Button type="button" variant="ghost" onClick={() => setSelectedDriver(null)} className="cursor-pointer gap-1">
                                    &larr; Back
                                </Button>
                                <div className="flex gap-2">
                                    <Button type="button" variant="outline" onClick={() => setIsCreateOpen(false)} className="cursor-pointer">
                                        Cancel
                                    </Button>
                                    <Button type="submit" disabled={processing} className="cursor-pointer">
                                        Create Provider
                                    </Button>
                                </div>
                            </DialogFooter>
                        </form>
                    )}
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
                                disabled
                                className="bg-neutral-100 dark:bg-neutral-900 cursor-not-allowed opacity-80"
                                required
                            />
                            {errors.class && <p className="text-xs text-red-500">{errors.class}</p>}
                        </div>

                        {(editDriver?.config_fields?.length ? editDriver.config_fields : FALLBACK_CONFIG_FIELDS).map((field) =>
                            configFieldRow(field, 'edit-cfg', false, field.options ? '' : ' (leave blank to keep current)'),
                        )}

                        {marketsCheckboxes(editDriver?.supported_country_options ?? [])}

                        <div className="space-y-1">
                            <Label htmlFor="edit-logo_url">Logo URL (Optional)</Label>
                            <div className="flex items-center gap-2">
                                {data.logo_url && (
                                    <img
                                        src={data.logo_url}
                                        alt="Logo preview"
                                        className="h-9 w-9 shrink-0 rounded-md border border-neutral-200 object-contain p-0.5 dark:border-neutral-800"
                                        onError={(e) => (e.currentTarget.style.visibility = 'hidden')}
                                        onLoad={(e) => (e.currentTarget.style.visibility = 'visible')}
                                    />
                                )}
                                <Input
                                    id="edit-logo_url"
                                    type="text"
                                    value={data.logo_url}
                                    onChange={(e) => setData('logo_url', e.target.value)}
                                    className="flex-1"
                                />
                            </div>
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
