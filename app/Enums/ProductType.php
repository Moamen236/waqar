<?php

namespace App\Enums;

// Advertisement vs. Real (spec Section 05) — same product record
// converts between the two, no new product is created.
enum ProductType: string
{
    case Advertisement = 'advertisement';
    case Real = 'real';
}
