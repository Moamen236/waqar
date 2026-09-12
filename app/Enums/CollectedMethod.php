<?php

namespace App\Enums;

enum CollectedMethod: string
{
    case Cash = 'cash';
    case BankTransfer = 'bank_transfer';
    case Wallet = 'wallet';
    case Other = 'other';
}
