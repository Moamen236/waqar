<?php

namespace App\Actions\Returns;

use App\Enums\ReturnStage;
use App\Enums\ReturnStatus;
use App\Models\Employee;
use App\Models\OrderReturn;
use RuntimeException;

class ApproveReturnAction
{
    public function execute(OrderReturn $return, Employee $employee): OrderReturn
    {
        if ($return->status !== ReturnStatus::Requested) {
            throw new RuntimeException(__('Only a requested return can be approved.'));
        }

        // Q6: consent must exist before approval, for post_delivery
        // returns specifically — at_delivery returns never carry this fee.
        if ($return->stage === ReturnStage::PostDelivery && $return->customer_accepted_return_shipping_fee_at === null) {
            throw new RuntimeException(__('The customer must accept the return shipping fee before this return can be approved.'));
        }

        $return->update(['status' => ReturnStatus::Approved]);

        return $return->fresh();
    }
}
