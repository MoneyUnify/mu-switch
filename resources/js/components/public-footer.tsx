import { Link } from '@inertiajs/react';
import { home, privacy, terms } from '@/routes';

/**
 * The footer on public pages: the author's note and the legal links.
 */
export function PublicFooter() {
    return (
        <footer className="relative z-10 mx-auto w-full max-w-7xl border-t border-neutral-200 px-6 pt-6 pb-8 text-center text-sm text-neutral-500 dark:border-neutral-800 dark:text-neutral-400">
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
            <nav className="mt-3 flex items-center justify-center gap-5 text-xs">
                <Link href={home()} className="hover:text-primary">
                    Home
                </Link>
                <Link href={privacy()} className="hover:text-primary">
                    Privacy
                </Link>
                <Link href={terms()} className="hover:text-primary">
                    Terms
                </Link>
            </nav>
        </footer>
    );
}
