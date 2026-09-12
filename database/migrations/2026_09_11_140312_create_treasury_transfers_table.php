<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row generates a matching transfer_out/transfer_in pair in
        // treasury_transactions, keeping transfers balanced.
        Schema::create('treasury_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_treasury_id')->constrained('treasuries');
            $table->foreignId('to_treasury_id')->constrained('treasuries');
            $table->decimal('amount', 10, 2);
            $table->string('notes')->nullable();
            $table->foreignId('created_by')->constrained('employees');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('treasury_transfers');
    }
};
