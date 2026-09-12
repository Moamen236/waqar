<?php

namespace App\Enums;

enum TreasuryTransactionType: string
{
    case Income = 'income';
    case Expense = 'expense';
    case Adjustment = 'adjustment';
    case TransferIn = 'transfer_in';
    case TransferOut = 'transfer_out';
}
