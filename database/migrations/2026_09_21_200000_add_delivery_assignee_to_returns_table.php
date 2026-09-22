<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who collects the goods coming back.
     *
     * Returns carried no assignee at all: ReceiveReturnAction restocks
     * into the main warehouse whenever someone presses the button, and
     * nothing recorded who was sent to fetch the parcel. On an order that
     * is `delivery_representative_id` / `shipping_company_id`; a return is
     * the same journey in reverse, so it gets the same two columns.
     *
     * Nullable: a customer may drop a return off themselves, and every
     * return filed before this existed has no courier to name.
     */
    public function up(): void
    {
        Schema::table('returns', function (Blueprint $table) {
            $table->string('delivery_assignment_type')->nullable()->after('checking_notes');
            $table->foreignId('delivery_representative_id')
                ->nullable()
                ->after('delivery_assignment_type')
                ->constrained('delivery_representatives')
                ->nullOnDelete();
            $table->foreignId('shipping_company_id')
                ->nullable()
                ->after('delivery_representative_id')
                ->constrained('shipping_companies')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('returns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('delivery_representative_id');
            $table->dropConstrainedForeignId('shipping_company_id');
            $table->dropColumn('delivery_assignment_type');
        });
    }
};
