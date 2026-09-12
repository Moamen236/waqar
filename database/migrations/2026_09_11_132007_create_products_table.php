<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->json('name'); // translatable (ar/en)
            $table->string('slug')->unique();
            $table->string('sku')->unique();
            $table->json('description')->nullable(); // translatable, optional
            $table->json('short_description')->nullable(); // translatable, optional
            $table->decimal('price', 10, 2);
            $table->decimal('sale_price', 10, 2)->nullable();
            $table->decimal('cost_price', 10, 2)->nullable(); // internal-only, never exposed to storefront
            $table->boolean('status')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_new')->default(false);
            $table->boolean('is_on_sale')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('meta_title')->nullable(); // translatable, optional
            $table->json('meta_description')->nullable(); // translatable, optional
            // product_type / inventory_tracking_enabled (Advertisement vs
            // Real, spec Section 05) and the product_categories pivot are
            // Phase 2 schema extensions — no category link exists yet.
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
