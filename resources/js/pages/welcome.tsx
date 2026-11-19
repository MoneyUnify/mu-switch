import { Head, Link, usePage } from '@inertiajs/react';
import { Button } from '@radix-ui/themes';
import {
    ArrowLeftRight,
    CreditCard,
    Download,
    Github,
    ImageIcon,
    Smartphone,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useEffect, useState } from 'react';
import { CodeSample } from '@/components/code-sample';
import type { CodeTab } from '@/components/code-sample';
import { EngineStack } from '@/components/engine-stack';
import { McpSession } from '@/components/mcp-session';
import { PublicFooter } from '@/components/public-footer';
import { GITHUB_URL, PublicHeader } from '@/components/public-header';
import { RaisedFrame } from '@/components/raised-frame';
import { Reveal } from '@/components/reveal';
import { SectionGlow } from '@/components/section-glow';
import { cn } from '@/lib/utils';
import { dashboard, register } from '@/routes';

interface Country {
    code: string;
    name: string;
    /** Payers there can pay in with PayPal. */
    paypal?: boolean;
    /** Mobile money is collected and paid out there. */
    mobile_money?: boolean;
}

interface Stats {
    /** Countries where mobile money is collected and paid out. */
    countries: number;
    /** Countries payers can pay in from with PayPal. */
    paypal_countries?: number;
    /** Both together. */
    reach?: number;
    providers: number;
    currencies: number;
}

interface WelcomeProps {
    countries?: Country[];
    githubUrl?: string;
    stats?: Stats;
    docsOnly?: boolean;
}

interface Audience {
    id: string;
    eyebrow: string;
    title: string;
    body: string;
    points: string[];
    /** Where the section's photo goes once it's added, under public/. */
    image?: string;
    /** What the photo should show, until it's added. */
    imageHint?: string;
}

const AUDIENCES: Audience[] = [
    {
        id: 'providers',
        eyebrow: 'Aggregators and payment providers',
        title: 'Give your merchants PayPal, settled to mobile money',
        body: "Add PayPal to what you already offer. Your merchants share a pay link, their customers pay from anywhere in the world, and the money lands in the merchant's mobile-money wallet.",
        points: [
            'Onboard merchants with their own pay links, receipts and API keys.',
            'Customers pay with PayPal, or by card without a PayPal account.',
            'Set your own fees and FX margin, and earn on every payment.',
        ],
        image: '/images/landing/aggregators.jpg',
        imageHint: 'A shop owner taking a payment on a phone',
    },
    {
        id: 'operators',
        eyebrow: 'Mobile-money operators and banks',
        title: 'Bring money from abroad straight into your wallets',
        body: "Run the switch yourself, under your own licence and contracts. Payers in PayPal's markets send money in, and it arrives as a deposit your customers already know how to use.",
        points: [
            "New inbound volume from PayPal's 201 markets.",
            "PayPal's own exchange rate, locked for every quote.",
            'A double-entry ledger, reconciled against every provider.',
        ],
        image: '/images/landing/operators.jpg',
        imageHint: 'A mobile-money agent kiosk in a busy street',
    },
    {
        id: 'merchants',
        eyebrow: 'Merchants, freelancers and families',
        title: 'Get paid by the world, in the money you already use',
        body: 'Sell to customers abroad, invoice overseas clients, or receive money from family. They pay with PayPal; you receive it on your phone in local currency, usually in seconds.',
        points: [
            'Share a pay link by WhatsApp, SMS or email.',
            'Works on any phone, with no internet or bank account needed.',
            "If a payment can't be delivered, the payer is refunded automatically.",
        ],
        image: '/images/landing/merchants.jpg',
        imageHint: 'A market trader holding a basic mobile phone',
    },
];

const INSTITUTIONS: { title: string; body: string }[] = [
    {
        title: 'Digital financial service providers',
        body: 'Launch wallets, remittances and merchant payments on one integration, then offer them to other businesses and their customers as a payment provider.',
    },
    {
        title: 'Banks and mobile-money operators',
        body: 'Reach mobile money and PayPal from your own platform, collect and pay out across markets, and reconcile it all in a double-entry ledger.',
    },
    {
        title: 'Governments and public programmes',
        body: "Pay grants, wages and refunds straight to people's mobile wallets, even where there's no bank or internet, and collect fees, with every payment traceable.",
    },
];

