<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Seeded from Section 12: Wrong Size, Defective Product, Customer
        // Changed Mind, Wrong Product, Product Damaged, Other —
        // admin-extendable without a schema change.
        Schema::create('return_reasons', function (Blueprint $table) {
            $table->id();
            $table->json('name'); // translatable
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_reasons');
    }
};
