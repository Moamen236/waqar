<?php

namespace App\Models;

use App\Enums\InventoryMovementType;
use App\Models\Concerns\RecordsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property InventoryMovementType $type
 * @property-read Warehouse|null $warehouse
 * @property-read ProductVariant $productVariant
 * @property-read Employee|null $createdBy
 */
class InventoryMovement extends Model
{
    use RecordsActivity;

    protected function activityLogName(): string
    {
        return 'inventory';
    }

    /**
     * @return list<string>
     */
    protected function activityLogAttributes(): array
    {
        return [
            'warehouse_id',
            'product_variant_id',
            'type',
            'quantity',
            'reference_type',
            'reference_id',
            'notes',
        ];
    }

    protected $fillable = [
        'warehouse_id',
        'product_variant_id',
        'type',
        'quantity',
        'reference_type',
        'reference_id',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return ['type' => InventoryMovementType::class];
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }

    public function activitySubjectLabel(): string
    {
        return $this->productVariant->sku.' '.($this->quantity > 0 ? '+' : '').$this->quantity;
    }
}
