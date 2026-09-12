<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treasury_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('treasury_id')->constrained();
            $table->string('type'); // income|expense|adjustment|transfer_in|transfer_out
            $table->decimal('amount', 10, 2);
            $table->foreignId('created_by')->constrained('employees');
            // Polymorphic — points at the payment, refund,
            // shipping_company_statement, or treasury_transfer that caused
            // it; null for a manual adjustment with no such source.
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('treasury_transactions');
    }
};
