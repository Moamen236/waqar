<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Full assignment history — like order_status_history is to
        // orders.status (Section 11). orders.delivery_representative_id/
        // shipping_company_id stay denormalized for the current
        // assignment; this table is the trail.
        Schema::create('delivery_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('assignment_type'); // representative|shipping_company
            $table->foreignId('delivery_representative_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('shipping_company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_by')->constrained('employees');
            $table->timestamp('assigned_at');
            $table->string('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_assignments');
    }
};
