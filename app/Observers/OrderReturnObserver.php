<?php

namespace App\Observers;

use App\Models\OrderReturn;
use App\Notifications\Orders\ReturnRequestedNotification;
use Illuminate\Support\Facades\DB;

/**
 * "Return requested" (Section 23's event list, Section 12's flow). Same
 * afterCommit reasoning as OrderObserver — RequestReturnAction creates
 * the return and its items inside one transaction.
 */
class OrderReturnObserver
{
    public function created(OrderReturn $return): void
    {
        DB::afterCommit(function () use ($return) {
            $return->customer?->notify(new ReturnRequestedNotification($return));
        });
    }
}
