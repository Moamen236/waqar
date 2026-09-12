<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Advertisement vs. Real (spec Section 05) — same product record
            // converts between the two, no new product is created.
            $table->string('product_type')->default('real')->after('sku'); // advertisement|real
            $table->boolean('inventory_tracking_enabled')->default(true)->after('product_type');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['product_type', 'inventory_tracking_enabled']);
        });
    }
};