const STEPS: {
    icon: LucideIcon;
    title: string;
    body: string;
    tags: string[];
}[] = [
    {
        icon: CreditCard,
        title: 'Pay from anywhere',
        body: "The payer approves in PayPal's own window, pays by debit or credit card without a PayPal account, or pays from their own mobile-money wallet.",
        tags: ['PayPal', 'Debit and credit cards', 'Mobile money'],
    },
    {
        icon: ArrowLeftRight,
        title: 'Convert at a locked rate',
        body: "PayPal's own exchange rate, locked when the payer is quoted, so the amount promised is the amount that arrives.",
        tags: ['PayPal FX', 'Locked for 10 minutes'],
    },
    {
        icon: Smartphone,
        title: 'Settle to any wallet',
        body: "The switch pays the wallet in local currency through the best available provider, usually in seconds, and refunds the payer if it can't.",
        tags: ['MTN MoMo', 'Airtel Money', 'M-Pesa', 'and more'],
    },
];

const MOBILE_MONEY_SAMPLE: CodeTab[] = [
    {
        label: 'Request',
        code: `curl -X POST https://your-domain.com/api/v1/payment/request \\
  -H "Authorization: Bearer YOUR_API_TOKEN" \\
  -H "Content-Type: application/json" \\
  -d '{
    "amount": 150.00,
    "account_number": "0976000000",
    "country": "ZM",
    "callback_url": "https://your-app.com/webhooks/payments"
  }'`,
    },
    {
        label: 'Response',
        meta: '200 OK',
        code: `{
  "status": "success",
  "message": "Payment request initiated successfully",
  "data": {
    "transaction_id": "9b6c2f1e-2a4d-4c7e-9f3a-1b2c3d4e5f6a",
    "reference": "MTN-8F2K41QZ",
    "status": "pending"
  }
}`,
    },
];

const PAYPAL_SAMPLE: CodeTab[] = [
    {
        label: 'Request',
        code: `curl -X POST https://your-domain.com/api/v1/transfer/request \\
  -H "Authorization: Bearer YOUR_API_TOKEN" \\
  -H "Idempotency-Key: send-mum-2026-10-15" \\
  -H "Content-Type: application/json" \\
  -d '{
    "amount": 2500,
    "country": "ZM",
    "account_number": "0977123456",
    "recipient_name": "Mum",
    "payer_email": "chipo@example.com"
  }'`,
    },
    {
        label: 'Response',
        meta: '200 OK',
        code: `{
  "status": "awaiting_payment",
  "message": "Transfer started. Send the sender to checkout_url to pay with PayPal.",
  "data": {
    "reference": "7f0c6c1e-3b5a-4c55-9a1d-2b7f3f9e8a10",
    "status": "awaiting_payment",
    "checkout_url": "https://www.sandbox.paypal.com/checkoutnow?token=5O190127TN364715T",
    "sender_pays": { "amount": "92.30", "currency": "USD" },
    "recipient_gets": { "amount": "2500.00", "currency": "ZMW" },
    "fx": {
      "quote_id": "2653c984-4e7f-4b34-b338-91ec79fecda0",
      "effective_rate": "27.08750000"
    }
  }
}`,
    },
];

/** Turn an ISO alpha-2 code into its flag emoji (regional indicator symbols). */
function flagEmoji(code: string): string {
    return code
        .toUpperCase()
        .replace(/./g, (char) =>
            String.fromCodePoint(127397 + char.charCodeAt(0)),
        );
}

/** Rotate an array so a copy can start at a different offset. */
function rotate<T>(items: T[], by: number): T[] {
    if (items.length === 0) {
        return items;
    }

    const n = ((by % items.length) + items.length) % items.length;

    return [...items.slice(n), ...items.slice(0, n)];
}

function CountryPill({ country }: { country: Country }) {
    return (
        <div className="flex items-center gap-2 rounded-lg border border-neutral-200 bg-white px-2.5 py-2 shadow-sm dark:border-white/10 dark:bg-white/[0.04] dark:backdrop-blur-sm">
            <span className="text-sm leading-none">
                {flagEmoji(country.code)}
            </span>
            <span className="min-w-0 text-xs leading-snug font-medium text-neutral-700 xl:truncate dark:text-white/80">
                {country.name}
            </span>
            {country.mobile_money ? (
                <span
                    className="ml-auto shrink-0 rounded-full bg-emerald-50 px-1.5 py-px text-[9px] font-semibold text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300"
                    title="Mobile money collected and paid out here"
                >
                    MoMo
                </span>
            ) : (
                <span
                    className="ml-auto h-1.5 w-1.5 shrink-0 rounded-full bg-primary/80"
                    title="Payers here pay in with PayPal"
                />
            )}
        </div>
    );
}

