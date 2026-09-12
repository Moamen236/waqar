<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Table is "returns" (spec Section 24) — the Eloquent model is
        // named OrderReturn since `Return` is a reserved PHP keyword;
        // OrderReturn::$table points it at "returns" explicitly.
        // Unifies the two return workflows (at_delivery / post_delivery,
        // Section 12) under one stage field.
        Schema::create('returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained();
            $table->foreignId('customer_id')->constrained();
            $table->string('stage'); // at_delivery|post_delivery
            // requested -> approved/rejected -> received -> inspected ->
            // refunded -> completed
            $table->string('status')->default('requested');
            $table->foreignId('reason_id')->constrained('return_reasons');
            // Only apply to post_delivery returns (Q6) — null for
            // at_delivery, where the item never left the warehouse.
            $table->decimal('return_shipping_fee', 10, 2)->nullable();
            $table->timestamp('customer_accepted_return_shipping_fee_at')->nullable();
            $table->text('customer_notes')->nullable();
            $table->timestamps();
            $table->softDeletes(); // "returns" is one of Section 23's 7 soft-delete models
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('returns');
    }
};
