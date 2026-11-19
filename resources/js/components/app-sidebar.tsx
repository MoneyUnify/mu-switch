import { Link } from '@inertiajs/react';
import {
    ArrowLeftRight,
    ArrowUpRight,
    BookOpen,
    Bot,
    Landmark,
    LayoutGrid,
    Percent,
    ScrollText,
    Send,
    Sliders,
    Store,
    Wallet,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { index as aiIndex } from '@/routes/ai';
import feePolicy from '@/routes/fee-policy';
import logs from '@/routes/logs';
import merchantsRoute from '@/routes/merchants';
import payments from '@/routes/payments';
import providers from '@/routes/providers';
import rates from '@/routes/rates';
import { index as sendIndex } from '@/routes/send';
import { index as transfersIndex } from '@/routes/transfers';
import { index as treasuryIndex } from '@/routes/treasury';
import type { NavGroup, NavItem } from '@/types';

const navGroups: NavGroup[] = [
    {
        title: 'Platform',
        items: [
            {
                title: 'Dashboard',
                href: dashboard(),
                icon: LayoutGrid,
            },
        ],
    },
    {
        title: 'Transactions',
        items: [
            {
                title: 'Payments',
                href: payments.index.url(),
                icon: Wallet,
            },
            {
                title: 'Transfers',
                href: transfersIndex.url(),
                icon: ArrowUpRight,
            },
            {
                title: 'Send Money',
                href: sendIndex.url(),
                icon: Send,
            },
            {
                title: 'Merchants',
                href: merchantsRoute.index.url(),
                icon: Store,
            },
        ],
    },
    {
        title: 'Treasury & FX',
        items: [
            {
                title: 'Treasury',
                href: treasuryIndex.url(),
                icon: Landmark,
            },
            {
                title: 'Exchange Rates',
                href: rates.index.url(),
                icon: ArrowLeftRight,
            },
            {
                title: 'Fee Policy',
                href: feePolicy.show.url(),
                icon: Percent,
            },
        ],
    },
    {
        title: 'Operations',
        items: [
            {
                title: 'Providers',
                href: providers.index.url(),
                icon: Sliders,
            },
            {
                title: 'AI & Agents',
                href: aiIndex.url(),
                icon: Bot,
            },
            {
                title: 'Logs',
                href: logs.index.url(),
                icon: ScrollText,
            },
        ],
    },
];

const footerNavItems: NavItem[] = [
    {
        title: 'Documentation',
        href: '/docs',
        icon: BookOpen,
    },
];

export function AppSidebar() {
    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain groups={navGroups} />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
