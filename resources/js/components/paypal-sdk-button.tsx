import { createElement, useEffect, useRef, useState } from 'react';

/**
 * The parts of PayPal's JS SDK v6 this button uses.
 *
 * @see https://github.com/paypal-examples/v6-web-sdk-sample-integration
 */
interface PaypalSession {
    start(
        options: { presentationMode: 'auto' | 'popup' | 'modal' | 'redirect' },
        order: Promise<{ orderId: string }>,
    ): Promise<void>;
}

interface PaypalSdkInstance {
    findEligibleMethods(options: {
        currencyCode: string;
    }): Promise<{ isEligible(method: string): boolean }>;
    createPayPalOneTimePaymentSession(options: {
        onApprove: (data: { orderId: string }) => Promise<void> | void;
        onCancel: (data: { orderId?: string }) => void;
        onError: (error: Error) => void;
    }): PaypalSession;
}

declare global {
    interface Window {
        paypal?: {
            createInstance(options: {
                clientId: string;
                components: string[];
                pageType: string;
            }): Promise<PaypalSdkInstance>;
        };
    }
}

const SCRIPT_URLS = {
    sandbox: 'https://www.sandbox.paypal.com/web-sdk/v6/core',
    live: 'https://www.paypal.com/web-sdk/v6/core',
} as const;

/** One load of the SDK script per page, shared by every button. */
const scripts = new Map<string, Promise<void>>();

function loadSdk(environment: 'sandbox' | 'live'): Promise<void> {
    const src = SCRIPT_URLS[environment];

    if (!scripts.has(src)) {
        scripts.set(
            src,
            new Promise((resolve, reject) => {
                const script = document.createElement('script');
                script.src = src;
                script.async = true;
                script.onload = () => resolve();
                script.onerror = () => {
                    scripts.delete(src);
                    reject(new Error('PayPal could not be loaded.'));
                };
                document.head.appendChild(script);
            }),
        );
    }

    return scripts.get(src)!;
}

/**
 * PayPal's own button, from its JS SDK v6: the payer approves in PayPal's
 * window without leaving the page. The order is created and captured on our
 * server. Until the SDK is ready (or if it can't load, or PayPal isn't
 * available for the currency), `fallback` is shown instead.
 */
export function PaypalSdkButton({
    clientId,
    environment,
    currency,
    disabled = false,
    createOrder,
    onApprove,
    onCancel,
    onError,
    onSettled,
    fallback,
}: {
    clientId: string;
    environment: 'sandbox' | 'live';
    currency: string;
    disabled?: boolean;
    /** Create the order on our server; resolves to PayPal's order id. */
    createOrder: () => Promise<string>;
    onApprove: (orderId: string) => Promise<void> | void;
    onCancel?: () => void;
    onError?: (error: Error) => void;
    /**
     * PayPal's session ended without approval: closed, blocked or failed.
     * Called after onCancel or onError, and when the session returns
     * without calling either (e.g. its window was blocked).
     */
    onSettled?: () => void;
    fallback: React.ReactNode;
}) {
    const button = useRef<HTMLElement | null>(null);
    const session = useRef<PaypalSession | null>(null);
    const callbacks = useRef({
        createOrder,
        onApprove,
        onCancel,
        onError,
        onSettled,
    });
    // Ready for this client, environment and currency (a change waits for a new session).
    const sessionKey = `${environment}|${clientId}|${currency}`;
    const [readyFor, setReadyFor] = useState<string | null>(null);
    const ready = readyFor === sessionKey;

    useEffect(() => {
        callbacks.current = {
            createOrder,
            onApprove,
            onCancel,
            onError,
            onSettled,
        };
    });

    useEffect(() => {
        let cancelled = false;
        session.current = null;

        (async () => {
            try {
                await loadSdk(environment);

                if (!window.paypal) {
                    return;
                }

                const sdk = await window.paypal.createInstance({
                    clientId,
                    components: ['paypal-payments'],
                    pageType: 'checkout',
                });
                const methods = await sdk.findEligibleMethods({
                    currencyCode: currency,
                });

                if (cancelled || !methods.isEligible('paypal')) {
                    return;
                }

                session.current = sdk.createPayPalOneTimePaymentSession({
                    onApprove: ({ orderId }) =>
                        callbacks.current.onApprove(orderId),
                    onCancel: () => callbacks.current.onCancel?.(),
                    onError: (error) => callbacks.current.onError?.(error),
                });
                setReadyFor(sessionKey);
            } catch {
                // The classic PayPal button (the fallback) still works.
            }
        })();

        return () => {
            cancelled = true;
        };
    }, [clientId, environment, currency, sessionKey]);

    useEffect(() => {
        const element = button.current;

        if (!ready || !element) {
            return;
        }

        const start = () => {
            if (disabled || !session.current) {
                return;
            }

            // Start the order without awaiting it, so PayPal's window opens
            // straight from the click (browsers block it otherwise).
            const order = callbacks.current
                .createOrder()
                .then((orderId) => ({ orderId }));

            session.current
                .start({ presentationMode: 'auto' }, order)
                .catch((error: Error) => callbacks.current.onError?.(error))
                .finally(() => callbacks.current.onSettled?.());
        };

        element.addEventListener('click', start);

        return () => element.removeEventListener('click', start);
    }, [ready, disabled]);

    if (!ready) {
        return <>{fallback}</>;
    }

    return (
        <div
            className={
                disabled ? 'pointer-events-none opacity-50' : 'cursor-pointer'
            }
            aria-disabled={disabled}
            data-test="paypal-sdk-button"
        >
            {createElement('paypal-button', {
                ref: button,
                type: 'pay',
                class: 'block w-full',
            })}
        </div>
    );
}
