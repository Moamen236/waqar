<?php

namespace App\Observers;

use App\Models\Treasury;
use App\Services\Notifications\StaffNotifier;
use Illuminate\Support\Facades\DB;

/**
 * A new treasury account is a structural change to where the business
 * keeps its money, not day-to-day traffic — the Chairman's concern.
 */
class TreasuryObserver
{
    public function __construct(private readonly StaffNotifier $staff) {}

    public function created(Treasury $treasury): void
    {
        $actor = StaffNotifier::actor();

        DB::afterCommit(fn () => $this->staff->toRoles(
            ['Chairman'],
            'treasury_account_opened',
            ['treasury' => $treasury->name],
            'admin.treasury.index',
            actorId: $actor,
        ));
    }
}
