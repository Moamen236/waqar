<?php

namespace App\Enums;

enum ReturnStage: string
{
    case AtDelivery = 'at_delivery';
    case PostDelivery = 'post_delivery';
}
