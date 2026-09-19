<?php

namespace App\Observers;

use App\Enums\InventoryMovementType;
use App\Models\InventoryMovement;
use App\Services\Inventory\InventoryService;
use App\Services\Notifications\StaffNotifier;
use Illuminate\Support\Facades\DB;

/**
 * Manual stock corrections only — the three movement types a human can
 * produce by hand (InventoryService::MANUAL_ADJUSTMENT_TYPES). Every
 * other type in the ledger is the order flow writing its own reservation
 * or sale, which nobody needs telling about; those are the reason the
 * ledger exists, not exceptions in it.
 *
 * A separate slug per type rather than one with the type as a parameter,
 * so the catalog can translate "written off as damaged" properly instead
 * of splicing an untranslated enum value into a sentence.
 */
class InventoryMovementObserver
{
    /** @var array<string, string> */
    private const TYPES = [
        'adjustment' => 'stock_adjusted',
        'damaged' => 'stock_damaged',
        'lost' => 'stock_lost',
    ];

    public function __construct(private readonly StaffNotifier $staff) {}

    public function created(InventoryMovement $movement): void
    {
        if (! in_array($movement->type, InventoryService::MANUAL_ADJUSTMENT_TYPES, true)) {
            return;
        }

        $roles = ['Warehouse Manager'];

        // Shrinkage is a loss on the books, not just a stock correction.
        if (in_array($movement->type, [InventoryMovementType::Damaged, InventoryMovementType::Lost], true)) {
            $roles[] = 'Chairman';
        }

        $params = [
            'sku' => $movement->productVariant->sku,
            'warehouse' => $movement->warehouse?->name ?? '—',
            'quantity' => abs($movement->quantity),
        ];

        DB::afterCommit(fn () => $this->staff->toRoles(
            $roles,
            self::TYPES[$movement->type->value],
            $params,
            'admin.inventory.index',
            actorId: $movement->created_by,
        ));
    }
}
