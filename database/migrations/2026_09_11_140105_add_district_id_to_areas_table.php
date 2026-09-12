<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('areas', function (Blueprint $table) {
            // Nullable — District is optional (spec Section 24,
            // confirmation #12), an Area may attach directly to a City.
            // city_id stays as the fallback, not replaced.
            $table->foreignId('district_id')->nullable()->after('city_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('areas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('district_id');
        });
    }
};
