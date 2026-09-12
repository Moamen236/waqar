<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_rates', function (Blueprint $table) {
            $table->id();
            $table->string('geo_type'); // governorate|city|district|area
            $table->unsignedBigInteger('geo_id'); // id of the matching row at that level
            $table->decimal('price', 10, 2);
            $table->decimal('free_shipping_threshold', 10, 2)->nullable(); // null = never free at this level
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            // One active rate per (geo_type, geo_id) — most-specific-match
            // resolution (Section 11) reads this at checkout.
            $table->unique(['geo_type', 'geo_id'], 'shipping_rates_geo_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_rates');
    }
};
