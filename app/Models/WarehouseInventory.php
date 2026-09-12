<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarehouseInventory extends Model
{
    // Singular table name (spec Section 24) — Eloquent would otherwise
    // guess "warehouse_inventories".
    protected $table = 'warehouse_inventory';

    protected $fillable = ['warehouse_id', 'product_variant_id', 'quantity', 'reserved_quantity'];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    /**
     * available = quantity - reserved_quantity, computed at read time
     * (Section 07), never stored.
     */
    public function getAvailableAttribute(): int
    {
        return $this->quantity - $this->reserved_quantity;
    }
}
