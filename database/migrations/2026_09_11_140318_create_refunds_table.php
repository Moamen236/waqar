<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_id')->constrained('returns');
            $table->foreignId('order_id')->constrained(); // denormalized for quick lookup
            $table->decimal('amount', 10, 2); // full refundable amount before deduction
            $table->decimal('return_shipping_fee', 10, 2)->default(0); // deducted per Q6
            $table->decimal('net_amount', 10, 2); // amount - return_shipping_fee
            $table->string('method'); // bank_transfer|wallet — manual, per Q5, no store credit/card reversal
            $table->string('status')->default('pending'); // pending|completed
            $table->string('reference_number')->nullable(); // bank/wallet transfer reference
            $table->foreignId('processed_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
