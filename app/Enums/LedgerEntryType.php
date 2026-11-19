<?php

namespace App\Enums;

enum LedgerEntryType: string
{
    case COLLECTION = 'collection';
    case PAYOUT = 'payout';
    case REFUND = 'refund';
    case FEE = 'fee';
    case TRANSFER_OUT = 'transfer_out';
    case TRANSFER_IN = 'transfer_in';
    case ADJUSTMENT = 'adjustment';
    case OPENING_BALANCE = 'opening_balance';
}
