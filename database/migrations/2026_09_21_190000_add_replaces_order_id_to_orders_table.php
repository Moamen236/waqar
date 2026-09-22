<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A replacement is a new order, linked back to the one it replaces.
     *
     * Not a flag on the original: that order is Delivered — its stock is
     * deducted, its payment collected, its treasury row written. Forcing
     * its total to zero would destroy the record of a real sale.
     *
     * This one nullable column IS the flag. A second boolean would be a
     * value that can disagree with it.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('replaces_order_id')
                ->nullable()
                ->after('customer_id')
                ->constrained('orders')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('replaces_order_id');
        });
    }
};
