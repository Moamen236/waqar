<?php

namespace App\Enums;

enum TreasuryType: string
{
    case Cash = 'cash';
    case Bank = 'bank';
    case Wallet = 'wallet';
}
