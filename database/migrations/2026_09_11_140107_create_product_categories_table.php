<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Many-to-many, replaces the single products.category_id the
        // source documents implied (spec Section 05, §20 #21).
        Schema::create('product_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['product_id', 'category_id'], 'product_categories_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_categories');
    }
};
