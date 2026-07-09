import { Head, Link, usePage } from '@inertiajs/react';
import { Button } from '@radix-ui/themes';
import { dashboard, login, register } from '@/routes';

interface Country {
    code: string;
    name: string;
}

interface Stats {
    countries: number;
    providers: number;
    currencies: number;
}

interface WelcomeProps {
    countries?: Country[];
    stats?: Stats;
}

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
    if (items.length === 0) return items;
    const n = ((by % items.length) + items.length) % items.length;
    return [...items.slice(n), ...items.slice(0, n)];
}

function CountryPill({ country }: { country: Country }) {
    return (
        <div className="flex items-center gap-2.5 rounded-xl border border-neutral-200 bg-white px-4 py-2.5 shadow-sm dark:border-white/10 dark:bg-white/[0.04] dark:backdrop-blur-sm">
            <span className="text-base leading-none">
                {flagEmoji(country.code)}
            </span>
            <span className="truncate text-sm font-medium text-neutral-700 dark:text-white/80">
                {country.name}
            </span>
            <span className="ml-auto h-1.5 w-1.5 shrink-0 rounded-full bg-primary/80" />
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
        <div className="flex-1">
            <div
                className="flex flex-col gap-3"
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
    stats = { countries: 0, providers: 0, currencies: 0 },
}: WelcomeProps) {
    const { auth } = usePage().props;

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

            <div className="relative flex min-h-screen flex-col overflow-hidden bg-[#FDFDFC] text-[#1b1b18] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]">
                {/* Ambient glow */}
                <div className="pointer-events-none absolute -top-40 -right-32 h-[38rem] w-[38rem] rounded-full bg-primary/10 blur-3xl" />
                <div className="pointer-events-none absolute -bottom-48 -left-32 h-[32rem] w-[32rem] rounded-full bg-primary/[0.07] blur-3xl" />

                {/* Header */}
                <header className="relative z-20 mx-auto flex w-full max-w-7xl items-center justify-between px-6 py-6">
                    <div className="flex items-center gap-2.5">
                        <img
                            src="/moneyunify-icon.png"
                            alt="MoneyUnify"
                            className="h-8 w-8 object-contain"
                        />
                        <span className="text-lg font-semibold tracking-tight text-neutral-900 dark:text-white">
                            MoneyUnify
                        </span>
                    </div>
                    <nav className="flex items-center gap-2">
                        <Button
                            asChild
                            size="2"
                            variant="ghost"
                            color="gray"
                            className="hidden cursor-pointer sm:inline-flex"
                        >
                            <a href="/docs">Docs</a>
                        </Button>
                        {auth.user ? (
                            <Button asChild size="2" className="cursor-pointer">
                                <Link href={dashboard()}>Dashboard</Link>
                            </Button>
                        ) : (
                            <>
                                <Button
                                    asChild
                                    size="2"
                                    variant="ghost"
                                    color="gray"
                                    className="cursor-pointer"
                                >
                                    <Link href={login()}>Sign in</Link>
                                </Button>
                                <Button
                                    asChild
                                    size="2"
                                    className="cursor-pointer"
                                >
                                    <Link href={register()}>Get Started</Link>
                                </Button>
                            </>
                        )}
                    </nav>
                </header>

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
                            Orchestrate, route, and optimize transactions across
                            active payment provider integrations.
                        </p>

                        <ul className="mt-6 flex flex-col gap-3">
                            <li className="flex items-start gap-3">
                                <span className="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-primary" />
                                <span className="text-neutral-700 dark:text-neutral-300">
                                    Configure provider credentials and regional
                                    availability.
                                </span>
                            </li>
                            <li className="flex items-start gap-3">
                                <span className="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-primary" />
                                <span className="text-neutral-700 dark:text-neutral-300">
                                    Maximize transaction success with automatic,
                                    sequential provider fallback.
                                </span>
                            </li>
                            <li className="flex items-start gap-3">
                                <span className="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-primary" />
                                <span className="text-neutral-700 dark:text-neutral-300">
                                    Collect mobile money through a single API —
                                    add or remove providers from the dashboard
                                    without changing your code.
                                </span>
                            </li>
                        </ul>

                        {/* Coverage at a glance */}
                        <dl className="mt-8 grid max-w-md grid-cols-3 divide-x divide-neutral-200 rounded-xl border border-neutral-200 bg-white/60 py-4 dark:divide-neutral-800 dark:border-neutral-800 dark:bg-white/[0.03]">
                            <div className="px-4 text-center">
                                <dt className="text-2xl font-bold text-neutral-900 tabular-nums dark:text-white">
                                    {stats.countries}
                                </dt>
                                <dd className="mt-0.5 text-xs text-neutral-500 dark:text-neutral-400">
                                    Countries
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
                            {auth.user ? (
                                <Button
                                    asChild
                                    size="3"
                                    className="cursor-pointer"
                                >
                                    <Link href={dashboard()}>
                                        Go to Dashboard
                                    </Link>
                                </Button>
                            ) : (
                                <Button
                                    asChild
                                    size="3"
                                    className="cursor-pointer"
                                >
                                    <Link href={register()}>Get Started</Link>
                                </Button>
                            )}
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
                        <div className="pointer-events-none absolute inset-0 overflow-hidden">
                            <div className="flex h-full gap-3">
                                {columns.map((column, index) => (
                                    <ScrollColumn
                                        key={index}
                                        countries={column.items}
                                        direction={column.direction}
                                        duration={column.duration}
                                    />
                                ))}
                            </div>
                            {/* Top / bottom fade into the background */}
                            <div className="absolute inset-x-0 top-0 h-28 bg-gradient-to-b from-[#FDFDFC] to-transparent dark:from-[#0a0a0a]" />
                            <div className="absolute inset-x-0 bottom-0 h-28 bg-gradient-to-t from-[#FDFDFC] to-transparent dark:from-[#0a0a0a]" />
                        </div>
                    </div>
                </div>

                {/* Footer — a heartfelt note, pinned to the bottom of the viewport */}
                <footer className="relative z-10 mx-auto w-full max-w-7xl px-6 pt-4 pb-6 text-center text-sm text-neutral-500 dark:text-neutral-400">
                    A gift from{' '}
                    <a
                        href="https://github.com/blessedjasonmwanza/"
                        target="_blank"
                        rel="noopener noreferrer"
                        className="font-semibold text-primary hover:underline"
                    >
                        Blessed Jason Mwanza
                    </a>
                    , made with so much love and passion for innovation and the
                    community.
                </footer>
            </div>
        </>
    );
}
