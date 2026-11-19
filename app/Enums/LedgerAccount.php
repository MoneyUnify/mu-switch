<?php

namespace App\Enums;

/**
 * The side of a ledger entry. Every money event writes balanced entries
 * across these accounts, so each event sums to zero.
 */
enum LedgerAccount: string
{
    /** The merchant's account at the provider: its balance is the money held there. */
    case PROVIDER = 'provider';

    /** Payers, recipients and other outside parties. */
    case COUNTERPARTY = 'counterparty';

    /** Fees paid to providers. */
    case FEES = 'fees';
}
