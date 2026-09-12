<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            // Customer-facing, seeded at 1001, independent of id (Section
            // 10) — assigned by Order::booted() on creating(), not a DB
            // auto-increment, so it stays under application control.
            $table->unsignedInteger('order_number')->unique();
            $table->foreignId('customer_id')->constrained();
            // Set only when order_source = customer_service (Section 03).
            $table->foreignId('created_by_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('order_source'); // website|customer_service
            // New|Checking|Confirmed|Postponed|Backorder|Cancelled|Assigned|
            // Out for Delivery|Delivered|Partially Returned|Returned (Section 10)
            $table->string('status')->default('New');
            $table->string('customer_status'); // computed/mapped customer-facing status (Section 03)
            $table->string('payment_status')->default('pending'); // pending|collected|not_collected|refunded (Section 09)
            $table->string('delivery_assignment_type')->nullable(); // representative|shipping_company — null until assigned
            $table->foreignId('delivery_representative_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('shipping_company_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('subtotal', 10, 2);
            $table->decimal('discount_amount', 10, 2)->default(0);
            $table->decimal('shipping_amount', 10, 2);
            $table->decimal('total', 10, 2);
            $table->foreignId('coupon_id')->nullable()->constrained()->nullOnDelete();
            // Snapshotted from the chosen address at order time, not a live
            // FK (Section 24, confirmation #10) — an address can be edited
            // or deleted after the order is placed.
            $table->string('shipping_recipient_name');
            $table->string('shipping_phone');
            $table->foreignId('shipping_governorate_id')->constrained('governorates');
            $table->foreignId('shipping_city_id')->constrained('cities');
            $table->foreignId('shipping_district_id')->nullable()->constrained('districts')->nullOnDelete();
            $table->foreignId('shipping_area_id')->constrained('areas');
            $table->string('shipping_address_line');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes(); // orders is one of Section 23's 7 soft-delete models
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
