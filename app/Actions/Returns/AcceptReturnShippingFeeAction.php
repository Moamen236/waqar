<?php

namespace App\Actions\Returns;

use App\Models\Customer;
use App\Models\OrderReturn;
use InvalidArgumentException;

/**
 * The customer's consent step Question 6 requires before a post-delivery
 * return can be approved: shown the return-shipping-fee deduction, must
 * accept it. Separate from ApproveReturnAction on purpose — this is the
 * customer acting, approval is staff acting, and approval checks this
 * already happened.
 */
class AcceptReturnShippingFeeAction
{
    public function execute(OrderReturn $return, Customer $customer, float $returnShippingFee): OrderReturn
    {
        if ($return->customer_id !== $customer->id) {
            throw new InvalidArgumentException('This return does not belong to this customer.');
        }

        $return->update([
            'return_shipping_fee' => $returnShippingFee,
            'customer_accepted_return_shipping_fee_at' => now(),
        ]);

        return $return->fresh();
    }
}
