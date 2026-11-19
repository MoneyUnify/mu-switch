import { Link } from '@inertiajs/react';
import { LegalPage } from '@/components/legal-page';
import type { LegalSection } from '@/components/legal-page';
import { terms } from '@/routes';

const ISSUES_URL = 'https://github.com/moneyUnify/mu-switch/issues';

const SECTIONS: LegalSection[] = [
    {
        title: 'What we collect',
        body: (
            <ul>
                <li>
                    <span>
                        <strong>Your account:</strong> your name, email address
                        and password (stored only as a hash), and your
                        two-factor settings if you turn them on.
                    </span>
                </li>
                <li>
                    <span>
                        <strong>Payments and transfers:</strong> amounts and
                        currencies, the recipient&apos;s mobile-money number,
                        name and email if given, the payer&apos;s email if
                        given, references, and the answers payment providers
                        send back.
                    </span>
                </li>
                <li>
                    <span>
                        <strong>
                            Card and PayPal details are not collected.
                        </strong>{' '}
                        Payers enter them on PayPal&apos;s own pages, and they
                        never reach MoneyUnify.
                    </span>
                </li>
                <li>
                    <span>
                        <strong>Requests:</strong> a log of API and MCP
                        requests, with IP addresses, and with tokens, keys and
                        secrets removed.
                    </span>
                </li>
                <li>
                    <span>
                        <strong>The Send Money assistant:</strong> the messages
                        you type to it and its replies, so a conversation can
                        continue.
                    </span>
                </li>
            </ul>
        ),
    },
    {
        title: 'How we use it',
        body: (
            <>
                <p>
                    Only to run the service: to make the payments and transfers
                    you ask for, send receipts and notifications, stop duplicate
                    or fraudulent payments, keep the ledger and audit trail, and
                    help you when something goes wrong.
                </p>
                <p>
                    We don&apos;t sell personal data, and we don&apos;t use it
                    for advertising.
                </p>
            </>
        ),
    },
    {
        title: 'Who we share it with',
        body: (
            <>
                <p>Only what each one needs to do its part:</p>
                <ul>
                    <li>
                        <span>
                            <strong>PayPal</strong>, where payers approve and
                            pay. PayPal&apos;s own{' '}
                            <a
                                href="https://www.paypal.com/legalhub/privacy-full"
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                privacy statement
                            </a>{' '}
                            applies there.
                        </span>
                    </li>
                    <li>
                        <span>
                            <strong>Mobile-money and payment providers</strong>,
                            which receive the number, name and amount needed to
                            pay a wallet.
                        </span>
                    </li>
                    <li>
                        <span>
                            <strong>
                                The AI provider set up for the account
                            </strong>
                            , which receives the messages sent to the Send Money
                            assistant.
                        </span>
                    </li>
                    <li>
                        <span>
                            <strong>Our email provider</strong>, to deliver
                            receipts and notifications.
                        </span>
                    </li>
                </ul>
                <p>We also disclose data when the law requires it.</p>
            </>
        ),
    },
    {
        title: 'Cookies',
        body: (
            <>
                <p>
                    We use only the cookies the site needs: one that keeps you
                    signed in, one that protects forms from forgery, and two
                    that remember your preferences (light or dark appearance,
                    and whether the sidebar is open). There are no advertising
                    or tracking cookies.
                </p>
                <p>
                    The home page loads the Buy Me a Coffee button, a
                    third-party script that may set its own cookies.
                </p>
            </>
        ),
    },
    {
        title: 'How long we keep it',
        body: (
            <p>
                Your account is kept until you delete it, which you can do at
                any time from your profile settings. Payment, transfer and
                ledger records are kept for as long as accounting and the law
                require, even after an account is closed.
            </p>
        ),
    },
    {
        title: 'Security',
        body: (
            <p>
                Passwords are hashed, merchant MCP keys are stored only as a
                hash and shown once, tokens and secrets are removed from logs,
                and payers&apos; emails are masked on receipts. No system is
                perfectly secure, so please tell us at once if you find a
                problem.
            </p>
        ),
    },
    {
        title: 'Your rights',
        body: (
            <p>
                You can ask to see, correct or delete the personal data we hold
                about you, and you can delete your account yourself from your
                profile settings. Depending on where you live, you may have more
                rights under data-protection law, including the right to
                complain to your data-protection authority.
            </p>
        ),
    },
    {
        title: 'Deployments run by others',
        body: (
            <p>
                MoneyUnify is open-source software. A business that deploys its
                own copy is responsible for the personal data in that copy, and
                its own privacy policy applies there, not this one. See the{' '}
                <Link href={terms()}>terms</Link> for what deployers are
                responsible for.
            </p>
        ),
    },
    {
        title: 'Changes and contact',
        body: (
            <p>
                If this policy changes, the date at the top changes with it. For
                questions or requests, open an issue on{' '}
                <a href={ISSUES_URL} target="_blank" rel="noopener noreferrer">
                    GitHub
                </a>
                .
            </p>
        ),
    },
];

export default function Privacy() {
    return (
        <LegalPage
            title="Privacy policy"
            updated="19 October 2026"
            intro={
                <p>
                    This policy covers the MoneyUnify Switch website and demo at
                    this address, run by Blessed Jason Mwanza. The public demo
                    runs on PayPal&apos;s and the payment providers&apos;
                    sandboxes, so no real money moves through it.
                </p>
            }
            sections={SECTIONS}
        >
            <p className="mt-16 border-t border-neutral-200 pt-6 text-xs leading-relaxed text-neutral-400 dark:border-neutral-800 dark:text-neutral-500">
                Photos on the home page are from{' '}
                <a
                    href="https://unsplash.com"
                    target="_blank"
                    rel="noopener noreferrer"
                    className="hover:underline"
                >
                    Unsplash
                </a>
                , by{' '}
                <a
                    href="https://unsplash.com/@mkumbwajr"
                    target="_blank"
                    rel="noopener noreferrer"
                    className="hover:underline"
                >
                    Ali Mkumbwa
                </a>{' '}
                (Dar es Salaam, Tanzania) and{' '}
                <a
                    href="https://unsplash.com/@cutinamoment"
                    target="_blank"
                    rel="noopener noreferrer"
                    className="hover:underline"
                >
                    Francis Odeyemi
                </a>{' '}
                (Lagos, Nigeria), used under the{' '}
                <a
                    href="https://unsplash.com/license"
                    target="_blank"
                    rel="noopener noreferrer"
                    className="hover:underline"
                >
                    Unsplash License
                </a>
                .
            </p>
        </LegalPage>
    );
}
