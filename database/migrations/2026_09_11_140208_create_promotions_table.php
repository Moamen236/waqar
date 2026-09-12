<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Bundle and Buy X Get Y (spec Section 24, Question 17). See
        // promotion_items (the buy/component side) and promotion_rewards
        // (the BXGY "get" side, empty for bundle promotions).
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->json('name'); // translatable — shown to the customer
            $table->json('description')->nullable(); // translatable, optional
            $table->string('type'); // bundle|buy_x_get_y
            $table->string('discount_type'); // percentage|fixed_amount|fixed_price|free
            $table->decimal('discount_value', 10, 2)->nullable(); // null only when discount_type = free
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->integer('priority')->default(0); // resolves overlaps between promotions
            $table->boolean('stackable_with_coupons')->default(false);
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('usage_limit_per_customer')->nullable();
            $table->unsignedInteger('times_used')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotions');
    }
};
