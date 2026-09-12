<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('sku')->unique();
            $table->string('barcode')->nullable();
            // Optional overrides of the product's own price — null falls
            // back to products.price/sale_price/cost_price.
            $table->decimal('price', 10, 2)->nullable();
            $table->decimal('sale_price', 10, 2)->nullable();
            $table->decimal('cost_price', 10, 2)->nullable();
            // Customer body-weight sizing range (spec Section 06) — not
            // shipping weight. Renamed from the source docs' "weight_min/
            // weight_max" to match Section 06's own resolution text.
            $table->decimal('size_guide_weight_min', 5, 2)->nullable();
            $table->decimal('size_guide_weight_max', 5, 2)->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
