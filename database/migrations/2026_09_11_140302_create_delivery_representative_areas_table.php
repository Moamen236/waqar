<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Same geo_type/geo_id pattern as shipping_rates — one
        // representative can cover multiple areas at mixed levels.
        Schema::create('delivery_representative_areas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_representative_id')->constrained()->cascadeOnDelete();
            $table->string('geo_type'); // governorate|city|district|area
            $table->unsignedBigInteger('geo_id');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_representative_areas');
    }
};
