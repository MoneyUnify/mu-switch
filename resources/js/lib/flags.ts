/** A country's flag emoji from its ISO 3166 alpha-2 code (🌐 when unknown). */
export function countryFlag(code: string | null | undefined): string {
    if (!code || !/^[A-Za-z]{2}$/.test(code)) {
        return '🌐';
    }

    return code
        .toUpperCase()
        .replace(/./g, (char) =>
            String.fromCodePoint(127397 + char.charCodeAt(0)),
        );
}
