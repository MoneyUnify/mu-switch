import { Link } from '@inertiajs/react';
import { LegalPage } from '@/components/legal-page';
import type { LegalSection } from '@/components/legal-page';
import { privacy } from '@/routes';

const REPOSITORY_URL = 'https://github.com/moneyUnify/mu-switch';
const LICENSE_URL = 'https://www.apache.org/licenses/LICENSE-2.0';

const SECTIONS: LegalSection[] = [
    {
        title: 'The licence',
        body: (
            <p>
                MoneyUnify Switch is licensed under the{' '}
                <a href={LICENSE_URL} target="_blank" rel="noopener noreferrer">
                    Apache License 2.0
                </a>
                . You may use, change and share it, including for commercial
                deployments, as long as you follow that licence and these terms.
            </p>
        ),
    },
    {
        title: 'Keep the licence and the author on every deployment',
        body: (
            <>
                <div className="rounded-xl border border-primary/30 bg-primary/5 p-5 text-neutral-800 dark:text-neutral-200">
                    <p className="font-semibold text-neutral-900 dark:text-white">
                        MoneyUnify was created by, and is owned by, Blessed
                        Jason Mwanza. Every deployment, copy, fork and
                        derivative must keep that credit.
                    </p>
                </div>
                <p>Whenever you deploy, copy or share MoneyUnify, you must:</p>
                <ul>
                    <li>
                        <span>
                            Keep the original <code>LICENSE.txt</code> and{' '}
                            <code>NOTICE</code> files with the software, without
                            changing them.
                        </span>
                    </li>
                    <li>
                        <span>
                            Keep every copyright, licence and attribution notice
                            that names Blessed Jason Mwanza as the author and
                            owner, in the source code and in the{' '}
                            <code>NOTICE</code> file.
                        </span>
                    </li>
                    <li>
                        <span>Mark any file you change as changed by you.</span>
                    </li>
                    <li>
                        <span>
                            When you share the software or a product built from
                            it, show the attribution in the <code>NOTICE</code>{' '}
                            file wherever your product normally shows credits or
                            third-party notices, such as an About page or its
                            documentation.
                        </span>
                    </li>
                </ul>
                <p>
                    You may add your own copyright for your own changes, but you
                    may not remove, replace or hide the original author&apos;s.
                </p>
            </>
        ),
    },
    {
        title: 'The MoneyUnify name and logo',
        body: (
            <p>
                The licence covers the code, not the MoneyUnify name or logo.
                You may say that your product is built on or powered by
                MoneyUnify, but you may not present your deployment as the
                official MoneyUnify, or suggest it is endorsed by Blessed Jason
                Mwanza, without written permission.
            </p>
        ),
    },
    {
        title: 'Who is responsible for a deployment',
        body: (
            <>
                <p>
                    MoneyUnify is the engine, not a payment service. It
                    doesn&apos;t hold anyone&apos;s money or onboard
                    anyone&apos;s customers. The business that deploys it does,
                    and is responsible for:
                </p>
                <ul>
                    <li>
                        <span>
                            the licences and authorisations to collect, hold,
                            convert and pay out money in each market;
                        </span>
                    </li>
                    <li>
                        <span>
                            checking its customers and merchants (KYC and KYB),
                            sanctions screening, and anti-money-laundering
                            monitoring and reporting;
                        </span>
                    </li>
                    <li>
                        <span>
                            consumer protection, disputes and its refund policy;
                        </span>
                    </li>
                    <li>
                        <span>
                            protecting its users&apos; personal data, under its
                            own privacy policy;
                        </span>
                    </li>
                    <li>
                        <span>
                            its agreements with PayPal and each payment
                            provider, and following their terms.
                        </span>
                    </li>
                </ul>
            </>
        ),
    },
    {
        title: 'Using this demo',
        body: (
            <p>
                The demo at this address runs on PayPal&apos;s and the payment
                providers&apos; sandboxes, so no real money moves. Don&apos;t
                use it for real payments, don&apos;t enter real card or bank
                details, and don&apos;t try to disrupt it or reach other
                people&apos;s data. Demo accounts and data may be reset at any
                time.
            </p>
        ),
    },
    {
        title: 'Third-party services',
        body: (
            <p>
                Payments go through PayPal and the payment providers a
                deployment connects, and the Send Money assistant uses the AI
                provider set up for the account. Their own terms apply to what
                happens on their services.
            </p>
        ),
    },
    {
        title: 'No warranty',
        body: (
            <p>
                As the Apache License 2.0 says, MoneyUnify is provided &ldquo;as
                is&rdquo;, without warranties of any kind. To the extent the law
                allows, the author is not liable for any loss arising from using
                or deploying it. Test every deployment thoroughly before it
                handles real money.
            </p>
        ),
    },
    {
        title: 'Changes and contact',
        body: (
            <p>
                If these terms change, the date at the top changes with them.
                For questions, or to ask permission to use the name or logo,
                open an issue on{' '}
                <a
                    href={`${REPOSITORY_URL}/issues`}
                    target="_blank"
                    rel="noopener noreferrer"
                >
                    GitHub
                </a>
                . How we handle personal data is in the{' '}
                <Link href={privacy()}>privacy policy</Link>.
            </p>
        ),
    },
];

export default function Terms() {
    return (
        <LegalPage
            title="Terms and conditions"
            updated="19 October 2026"
            intro={
                <p>
                    MoneyUnify Switch is open-source software created by Blessed
                    Jason Mwanza. These terms cover using this website and demo,
                    and deploying the software yourself.
                </p>
            }
            sections={SECTIONS}
        />
    );
}
