<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Storefront checkout only insists on governorate + street address;
     * city and area are optional (district always was). An order placed
     * without them is priced at the governorate-level shipping rate —
     * ShippingRateResolver simply skips the levels it wasn't given — and
     * the courier works from the street address. A saved address follows
     * the order it was saved from, so `addresses` relaxes the same way.
     * Every screen that shows these already renders a missing level as
     * "—" (see ShippingAddressCard, LabelSheet, the geography report's
     * LEFT JOIN).
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('shipping_city_id')->nullable()->change();
            $table->foreignId('shipping_area_id')->nullable()->change();
        });

        Schema::table('addresses', function (Blueprint $table) {
            $table->foreignId('city_id')->nullable()->change();
            $table->foreignId('area_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('shipping_city_id')->nullable(false)->change();
            $table->foreignId('shipping_area_id')->nullable(false)->change();
        });

        Schema::table('addresses', function (Blueprint $table) {
            $table->foreignId('city_id')->nullable(false)->change();
            $table->foreignId('area_id')->nullable(false)->change();
        });
    }
};
