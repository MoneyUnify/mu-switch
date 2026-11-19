import { useEffect, useRef, useState } from 'react';
import { cn } from '@/lib/utils';

/** How much must be on screen before content appears; more than its 32px slide. */
const SHOW_AFTER_PX = 64;

/** Check every 5% of the element, so SHOW_AFTER_PX is noticed promptly. */
const THRESHOLDS = Array.from({ length: 21 }, (_, step) => step / 20);

/**
 * Fades and lifts its content in as it scrolls into view, and back out as it
 * leaves, so it animates both scrolling down and scrolling back up. People
 * who prefer reduced motion see the content without animation.
 */
export function Reveal({
    children,
    delay = 0,
    className,
}: {
    children: React.ReactNode;
    /** Milliseconds to wait before animating, to stagger siblings. */
    delay?: number;
    className?: string;
}) {
    const element = useRef<HTMLDivElement | null>(null);
    const [visible, setVisible] = useState(false);

    useEffect(() => {
        const node = element.current;

        if (!node || typeof IntersectionObserver === 'undefined') {
            setVisible(true);

            return;
        }

        // Show once a good piece is on screen, hide only once it's fully off.
        // The gap between the two is wider than the slide, so sliding in or
        // out can never flip the state back (which made content blink when
        // it rested at the edge of the screen).
        const observer = new IntersectionObserver(
            ([entry]) => {
                const onScreen = entry.intersectionRect.height;
                const enough = Math.min(
                    SHOW_AFTER_PX,
                    entry.boundingClientRect.height,
                );

                if (onScreen >= enough) {
                    setVisible(true);
                } else if (!entry.isIntersecting) {
                    setVisible(false);
                }
            },
            { threshold: THRESHOLDS },
        );

        observer.observe(node);

        return () => observer.disconnect();
    }, []);

    return (
        <div
            ref={element}
            style={{ transitionDelay: visible ? `${delay}ms` : '0ms' }}
            className={cn(
                'transition-all duration-700 ease-out motion-reduce:translate-y-0 motion-reduce:opacity-100 motion-reduce:transition-none',
                visible
                    ? 'translate-y-0 opacity-100'
                    : 'translate-y-8 opacity-0',
                className,
            )}
        >
            {children}
        </div>
    );
}
