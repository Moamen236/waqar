<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained();
            $table->foreignId('product_variant_id')->constrained();
            // purchase|sale|return|adjustment|damaged|lost|transfer_in|
            // transfer_out|reservation|release (Section 07)
            $table->string('type');
            $table->integer('quantity'); // signed — positive in, negative out
            // Polymorphic pointer to what caused it (an order, a return, a
            // stock_transfer) — null for e.g. a manual adjustment.
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete(); // null = system-triggered
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};
