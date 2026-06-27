import { Theme } from '@radix-ui/themes';
import type { ReactNode } from 'react';
import { useAppearance } from '@/hooks/use-appearance';

/**
 * Wraps the application in Radix Themes, kept in sync with the app's light/dark
 * appearance. Radius is set to "full" and scaling to 90% for the compact,
 * elegant enterprise look.
 */
export function RadixTheme({ children }: { children: ReactNode }) {
    const { resolvedAppearance } = useAppearance();

    return (
        <Theme
            appearance={resolvedAppearance}
            accentColor="blue"
            grayColor="gray"
            radius="full"
            scaling="90%"
            panelBackground="translucent"
            style={{ minHeight: '100%', background: 'transparent' }}
        >
            {children}
        </Theme>
    );
}
