<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('areas', function (Blueprint $table) {
            $table->id();
            // Phase 1 base hierarchy stops at City (spec Section 11: "the
            // original E-Commerce document's hierarchy stopped at Area,"
            // under City). Phase 2 introduces `districts` between City and
            // Area and adds a nullable `district_id` here per Section 24 /
            // Question 12 (District is optional, confirmed) — that
            // migration decides then whether city_id stays as a fallback
            // or areas resolve through district_id only.
            $table->foreignId('city_id')->constrained()->cascadeOnDelete();
            $table->json('name'); // translatable (ar/en)
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('areas');
    }
};
