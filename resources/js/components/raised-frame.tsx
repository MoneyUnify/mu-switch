import { cn } from '@/lib/utils';

/**
 * Lifts a picture or code sample off the page: a soft shadow, with a tinted
 * plate offset behind it. The plate sits towards the content side, so it never
 * runs into the page edge, and shifts a little further on hover.
 */
export function RaisedFrame({
    children,
    plate = 'right',
}: {
    children: React.ReactNode;
    /** Which side the plate peeks out on. */
    plate?: 'left' | 'right';
}) {
    return (
        <div className="group relative">
            <div
                aria-hidden
                className={cn(
                    'absolute inset-0 translate-y-4 rounded-2xl bg-primary/20 transition-transform duration-500 ease-out sm:translate-y-6 dark:bg-primary/25',
                    plate === 'right'
                        ? 'translate-x-4 group-hover:translate-x-5 sm:translate-x-6 sm:group-hover:translate-x-8'
                        : '-translate-x-4 group-hover:-translate-x-5 sm:-translate-x-6 sm:group-hover:-translate-x-8',
                    'group-hover:translate-y-5 sm:group-hover:translate-y-8',
                )}
            />
            <div className="relative rounded-2xl shadow-[0_24px_48px_-16px_rgb(15_23_42/0.35)] transition-transform duration-500 ease-out group-hover:-translate-y-1 dark:shadow-[0_24px_48px_-16px_rgb(0_0_0/0.7)]">
                {children}
            </div>
        </div>
    );
}
