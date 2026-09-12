<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('session_token')->nullable(); // identifies a guest cart
            $table->timestamps();
            // Exactly one of customer_id/session_token is set — merges on
            // login (Section 08), never both null.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carts');
    }
};
