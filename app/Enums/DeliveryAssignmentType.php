<?php

namespace App\Enums;

enum DeliveryAssignmentType: string
{
    case Representative = 'representative';
    case ShippingCompany = 'shipping_company';
}