/**
 * A section's photo, or a placeholder naming the photo it's waiting for.
 */
function SectionImage({ src, hint }: { src: string; hint: string }) {
    const [missing, setMissing] = useState(false);

    if (!missing) {
        return (
            <img
                src={src}
                alt={hint}
                // An image that failed before hydration never fires onError.
                ref={(image) => {
                    if (image?.complete && image.naturalWidth === 0) {
                        setMissing(true);
                    }
                }}
                onError={() => setMissing(true)}
                className="aspect-[4/3] w-full rounded-2xl object-cover"
            />
        );
    }

    return (
        <div className="flex aspect-[4/3] w-full flex-col items-center justify-center gap-2 rounded-2xl border border-dashed border-neutral-300 bg-neutral-50 px-6 text-center dark:border-neutral-700 dark:bg-white/[0.03]">
            <ImageIcon
                className="h-8 w-8 text-neutral-400 dark:text-neutral-500"
                aria-hidden
            />
            <span className="text-sm font-medium text-neutral-600 dark:text-neutral-300">
                {hint}
            </span>
            <span className="font-mono text-xs break-all text-neutral-400 dark:text-neutral-500">
                public{src}
            </span>
        </div>
    );
}

function AudienceSection({
    audience,
    reversed,
    media,
}: {
    audience: Audience;
    reversed: boolean;
    /** Shown in place of the photo, e.g. a code sample. */
    media?: React.ReactNode;
}) {
    return (
        <div
            id={audience.id}
            className="grid scroll-mt-28 grid-cols-1 items-center gap-10 lg:grid-cols-2 lg:gap-16"
        >
            <Reveal
                className={cn('min-w-0', reversed ? 'lg:order-2' : undefined)}
            >
                {/* The plate peeks out towards the text, away from the page edge */}
                <RaisedFrame plate={reversed ? 'left' : 'right'}>
                    {media ?? (
                        <SectionImage
                            src={audience.image ?? ''}
                            hint={audience.imageHint ?? ''}
                        />
                    )}
                </RaisedFrame>
            </Reveal>
            <Reveal delay={120} className="max-w-xl">
                <p className="text-sm font-semibold text-primary">
                    {audience.eyebrow}
                </p>
                <h3 className="mt-2 text-2xl font-bold tracking-tight text-neutral-900 sm:text-3xl dark:text-white">
                    {audience.title}
                </h3>
                <p className="mt-4 leading-relaxed text-neutral-600 dark:text-neutral-400">
                    {audience.body}
                </p>
                <ul className="mt-6 flex flex-col gap-3">
                    {audience.points.map((point) => (
                        <li key={point} className="flex items-start gap-3">
                            <span className="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-primary" />
                            <span className="text-neutral-700 dark:text-neutral-300">
                                {point}
                            </span>
                        </li>
                    ))}
                </ul>
            </Reveal>
        </div>
    );
}

/**
 * A single vertically-scrolling column. The list is rendered twice and the
 * inner track animates by exactly one list-height, so the loop is seamless.
 */
function ScrollColumn({
    countries,
    direction,
    duration,
}: {
    countries: Country[];
    direction: 'up' | 'down';
    duration: number;
}) {
    return (
        // min-w-0 lets a column shrink to its share, so long names truncate
        // inside their pill instead of pushing the pill past the edge.
        <div className="min-w-0 flex-1">
            <div
                className="flex flex-col gap-2"
                style={{
                    animation: `mu-scroll-${direction} ${duration}s linear infinite`,
                }}
            >
                {[...countries, ...countries].map((country, index) => (
                    <CountryPill
                        key={`${country.code}-${index}`}
                        country={country}
                    />
                ))}
            </div>
        </div>
    );
}

