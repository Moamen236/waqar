<?php

namespace App\Enums;

// Manual only (Question 5) — no store credit, no card reversal.
enum RefundMethod: string
{
    case BankTransfer = 'bank_transfer';
    case Wallet = 'wallet';
    case Cash = 'cash';
}
