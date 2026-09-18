<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Warehouse extends Model
{
    protected $fillable = ['name', 'address', 'manager_employee_id', 'phone', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * The warehouse orders default to. Admin order-create no longer lets the
     * operator pick one, so every manually created order reserves against
     * this: the seeded "Main Warehouse" when it exists and is active,
     * otherwise the oldest active warehouse.
     */
    public static function main(): ?self
    {
        return static::query()
            ->where('is_active', true)
            ->orderByRaw("name = 'Main Warehouse' DESC")
            ->orderBy('id')
            ->first();
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_employee_id');
    }

    public function inventory(): HasMany
    {
        return $this->hasMany(WarehouseInventory::class);
    }
}
