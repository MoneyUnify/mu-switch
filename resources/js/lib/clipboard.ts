import { toast } from 'sonner';

/**
 * Copy text to the clipboard and say so (or that it couldn't be copied).
 */
export async function copyText(text: string, what = 'Link'): Promise<void> {
    try {
        await navigator.clipboard.writeText(text);
        toast.success(`${what} copied.`);
    } catch {
        toast.error('Could not copy.');
    }
}
