<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The permission split that broke every `*.manage` permission into
 * view/create/update/delete (spec Section 15) gave these five resources a
 * real delete action for the first time — attributes, collections,
 * promotions, shipping_companies and delivery_representatives previously
 * had no destroy route at all. Soft, matching every other deletable table
 * in this system (see 2026_09_13_211406's own comment on why): an order
 * or delivery assignment placed against one of these keeps referencing a
 * real row, just one hidden from the active lists.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['attributes', 'collections', 'promotions', 'shipping_companies', 'delivery_representatives'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->softDeletes();
            });
        }
    }

    public function down(): void
    {
        foreach (['attributes', 'collections', 'promotions', 'shipping_companies', 'delivery_representatives'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropSoftDeletes();
            });
        }
    }
};
