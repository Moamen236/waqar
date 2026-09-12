<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Table name is singular ("warehouse_inventory", spec Section 24)
        // — Eloquent's default pluralization would say "warehouse_inventories",
        // so WarehouseInventory::$table overrides it explicitly.
        Schema::create('warehouse_inventory', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity')->default(0); // physical stock
            $table->unsignedInteger('reserved_quantity')->default(0);
            $table->timestamps();
            // available = quantity - reserved_quantity, computed at read
            // time (Section 07), not stored.
            $table->unique(['warehouse_id', 'product_variant_id'], 'warehouse_inventory_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_inventory');
    }
};
