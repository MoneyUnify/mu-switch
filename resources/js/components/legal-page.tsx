import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { PublicFooter } from '@/components/public-footer';
import { PublicHeader } from '@/components/public-header';
import { home } from '@/routes';

export interface LegalSection {
    title: string;
    body: React.ReactNode;
}

/**
 * The privacy policy and terms pages: the landing page's header and colours,
 * with a readable column of numbered sections.
 */
export function LegalPage({
    title,
    updated,
    intro,
    sections,
    children,
}: {
    title: string;
    /** When the page last changed, e.g. "19 October 2026". */
    updated: string;
    intro: React.ReactNode;
    sections: LegalSection[];
    /** Anything after the sections, such as credits. */
    children?: React.ReactNode;
}) {
    const { docsOnly = false } = usePage<{ docsOnly?: boolean }>().props;

    return (
        <>
            <Head title={title} />

            <div className="min-h-screen bg-[#FDFDFC] text-[#1b1b18] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]">
                <PublicHeader docsOnly={docsOnly} />

                <main className="mx-auto w-full max-w-3xl px-6 pt-8 pb-20">
                    <Link
                        href={home()}
                        className="mb-8 inline-flex items-center gap-1.5 text-sm font-medium text-neutral-500 transition-colors hover:text-primary dark:text-neutral-400"
                    >
                        <ArrowLeft className="h-4 w-4" aria-hidden />
                        Back to home
                    </Link>
                    <h1 className="text-3xl font-bold tracking-tight text-neutral-900 sm:text-4xl dark:text-white">
                        {title}
                    </h1>
                    <p className="mt-2 text-sm text-neutral-500 dark:text-neutral-400">
                        Last updated {updated}
                    </p>
                    <div className="mt-6 space-y-4 leading-relaxed text-neutral-700 dark:text-neutral-300">
                        {intro}
                    </div>

                    <ol className="mt-12 space-y-10">
                        {sections.map((section, index) => (
                            <li
                                key={section.title}
                                id={`section-${index + 1}`}
                                className="scroll-mt-28"
                            >
                                <h2 className="text-xl font-semibold tracking-tight text-neutral-900 dark:text-white">
                                    <span className="mr-2 text-neutral-400 tabular-nums dark:text-neutral-500">
                                        {index + 1}.
                                    </span>
                                    {section.title}
                                </h2>
                                <div className="mt-3 space-y-3 leading-relaxed text-neutral-700 dark:text-neutral-300 [&_a]:font-medium [&_a]:text-primary [&_a]:hover:underline [&_li]:flex [&_li]:gap-3 [&_li]:before:mt-2.5 [&_li]:before:h-1.5 [&_li]:before:w-1.5 [&_li]:before:shrink-0 [&_li]:before:rounded-full [&_li]:before:bg-primary [&_li]:before:content-[''] [&_ul]:space-y-2">
                                    {section.body}
                                </div>
                            </li>
                        ))}
                    </ol>

                    {children}
                </main>

                <PublicFooter />
            </div>
        </>
    );
}
