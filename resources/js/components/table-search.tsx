import { router } from '@inertiajs/react';
import { TextField } from '@radix-ui/themes';
import { Search } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';

/**
 * A search box for one paginated table. Submitting reloads only that table's
 * props and returns it to its first page, leaving other tables untouched.
 */
export function TableSearch({
    param,
    pageName,
    only,
    initial,
    placeholder,
}: {
    param: string;
    pageName: string;
    only: string[];
    initial: string | null;
    placeholder: string;
}) {
    const [value, setValue] = useState(initial ?? '');

    const submit = (event: FormEvent) => {
        event.preventDefault();

        const params = new URLSearchParams(window.location.search);
        const term = value.trim();

        if (term) {
            params.set(param, term);
        } else {
            params.delete(param);
        }

        params.delete(pageName);

        router.get(
            `${window.location.pathname}?${params.toString()}`,
            {},
            { only, preserveState: true, preserveScroll: true, replace: true },
        );
    };

    return (
        <form onSubmit={submit} role="search">
            <TextField.Root
                type="search"
                value={value}
                onChange={(e) => setValue(e.target.value)}
                placeholder={placeholder}
                aria-label={placeholder}
                className="w-full sm:w-56"
            >
                <TextField.Slot>
                    <Search className="h-4 w-4" />
                </TextField.Slot>
            </TextField.Root>
        </form>
    );
}
