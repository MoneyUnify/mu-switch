import { Bot, Check, LoaderCircle, Wrench } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { cn } from '@/lib/utils';

type Step =
    | { kind: 'user'; text: string }
    | { kind: 'agent'; text: string }
    | { kind: 'tool'; name: string; args: string; result: string };

/**
 * A scripted conversation with an AI agent connected to MoneyUnify's MCP
 * server. The tool names and arguments are the real ones; the session itself
 * is a simulation, and is labelled as one.
 */
const SCRIPT: Step[] = [
    { kind: 'user', text: 'Send K2,500 to Mum on 0977 123 456' },
    {
        kind: 'tool',
        name: 'quote_transfer',
        args: '{ "amount": 2500, "country": "ZM", "account_number": "0977123456" }',
        result: 'USD 92.30 → ZMW 2,500.00 · PayPal rate locked for 10 min',
    },
    {
        kind: 'agent',
        text: 'Mum gets ZMW 2,500.00 on Airtel Money. You pay USD 92.30 with PayPal. Shall I start it?',
    },
    { kind: 'user', text: 'Yes, go ahead' },
    {
        kind: 'tool',
        name: 'start_transfer',
        args: '{ "amount": 2500, "country": "ZM", "account_number": "0977123456", "recipient_name": "Mum" }',
        result: 'awaiting_payment · PayPal checkout link ready',
    },
    {
        kind: 'agent',
        text: 'Started. Approve the payment in PayPal and I’ll let you know when it lands.',
    },
    {
        kind: 'tool',
        name: 'check_transfer',
        args: '{ "reference": "7f0c6c1e-3b5a-4c55-9a1d-2b7f3f9e8a10" }',
        result: 'delivered · ZMW 2,500.00 paid to Airtel Money',
    },
    { kind: 'agent', text: 'Delivered. Mum has received ZMW 2,500.00.' },
];

const TYPING_MS = 38;
const TOOL_MS = 1200;
const PAUSE_MS = 900;
const RESTART_MS = 5000;

function prefersReducedMotion(): boolean {
    return (
        typeof window !== 'undefined' &&
        window.matchMedia('(prefers-reduced-motion: reduce)').matches
    );
}

/**
 * The simulated session, played step by step while it's on screen: the person
 * types, the agent calls MoneyUnify's tools and replies, then it starts over.
 */
