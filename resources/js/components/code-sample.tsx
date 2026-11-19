import { Check, Copy } from 'lucide-react';
import { useState } from 'react';
import { useClipboard } from '@/hooks/use-clipboard';
import { cn } from '@/lib/utils';

export interface CodeTab {
    label: string;
    /** Shown on the right of the tab bar, e.g. "200 OK". */
    meta?: string;
    code: string;
}

/** Strings (keys and values), numbers, literals, and curl's flags and verbs. */
const TOKENS =
    /("(?:[^"\\]|\\.)*")(\s*:)?|(\b-?\d+(?:\.\d+)?\b)|(\btrue\b|\bfalse\b|\bnull\b)|((?:^|\s)-{1,2}[A-Za-z-]+|\bcurl\b|\bPOST\b|\bGET\b)/gm;

/**
 * A light syntax colouring for the JSON and curl samples on the landing page.
 */
function highlight(code: string): React.ReactNode[] {
    const parts: React.ReactNode[] = [];
    let last = 0;

    for (const match of code.matchAll(TOKENS)) {
        const index = match.index ?? 0;

        if (index > last) {
            parts.push(code.slice(last, index));
        }

        const [text, string, colon, number, literal] = match;
        const className = string
            ? colon
                ? 'text-sky-300'
                : 'text-emerald-300'
            : number
              ? 'text-amber-300'
              : literal
                ? 'text-violet-300'
                : 'text-pink-300';

        parts.push(
            <span key={index} className={className}>
                {string ?? text}
            </span>,
        );

        if (colon) {
            parts.push(colon);
        }

        last = index + text.length;
    }

    parts.push(code.slice(last));

    return parts;
}

/**
 * A dark code panel with tabs (e.g. Request and Response) and a copy button.
 */
export function CodeSample({
    title,
    tabs,
}: {
    /** Shown in the panel's title bar, e.g. "POST /api/v1/payment/request". */
    title: string;
    tabs: CodeTab[];
}) {
    const [active, setActive] = useState(0);
    const [copied, copy] = useClipboard();
    const tab = tabs[active];

    return (
        <div className="overflow-hidden rounded-2xl border border-neutral-800 bg-[#0d1117] text-left shadow-xl shadow-neutral-900/10 dark:shadow-none">
            <div className="flex items-center gap-3 border-b border-white/10 px-4 py-3">
                <div className="flex gap-1.5" aria-hidden>
                    <span className="h-2.5 w-2.5 rounded-full bg-white/15" />
                    <span className="h-2.5 w-2.5 rounded-full bg-white/15" />
                    <span className="h-2.5 w-2.5 rounded-full bg-white/15" />
                </div>
                <span className="truncate font-mono text-xs text-neutral-400">
                    {title}
                </span>
            </div>

            <div className="flex items-center justify-between border-b border-white/10 px-2">
                <div role="tablist" className="flex">
                    {tabs.map((item, index) => (
                        <button
                            key={item.label}
                            type="button"
                            role="tab"
                            aria-selected={index === active}
                            onClick={() => setActive(index)}
                            className={cn(
                                '-mb-px cursor-pointer border-b-2 px-3 py-2.5 text-xs font-medium transition-colors',
                                index === active
                                    ? 'border-primary text-white'
                                    : 'border-transparent text-neutral-400 hover:text-neutral-200',
                            )}
                        >
                            {item.label}
                        </button>
                    ))}
                </div>
                <div className="flex items-center gap-3 pr-2">
                    {tab.meta && (
                        <span className="font-mono text-xs whitespace-nowrap text-emerald-400">
                            {tab.meta}
                        </span>
                    )}
                    <button
                        type="button"
                        onClick={() => copy(tab.code)}
                        className="cursor-pointer rounded-md p-1.5 text-neutral-400 transition-colors hover:bg-white/10 hover:text-white"
                        aria-label={`Copy the ${tab.label.toLowerCase()}`}
                    >
                        {copied === tab.code ? (
                            <Check className="h-3.5 w-3.5" aria-hidden />
                        ) : (
                            <Copy className="h-3.5 w-3.5" aria-hidden />
                        )}
                    </button>
                </div>
            </div>

            <pre
                role="tabpanel"
                className="max-h-[26rem] overflow-auto p-5 font-mono text-[13px] leading-relaxed text-neutral-300"
            >
                <code>{highlight(tab.code)}</code>
            </pre>
        </div>
    );
}
