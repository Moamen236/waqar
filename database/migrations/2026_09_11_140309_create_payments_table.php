<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained();
            $table->string('method')->default('cod'); // always cod (Section 09)
            $table->string('status')->default('pending'); // pending|collected|not_collected|refunded
            // Set only when status = collected (§20 #5).
            $table->string('collection_type')->nullable(); // full|partial
            $table->string('collected_method')->nullable(); // cash|bank_transfer|wallet|other
            $table->decimal('amount', 10, 2);
            $table->decimal('collected_amount', 10, 2)->nullable();
            $table->timestamp('collected_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
