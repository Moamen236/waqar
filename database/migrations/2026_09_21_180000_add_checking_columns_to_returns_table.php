<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Checking phones the customer before a return is approved: confirms
     * the reason they gave, and for a replacement agrees the swap the way
     * it would a new order.
     *
     * The activity log already records who changed the status and when,
     * but not what was said on the call — and that is the whole output of
     * this step. These three columns are that record, and they are what
     * the Returns screen reads back.
     *
     * Nullable throughout: returns filed before this existed were never
     * called, and a return can still be approved by Warehouse Manager or
     * Accounting without a Checking call (the step is a verification, not
     * a gate — same reasoning as the delivery handover).
     */
    public function up(): void
    {
        Schema::table('returns', function (Blueprint $table) {
            $table->foreignId('checked_by_employee_id')
                ->nullable()
                ->after('customer_notes')
                ->constrained('employees')
                ->nullOnDelete();
            $table->timestamp('checked_at')->nullable()->after('checked_by_employee_id');
            $table->text('checking_notes')->nullable()->after('checked_at');
        });
    }

    public function down(): void
    {
        Schema::table('returns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('checked_by_employee_id');
            $table->dropColumn(['checked_at', 'checking_notes']);
        });
    }
};
