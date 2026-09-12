<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Table name is singular ("order_status_history", spec Section
        // 24) — OrderStatusHistory::$table overrides Eloquent's default
        // pluralization.
        Schema::create('order_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('from_status')->nullable(); // null on the very first row
            $table->string('to_status');
            $table->foreignId('changed_by')->nullable()->constrained('employees')->nullOnDelete(); // null = system transition
            $table->string('reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_history');
    }
};
