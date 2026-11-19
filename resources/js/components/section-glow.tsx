import { cn } from '@/lib/utils';

/** The colours the glows mix: the brand blue, a teal and a violet. */
const TONES = {
    primary: 'var(--primary)',
    teal: 'oklch(0.74 0.13 200)',
    violet: 'oklch(0.62 0.16 290)',
} as const;

function blob(tone: keyof typeof TONES, strength = 'var(--glow-strength)') {
    return `radial-gradient(closest-side, color-mix(in oklab, ${TONES[tone]} ${strength}, transparent), transparent)`;
}

/**
 * Soft, slowly drifting colour on one side of a section that fades into the
 * content. Place it inside a `relative isolate overflow-hidden` section.
 * `offset` staggers the motion so neighbouring sections never move in step.
 */
export function SectionGlow({
    side,
    offset = 0,
}: {
    side: 'left' | 'right';
    offset?: number;
}) {
    const direction = side === 'left' ? 1 : -1;

    return (
        <div
            aria-hidden
            className={cn(
                'pointer-events-none absolute inset-y-0 -z-10 w-[min(44rem,75%)]',
                side === 'left' ? 'left-0' : 'right-0',
            )}
            style={{
                maskImage: `linear-gradient(to ${side === 'left' ? 'right' : 'left'}, black 40%, transparent)`,
                WebkitMaskImage: `linear-gradient(to ${side === 'left' ? 'right' : 'left'}, black 40%, transparent)`,
            }}
        >
            <div
                className={cn(
                    'absolute top-[calc(50%-17rem)] h-[34rem] w-[34rem] glow-blob rounded-full',
                    side === 'left' ? '-left-48' : '-right-48',
                )}
                style={
                    {
                        background: blob('primary'),
                        '--glow-x': `${direction * 20}%`,
                        '--glow-y': '14%',
                        '--glow-duration': '18s',
                        '--glow-delay': `${-offset * 7}s`,
                    } as React.CSSProperties
                }
            />
            <div
                className={cn(
                    'absolute top-[8%] h-[22rem] w-[22rem] glow-blob rounded-full',
                    side === 'left' ? 'left-24' : 'right-24',
                )}
                style={
                    {
                        background: blob(
                            'teal',
                            'calc(var(--glow-strength) * 0.9)',
                        ),
                        '--glow-x': `${direction * 30}%`,
                        '--glow-y': '24%',
                        '--glow-duration': '24s',
                        '--glow-delay': `${-offset * 11 - 9}s`,
                    } as React.CSSProperties
                }
            />
            <div
                className={cn(
                    'absolute bottom-[6%] h-[18rem] w-[18rem] glow-blob rounded-full',
                    side === 'left' ? 'left-8' : 'right-8',
                )}
                style={
                    {
                        background: blob(
                            'violet',
                            'calc(var(--glow-strength) * 0.7)',
                        ),
                        '--glow-x': `${direction * 36}%`,
                        '--glow-y': '20%',
                        '--glow-duration': '21s',
                        '--glow-delay': `${-offset * 5 - 4}s`,
                    } as React.CSSProperties
                }
            />
        </div>
    );
}
