import { Heart } from 'lucide-react';

export function AppFooter() {
    const year = new Date().getFullYear();

    return (
        <footer className="mt-auto border-t border-sidebar-border/50 px-6 py-4 text-center text-xs text-muted-foreground dark:border-sidebar-border">
            <span className="inline-flex flex-wrap items-center justify-center gap-1">
                © {year} MoneyUnify · built with
                <Heart className="size-3.5 fill-red-500 text-red-500" />
                so much love and passion by
                <a
                    href="https://github.com/blessedjasonmwanza"
                    target="_blank"
                    rel="noopener noreferrer"
                    className="font-medium text-foreground underline-offset-4 transition-colors hover:text-primary hover:underline"
                >
                    Blessed Jason Mwanza
                </a>
            </span>
        </footer>
    );
}
