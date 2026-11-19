<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\Merchant;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

/**
 * MoneyUnify for merchants: a business its operator collects for builds its
 * own apps on it, or connects an AI assistant, with its own access key.
 */
#[Name('MoneyUnify Merchant')]
#[Version('1.0.0')]
#[Instructions(<<<'TEXT'
You act for one merchant on MoneyUnify. Its customers pay with PayPal (or by card through PayPal) from anywhere, and the merchant is settled in its own currency to its mobile-money wallet, less its operator's commission.

- merchant_profile: the merchant's account.
- quote_payment: what a customer pays for an amount, and what the merchant receives. Creates nothing.
- create_payment: starts a payment and returns checkout_url to send the customer to, and receipt_url.
- list_payments and check_payment: payments and their settlement.
- list_pay_links, create_pay_link, set_pay_link_active, delete_pay_link: saved pay links.

You can only reach this merchant's own payments and links. Confirm amounts with the user before create_payment, and pass an idempotency_key so a retry never charges twice. Refunds are handled by the merchant's operator.
TEXT)]
class MerchantServer extends Server
{
    protected array $tools = [
        Merchant\MerchantProfile::class,
        Merchant\QuotePayment::class,
        Merchant\CreatePayment::class,
        Merchant\ListPayments::class,
        Merchant\CheckPayment::class,
        Merchant\ListPayLinks::class,
        Merchant\CreatePayLink::class,
        Merchant\SetPayLinkActive::class,
        Merchant\DeletePayLink::class,
    ];
}
