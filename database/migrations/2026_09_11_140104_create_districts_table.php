<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // New level between City and Area (spec Section 11) — the Phase 1
        // areas/addresses migrations deferred their district_id column to
        // this phase since this table didn't exist yet; see the two
        // add_district_id_to_* migrations right after this one.
        Schema::create('districts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('city_id')->constrained()->cascadeOnDelete();
            $table->json('name'); // translatable (ar/en)
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('districts');
    }
};