export function McpSession() {
    const panel = useRef<HTMLDivElement | null>(null);
    const [visible, setVisible] = useState(false);
    const [step, setStep] = useState(0);
    const [typed, setTyped] = useState(0);
    const [toolDone, setToolDone] = useState(false);
    const [still] = useState(prefersReducedMotion);

    useEffect(() => {
        const node = panel.current;

        if (!node || typeof IntersectionObserver === 'undefined') {
            setVisible(true);

            return;
        }

        const observer = new IntersectionObserver(
            ([entry]) => setVisible(entry.isIntersecting),
            { threshold: 0.3 },
        );

        observer.observe(node);

        return () => observer.disconnect();
    }, []);

    useEffect(() => {
        if (still || !visible) {
            return;
        }

        const current = SCRIPT[step];
        let timer: number;

        if (!current) {
            timer = window.setTimeout(() => {
                setStep(0);
                setTyped(0);
                setToolDone(false);
            }, RESTART_MS);
        } else if (current.kind === 'user' && typed < current.text.length) {
            timer = window.setTimeout(() => setTyped(typed + 1), TYPING_MS);
        } else if (current.kind === 'tool' && !toolDone) {
            timer = window.setTimeout(() => setToolDone(true), TOOL_MS);
        } else {
            timer = window.setTimeout(() => {
                setStep(step + 1);
                setTyped(0);
                setToolDone(false);
            }, PAUSE_MS);
        }

        return () => window.clearTimeout(timer);
    }, [still, visible, step, typed, toolDone]);

    const shown = still ? SCRIPT : SCRIPT.slice(0, step + 1);

    return (
        <div
            ref={panel}
            className="overflow-hidden rounded-2xl border border-primary/15 bg-gradient-to-b from-white to-[color-mix(in_oklab,var(--primary)_9%,white)] text-left dark:border-white/10 dark:from-neutral-900 dark:to-neutral-950"
        >
            <div className="flex items-center justify-between gap-3 border-b border-primary/10 bg-white/70 px-4 py-3 dark:border-white/10 dark:bg-white/[0.02]">
                <div className="flex min-w-0 items-center gap-2">
                    <span className="relative flex h-2 w-2 shrink-0">
                        <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-primary opacity-60 motion-reduce:animate-none" />
                        <span className="relative inline-flex h-2 w-2 rounded-full bg-primary" />
                    </span>
                    <span className="truncate font-mono text-xs text-neutral-600 dark:text-neutral-300">
                        moneyunify · /mcp/operator
                    </span>
                </div>
                <span className="shrink-0 rounded-full border border-primary/20 bg-primary/5 px-2 py-0.5 text-[10px] font-medium tracking-wide text-primary uppercase dark:border-white/10 dark:bg-transparent dark:text-neutral-400">
                    Simulated
                </span>
            </div>

            <div
                className="flex h-[26rem] flex-col justify-end gap-3 overflow-hidden p-4 sm:p-5"
                style={{
                    maskImage:
                        'linear-gradient(to bottom, transparent, black 3rem)',
                    WebkitMaskImage:
                        'linear-gradient(to bottom, transparent, black 3rem)',
                }}
                aria-live="off"
            >
                {shown.map((item, index) => {
                    const isCurrent = !still && index === step;

                    if (item.kind === 'user') {
                        const text = isCurrent
                            ? item.text.slice(0, typed)
                            : item.text;

                        return (
                            <div key={index} className="flex justify-end">
                                <p className="max-w-[85%] rounded-2xl rounded-br-md bg-primary px-3.5 py-2 text-sm text-white shadow-sm shadow-primary/20">
                                    {text}
                                    {isCurrent && typed < item.text.length && (
                                        <span className="ml-0.5 inline-block h-3.5 w-px translate-y-0.5 animate-pulse bg-white" />
                                    )}
                                </p>
                            </div>
                        );
                    }

                    if (item.kind === 'tool') {
                        const done = !isCurrent || toolDone;

                        return (
                            <div
                                key={index}
                                className="animate-in rounded-xl border border-primary/15 bg-white px-3.5 py-2.5 font-mono text-xs shadow-sm shadow-primary/5 duration-500 fade-in slide-in-from-bottom-2 dark:border-white/10 dark:bg-white/[0.03] dark:shadow-none"
                            >
                                <div className="flex items-center gap-2 text-neutral-700 dark:text-neutral-300">
                                    <Wrench
                                        className="h-3.5 w-3.5 shrink-0 text-primary dark:text-sky-300"
                                        aria-hidden
                                    />
                                    <span className="font-semibold text-primary dark:font-normal dark:text-sky-300">
                                        {item.name}
                                    </span>
                                    <span className="ml-auto shrink-0">
                                        {done ? (
                                            <Check
                                                className="h-3.5 w-3.5 text-emerald-600 dark:text-emerald-400"
                                                aria-hidden
                                            />
                                        ) : (
                                            <LoaderCircle
                                                className="h-3.5 w-3.5 animate-spin text-primary/60 dark:text-neutral-400"
                                                aria-hidden
                                            />
                                        )}
                                    </span>
                                </div>
                                <p className="mt-1.5 truncate text-neutral-500 dark:text-neutral-500">
                                    {item.args}
                                </p>
                                <p
                                    className={cn(
                                        'mt-1 text-emerald-700 transition-opacity duration-300 dark:text-emerald-300',
                                        done ? 'opacity-100' : 'opacity-0',
                                    )}
                                >
                                    → {item.result}
                                </p>
                            </div>
                        );
                    }

                    return (
                        <div
                            key={index}
                            className="flex animate-in items-start gap-2.5 duration-500 fade-in slide-in-from-bottom-2"
                        >
                            <span className="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary/10 dark:bg-white/10">
                                <Bot
                                    className="h-3.5 w-3.5 text-primary dark:text-neutral-200"
                                    aria-hidden
                                />
                            </span>
                            <p className="max-w-[85%] rounded-2xl rounded-tl-md border border-primary/10 bg-white px-3.5 py-2 text-sm text-neutral-700 shadow-sm shadow-primary/5 dark:border-transparent dark:bg-white/[0.06] dark:text-neutral-200 dark:shadow-none">
                                {item.text}
                            </p>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
