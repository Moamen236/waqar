<?php

namespace App\Enums;

enum OrderSource: string
{
    case Website = 'website';
    case CustomerService = 'customer_service';
}