export default function Welcome({
    countries = [],
    githubUrl = GITHUB_URL,
    stats = { countries: 0, providers: 0, currencies: 0 },
    docsOnly = false,
}: WelcomeProps) {
    const { auth } = usePage().props;

    // Load the Buy Me a Coffee floating widget. It is a self-executing script
    // that reads its own data-* attributes at load time and injects a floating
    // button, so we build the element imperatively and clean it up on unmount
    // to avoid duplicates across Inertia client-side navigations.
    useEffect(() => {
        const script = document.createElement('script');
        script.src = 'https://cdnjs.buymeacoffee.com/1.0.0/widget.prod.min.js';
        script.setAttribute('data-name', 'BMC-Widget');
        script.setAttribute('data-cfasync', 'false');
        script.setAttribute('data-id', 'mwanzabj');
        script.setAttribute(
            'data-description',
            'Support me on Buy me a coffee!',
        );
        script.setAttribute(
            'data-message',
            '❤️ Thank you for exploring MoneyUnify! DONATE a Coffee ☕️ to help the developer build faster and ship more features. 🚀',
        );
        script.setAttribute('data-color', '#5F7FFF');
        script.setAttribute('data-position', 'Right');
        script.setAttribute('data-x_margin', '18');
        script.setAttribute('data-y_margin', '18');

        // The widget dispatches DOMContentLoaded internally to build the button;
        // fire it again once the script has loaded since the page is already ready.
        script.onload = () => {
            window.dispatchEvent(new Event('DOMContentLoaded'));
        };

        document.body.appendChild(script);

        return () => {
            script.remove();
            document.getElementById('bmc-wbtn')?.remove();
            document
                .querySelectorAll('.bmc-btn-container, #bmc-iframe-wrapper')
                .forEach((element) => element.remove());
        };
    }, []);

    // Three columns with genuinely different orderings (as-is, reversed, and
    // half-rotated) so adjacent columns never mirror each other, scrolling in
    // alternating directions at slightly different speeds.
    const half = Math.floor(countries.length / 2) || 1;
    const columns = [
        { items: countries, direction: 'up' as const, duration: 70 },
        {
            items: [...countries].reverse(),
            direction: 'down' as const,
            duration: 88,
        },
        {
            items: rotate(countries, half),
            direction: 'up' as const,
            duration: 78,
        },
    ];

    return (
        <>
            <Head title="MoneyUnify Payment Switch" />

            <style>{`
                @keyframes mu-scroll-up { from { transform: translateY(0); } to { transform: translateY(-50%); } }
                @keyframes mu-scroll-down { from { transform: translateY(-50%); } to { transform: translateY(0); } }
            `}</style>

            <div className="relative overflow-hidden bg-[#FDFDFC] text-[#1b1b18] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]">
                <div className="relative flex min-h-screen flex-col">
                    {/* Ambient glow */}
                    <div className="pointer-events-none absolute -top-40 -right-32 h-[38rem] w-[38rem] rounded-full bg-primary/10 blur-3xl" />
                    <div className="pointer-events-none absolute -bottom-48 -left-32 h-[32rem] w-[32rem] rounded-full bg-primary/[0.07] blur-3xl" />

                    <PublicHeader githubUrl={githubUrl} docsOnly={docsOnly} />

                    {/* Main: content left, scrolling countries right */}
                    <div className="relative z-10 mx-auto grid w-full max-w-7xl flex-1 grid-cols-1 items-center gap-8 px-6 pt-6 pb-8 lg:grid-cols-2 lg:gap-4 lg:pt-4">
                        {/* Left — content */}
                        <div className="max-w-xl">
                            <img
                                src="/moneyunify-logo-word-below-icon.png"
                                alt="MoneyUnify"
                                className="mb-6 h-20 w-auto object-contain"
                            />

                            <h1 className="text-3xl font-bold tracking-tight text-neutral-900 sm:text-4xl dark:text-white">
                                MoneyUnify Payment Switch
                            </h1>

                            <p className="mt-3 text-base leading-relaxed text-neutral-600 sm:text-lg dark:text-neutral-400">
                                Orchestrate, route, and optimize transactions
                                across active payment provider integrations.
                            </p>

                            <ul className="mt-6 flex flex-col gap-3">
                                <li className="flex items-start gap-3">
                                    <span className="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-primary" />
                                    <span className="text-neutral-700 dark:text-neutral-300">
                                        Configure provider credentials and
                                        regional availability.
                                    </span>
                                </li>
                                <li className="flex items-start gap-3">
                                    <span className="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-primary" />
                                    <span className="text-neutral-700 dark:text-neutral-300">
                                        Maximize transaction success with
                                        automatic, sequential provider fallback.
                                    </span>
                                </li>
                                <li className="flex items-start gap-3">
                                    <span className="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-primary" />
                                    <span className="text-neutral-700 dark:text-neutral-300">
                                        Collect mobile money through a single
                                        API — add or remove providers from the
                                        dashboard without changing your code.
                                    </span>
                                </li>
                                <li className="flex items-start gap-3">
                                    <span className="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-primary" />
                                    <span className="text-neutral-700 dark:text-neutral-300">
                                        Take PayPal payments from{' '}
                                        {stats.paypal_countries ?? 'PayPal'}{' '}
                                        countries and settle them to
                                        mobile-money wallets in{' '}
                                        {stats.countries}.
                                    </span>
                                </li>
                            </ul>

                            {/* Coverage at a glance */}
                            <dl className="mt-8 grid max-w-lg grid-cols-2 gap-y-4 rounded-xl border border-neutral-200 bg-white/60 py-4 sm:grid-cols-4 sm:divide-x sm:divide-neutral-200 dark:border-neutral-800 dark:bg-white/[0.03] sm:dark:divide-neutral-800">
                                <div className="px-4 text-center">
                                    <dt className="text-2xl font-bold text-neutral-900 tabular-nums dark:text-white">
                                        {stats.reach ?? stats.countries}
                                    </dt>
                                    <dd className="mt-0.5 text-xs text-neutral-500 dark:text-neutral-400">
                                        Countries reached
                                    </dd>
                                </div>
                                <div className="px-4 text-center">
                                    <dt className="text-2xl font-bold text-neutral-900 tabular-nums dark:text-white">
                                        {stats.countries}
                                    </dt>
                                    <dd className="mt-0.5 text-xs text-neutral-500 dark:text-neutral-400">
                                        Mobile money
                                    </dd>
                                </div>
                                <div className="px-4 text-center">
                                    <dt className="text-2xl font-bold text-neutral-900 tabular-nums dark:text-white">
                                        {stats.providers}
                                    </dt>
                                    <dd className="mt-0.5 text-xs text-neutral-500 dark:text-neutral-400">
                                        Providers
                                    </dd>
                                </div>
                                <div className="px-4 text-center">
                                    <dt className="text-2xl font-bold text-neutral-900 tabular-nums dark:text-white">
                                        {stats.currencies}
                                    </dt>
                                    <dd className="mt-0.5 text-xs text-neutral-500 dark:text-neutral-400">
                                        Currencies
                                    </dd>
                                </div>
                            </dl>

                            <div className="mt-8 flex flex-wrap items-center gap-3">
                                {!docsOnly && auth.user ? (
                                    <Button
                                        asChild
                                        size="3"
                                        className="cursor-pointer"
                                    >
                                        <Link href={dashboard()}>
                                            Go to Dashboard
                                        </Link>
                                    </Button>
                                ) : !docsOnly ? (
                                    <Button
                                        asChild
                                        size="3"
                                        className="cursor-pointer"
                                    >
                                        <Link href={register()}>
                                            Get Started
                                        </Link>
                                    </Button>
                                ) : null}
                                <Button
                                    asChild
                                    size="3"
                                    variant="outline"
                                    color="gray"
                                    className="cursor-pointer"
                                >
                                    <a href="/docs">Read the docs</a>
                                </Button>
                            </div>
                        </div>

                        {/* Right — scrolling country columns (desktop only) */}
                        <div className="relative hidden h-[38rem] lg:block">
                            {/* The columns fade to transparent at the top and bottom, so the page's glow shows through */}
                            <div
                                className="pointer-events-none absolute inset-y-0 -right-6 left-0 overflow-hidden"
                                style={{
                                    maskImage:
                                        'linear-gradient(to bottom, transparent, black 7rem, black calc(100% - 7rem), transparent)',
                                    WebkitMaskImage:
                                        'linear-gradient(to bottom, transparent, black 7rem, black calc(100% - 7rem), transparent)',
                                }}
                            >
                                <div className="flex h-full gap-2">
                                    {columns.map((column, index) => (
                                        <ScrollColumn
                                            key={index}
                                            countries={column.items}
                                            direction={column.direction}
                                            duration={column.duration}
                                        />
                                    ))}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                {/* How a payment moves */}
                <section className="relative isolate z-10 overflow-hidden border-t border-neutral-200 dark:border-neutral-800">
                    <SectionGlow side="right" offset={0} />
                    <div className="mx-auto w-full max-w-7xl px-6 py-20">
                        <Reveal className="max-w-2xl">
                            <p className="text-sm font-semibold text-primary">
                                How it works
                            </p>
                            <h2 className="mt-2 text-2xl font-bold tracking-tight text-neutral-900 sm:text-3xl dark:text-white">
                                Pay anyone in Africa, from{' '}
                                {stats.reach ?? stats.countries} countries
                            </h2>
                            <p className="mt-3 leading-relaxed text-neutral-600 dark:text-neutral-400">
                                Payers anywhere in the world pay with PayPal, a
                                card or mobile money, and the money arrives in a
                                mobile-money wallet in local currency. One
                                integration, used through the API, a pay link,
                                the Send Money page or an AI agent.
                            </p>
                        </Reveal>
                        <ol className="mt-10 grid grid-cols-1 gap-4 md:grid-cols-3">
                            {STEPS.map((step, index) => (
                                <li key={step.title} className="flex">
                                    <Reveal
                                        delay={index * 120}
                                        className="flex w-full flex-col rounded-xl border border-neutral-200 bg-white/60 p-6 dark:border-neutral-800 dark:bg-white/[0.03]"
                                    >
                                        <div className="flex items-center justify-between">
                                            <span className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                                <step.icon
                                                    className="h-5 w-5"
                                                    aria-hidden
                                                />
                                            </span>
                                            <span className="text-xs font-medium text-neutral-400 tabular-nums dark:text-neutral-500">
                                                Step {index + 1}
                                            </span>
                                        </div>
                                        <h3 className="mt-5 font-semibold text-neutral-900 dark:text-white">
                                            {step.title}
                                        </h3>
                                        <p className="mt-1.5 text-sm leading-relaxed text-neutral-600 dark:text-neutral-400">
                                            {step.body}
                                        </p>
                                        <ul className="mt-auto flex flex-wrap gap-1.5 pt-5">
                                            {step.tags.map((tag) => (
                                                <li
                                                    key={tag}
                                                    className="rounded-full border border-neutral-200 px-2.5 py-0.5 text-xs text-neutral-600 dark:border-neutral-700 dark:text-neutral-300"
                                                >
                                                    {tag}
                                                </li>
                                            ))}
                                        </ul>
                                    </Reveal>
                                </li>
                            ))}
                        </ol>
                    </div>
                </section>

                {/* The engine behind the apps */}
                <section className="relative isolate z-10 overflow-hidden border-t border-neutral-200 dark:border-neutral-800">
                    <SectionGlow side="left" offset={1} />
                    <div className="mx-auto w-full max-w-7xl px-6 py-20">
                        <div className="grid grid-cols-1 items-center gap-12 lg:grid-cols-2 lg:gap-16">
                            <Reveal className="max-w-xl">
                                <p className="text-sm font-semibold text-primary">
                                    Infrastructure
                                </p>
                                <h2 className="mt-2 text-2xl font-bold tracking-tight text-neutral-900 sm:text-3xl dark:text-white">
                                    The engine behind payment apps, open to
                                    anyone
                                </h2>
                                <p className="mt-4 leading-relaxed text-neutral-600 dark:text-neutral-400">
                                    Remittance apps like Wise, Remitly and
                                    Chipper Cash each run a payment engine
                                    behind the scenes: it takes the money in,
                                    converts it, routes it and pays it out. Each
                                    of them built theirs privately.
                                </p>
                                <p className="mt-4 leading-relaxed text-neutral-600 dark:text-neutral-400">
                                    MoneyUnify is that engine, as open
                                    infrastructure. Run your own payments on it,
                                    or become a payment provider for other
                                    businesses, under your own brand, licences
                                    and data.
                                </p>
                                <ul className="mt-6 flex flex-wrap gap-2">
                                    {[
                                        'Self-hosted',
                                        'Open source (Apache-2.0)',
                                        'Your brand and licences',
                                    ].map((tag) => (
                                        <li
                                            key={tag}
                                            className="rounded-full border border-primary/25 bg-primary/5 px-3 py-1 text-xs font-medium text-primary"
                                        >
                                            {tag}
                                        </li>
                                    ))}
                                </ul>
                            </Reveal>
                            <Reveal delay={120}>
                                <EngineStack providers={stats.providers} />
                            </Reveal>
                        </div>

                        <div className="mt-16 grid grid-cols-1 gap-4 md:grid-cols-3">
                            {INSTITUTIONS.map((institution, index) => (
                                <Reveal
                                    key={institution.title}
                                    delay={index * 120}
                                    className="rounded-xl border border-neutral-200 bg-white/60 p-6 dark:border-neutral-800 dark:bg-white/[0.03]"
                                >
                                    <h3 className="font-semibold text-neutral-900 dark:text-white">
                                        {institution.title}
                                    </h3>
                                    <p className="mt-1.5 text-sm leading-relaxed text-neutral-600 dark:text-neutral-400">
                                        {institution.body}
                                    </p>
                                </Reveal>
                            ))}
                        </div>
                    </div>
                </section>

                {/* Mobile money, natively */}
                <section className="relative isolate z-10 overflow-hidden border-t border-neutral-200 dark:border-neutral-800">
                    <SectionGlow side="right" offset={2} />
                    <div className="mx-auto w-full max-w-7xl px-6 py-20">
                        <AudienceSection
                            audience={{
                                id: 'mobile-money',
                                eyebrow: 'Mobile money, natively',
                                title: 'Collect and pay out mobile money, settled in seconds',
                                body: "MoneyUnify started as a mobile-money switch, and that's still its core. Collect from and pay out to MTN, Airtel, M-Pesa and other wallets through one API, with or without PayPal.",
                                points: [
                                    `${stats.providers} payment providers across ${stats.countries} countries and ${stats.currencies} currencies, behind one API.`,
                                    'Payouts settle straight to the wallet, usually in seconds.',
                                    'Automatic fallback: the next provider is tried only when one definitely refuses, so a payout is never made twice.',
                                    'Add or remove providers from the dashboard without changing your code.',
                                ],
                                image: '/images/landing/mobile-money.jpg',
                                imageHint:
                                    'Someone checking a mobile-money payment on their phone',
                            }}
                            reversed
                        />
                    </div>
                </section>

                {/* For developers */}
                <section className="relative isolate z-10 overflow-hidden border-t border-neutral-200 dark:border-neutral-800">
                    <SectionGlow side="left" offset={3} />
                    <div className="mx-auto w-full max-w-7xl px-6 py-20">
                        <Reveal className="max-w-2xl">
                            <p className="text-sm font-semibold text-primary">
                                For developers
                            </p>
                            <h2 className="mt-2 text-2xl font-bold tracking-tight text-neutral-900 sm:text-3xl dark:text-white">
                                One API for mobile money and PayPal
                            </h2>
                            <p className="mt-3 leading-relaxed text-neutral-600 dark:text-neutral-400">
                                Every provider sits behind the same JSON API,
                                documented in an OpenAPI spec. Send one request;
                                the switch picks the provider, tracks the
                                payment and tells you when it settles.
                            </p>
                            <div className="mt-6 flex flex-wrap items-center gap-3">
                                <Button
                                    asChild
                                    size="2"
                                    className="cursor-pointer"
                                >
                                    <a href="/postman-collection.json" download>
                                        <Download
                                            className="h-4 w-4"
                                            aria-hidden
                                        />
                                        Download the Postman collection
                                    </a>
                                </Button>
                                <Button
                                    asChild
                                    size="2"
                                    variant="outline"
                                    color="gray"
                                    className="cursor-pointer"
                                >
                                    <a href="/docs/api/overview">
                                        Read the API docs
                                    </a>
                                </Button>
                            </div>
                        </Reveal>
                        <div className="mt-14 flex flex-col gap-20">
                            <AudienceSection
                                audience={{
                                    id: 'request-to-pay',
                                    eyebrow: 'Mobile money request to pay',
                                    title: "A payment prompt on the payer's phone",
                                    body: "One call sends a payment prompt straight to the payer's phone. They approve it with their PIN, on any phone, and the switch routes it through the best available provider.",
                                    points: [
                                        'Works the same way across MTN, Airtel, M-Pesa and every other provider.',
                                        'If a provider declines, the next one is tried automatically.',
                                        'Get the final result by webhook, or check it with one call.',
                                    ],
                                }}
                                media={
                                    <CodeSample
                                        title="POST /api/v1/payment/request"
                                        tabs={MOBILE_MONEY_SAMPLE}
                                    />
                                }
                                reversed
                            />
                            <AudienceSection
                                audience={{
                                    id: 'paypal-transfer',
                                    eyebrow: 'PayPal to mobile money',
                                    title: 'PayPal in, mobile money out, in one call',
                                    body: "Start a transfer and get back a PayPal checkout link, the exact amounts on both sides and PayPal's locked exchange rate. Once PayPal confirms the payment, the switch pays the wallet.",
                                    points: [
                                        'The rate is locked when the sender is quoted.',
                                        'Retries are safe with an Idempotency-Key.',
                                        "If the wallet can't be paid, the PayPal payment is refunded automatically.",
                                    ],
                                }}
                                media={
                                    <CodeSample
                                        title="POST /api/v1/transfer/request"
                                        tabs={PAYPAL_SAMPLE}
                                    />
                                }
                                reversed={false}
                            />
                        </div>
                    </div>
                </section>

                {/* Built for AI agents */}
                <section className="relative isolate z-10 overflow-hidden border-t border-neutral-200 dark:border-neutral-800">
                    <SectionGlow side="right" offset={4} />
                    <div className="mx-auto w-full max-w-7xl px-6 py-20">
                        <AudienceSection
                            audience={{
                                id: 'ai-agents',
                                eyebrow: 'Built for AI agents',
                                title: 'Any AI agent can move money, with a person in charge',
                                body: 'MoneyUnify runs its own MCP servers, so any AI assistant or agent, such as Gemini, ChatGPT or Claude, can quote, send and track payments in plain language. They use the same rules as the API, and nothing moves until a person approves it.',
                                points: [
                                    '21 tools for operators: payments, payouts, transfers, refunds, merchants, FX and treasury.',
                                    '9 tools for each merchant, limited to its own payments and pay links.',
                                    "PayPal's Agent Toolkit, read-only, so the agent can check PayPal's own record of a payment.",
                                    'Spending limits and approvals are enforced in code, not in the prompt.',
                                ],
                            }}
                            media={<McpSession />}
                            reversed
                        />
                    </div>
                </section>

                {/* Who it's for */}
                <section className="relative isolate z-10 overflow-hidden border-t border-neutral-200 dark:border-neutral-800">
                    <SectionGlow side="left" offset={5} />
                    <div className="mx-auto w-full max-w-7xl px-6 py-20">
                        <Reveal className="max-w-2xl">
                            <h2 className="text-2xl font-bold tracking-tight text-neutral-900 sm:text-3xl dark:text-white">
                                Built for everyone between the payer and the
                                wallet
                            </h2>
                            <p className="mt-3 leading-relaxed text-neutral-600 dark:text-neutral-400">
                                Use it as your own payment tool, as a gateway
                                for your merchants, or both.
                            </p>
                        </Reveal>
                        <div className="mt-14 flex flex-col gap-20">
                            {AUDIENCES.map((audience, index) => (
                                <AudienceSection
                                    key={audience.id}
                                    audience={audience}
                                    reversed={index % 2 === 1}
                                />
                            ))}
                        </div>
                    </div>
                </section>

                {/* Closing call to action */}
                <section className="relative isolate z-10 overflow-hidden border-t border-neutral-200 dark:border-neutral-800">
                    <SectionGlow side="right" offset={6} />
                    <Reveal className="mx-auto flex w-full max-w-7xl flex-col items-start gap-6 px-6 py-20 md:flex-row md:items-center md:justify-between">
                        <div className="max-w-xl">
                            <h2 className="text-2xl font-bold tracking-tight text-neutral-900 sm:text-3xl dark:text-white">
                                Open source, and yours to run
                            </h2>
                            <p className="mt-3 leading-relaxed text-neutral-600 dark:text-neutral-400">
                                Deploy MoneyUnify with your own PayPal account
                                and provider contracts, or try it on the sandbox
                                first.
                            </p>
                        </div>
                        <div className="flex flex-wrap items-center gap-3">
                            {!docsOnly && auth.user ? (
                                <Button
                                    asChild
                                    size="3"
                                    className="cursor-pointer"
                                >
                                    <Link href={dashboard()}>
                                        Go to Dashboard
                                    </Link>
                                </Button>
                            ) : !docsOnly ? (
                                <Button
                                    asChild
                                    size="3"
                                    className="cursor-pointer"
                                >
                                    <Link href={register()}>Get Started</Link>
                                </Button>
                            ) : null}
                            <Button
                                asChild
                                size="3"
                                variant="outline"
                                color="gray"
                                className="cursor-pointer"
                            >
                                <a
                                    href={githubUrl}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    <Github className="h-4 w-4" aria-hidden />
                                    View on GitHub
                                </a>
                            </Button>
                        </div>
                    </Reveal>
                </section>

                <PublicFooter />
            </div>
        </>
    );
}
