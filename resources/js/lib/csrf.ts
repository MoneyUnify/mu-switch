/**
 * The page's CSRF token, for JSON requests made with fetch.
 */
export function csrfToken(): string {
    return (
        (
            document.querySelector(
                'meta[name="csrf-token"]',
            ) as HTMLMetaElement | null
        )?.content ?? ''
    );
}
