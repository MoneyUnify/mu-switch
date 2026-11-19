import { Link, usePage } from '@inertiajs/react';
import { Button } from '@radix-ui/themes';
import { Github } from 'lucide-react';
import { useEffect, useState } from 'react';
import { cn } from '@/lib/utils';
import { dashboard, home, login, register } from '@/routes';

export const GITHUB_URL = 'https://github.com/moneyUnify/mu-switch';

/**
 * The top navigation on public pages: the landing page, privacy and terms.
 * It stays fixed at the top while the page scrolls: clear over the page at
 * first, then compact on a frosted background once the page has moved.
 */
export function PublicHeader({
    githubUrl = GITHUB_URL,
    docsOnly = false,
}: {
    githubUrl?: string;
    /** The docs-only deployment has no accounts, so no sign-in links. */
    docsOnly?: boolean;
}) {
    const { auth } = usePage().props;
    const [scrolled, setScrolled] = useState(false);

    useEffect(() => {
        const update = () => setScrolled(window.scrollY > 8);

        update();
        window.addEventListener('scroll', update, { passive: true });

        return () => window.removeEventListener('scroll', update);
    }, []);

    return (
        <>
            <div
                className={cn(
                    'fixed inset-x-0 top-0 z-50 border-b transition-[background-color,border-color,box-shadow] duration-300',
                    scrolled
                        ? 'border-neutral-200/80 bg-[#FDFDFC]/80 shadow-sm backdrop-blur-md dark:border-neutral-800/80 dark:bg-[#0a0a0a]/80'
                        : 'border-transparent bg-transparent',
                )}
            >
                <header
                    className={cn(
                        'mx-auto flex w-full max-w-7xl items-center justify-between gap-4 px-4 transition-[padding] duration-300 sm:px-6',
                        scrolled ? 'py-3' : 'py-6',
                    )}
                >
                    <Link
                        href={home()}
                        className="flex shrink-0 items-center gap-2.5"
                    >
                        <img
                            src="/moneyunify-icon.png"
                            alt="MoneyUnify"
                            className="h-8 w-8 object-contain"
                        />
                        <span className="hidden text-lg font-semibold tracking-tight text-neutral-900 min-[400px]:inline dark:text-white">
                            MoneyUnify
                        </span>
                    </Link>
                    <nav className="flex items-center gap-4 sm:gap-8">
                        <span className="hidden sm:inline-flex">
                            <Button
                                asChild
                                size="2"
                                variant="ghost"
                                color="gray"
                                className="cursor-pointer"
                            >
                                <a href="/docs">Docs</a>
                            </Button>
                        </span>
                        <Button
                            asChild
                            size="2"
                            variant="ghost"
                            color="gray"
                            className="cursor-pointer"
                        >
                            <a
                                href={githubUrl}
                                target="_blank"
                                rel="noopener noreferrer"
                                aria-label="MoneyUnify on GitHub"
                            >
                                <Github className="h-4 w-4" aria-hidden />
                            </a>
                        </Button>
                        {!docsOnly && auth?.user ? (
                            <Button asChild size="2" className="cursor-pointer">
                                <Link href={dashboard()}>Dashboard</Link>
                            </Button>
                        ) : !docsOnly ? (
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
                        ) : null}
                    </nav>
                </header>
            </div>
            {/* Keeps the page's content clear of the fixed header. */}
            <div className="h-20 shrink-0" aria-hidden />
        </>
    );
}
