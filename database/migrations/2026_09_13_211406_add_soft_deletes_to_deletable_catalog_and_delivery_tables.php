<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Soft deletes for the three tables the admin can actually delete from and
 * that did not already have them (products, categories, orders, customers,
 * employees, returns and treasuries did, since Phase 1–2).
 *
 * `shipping_rates` needs one more change than the column. It carries
 * `UNIQUE (geo_type, geo_id)` — one rate per place — and a soft-deleted row
 * keeps occupying that pair. Left alone, deleting Cairo's rate would make a
 * new Cairo rate impossible to insert, and since checkout refuses any order
 * to an address with no rate (Phase 5), that would take delivery to a
 * governorate offline with a unique-constraint error as the only clue.
 *
 * Widening the index to include `deleted_at` is not a fix: MySQL treats NULL
 * as distinct in a unique index, so live rows (deleted_at IS NULL) would stop
 * being constrained at all — the opposite of what the index is for. The
 * constraint is therefore left exactly as it is, and the *controller* revives
 * a trashed row instead of inserting a colliding one. See
 * ShippingRateController::store().
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['attribute_values', 'shipping_rates', 'delivery_representative_areas'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->softDeletes();
            });
        }
    }

    public function down(): void
    {
        foreach (['attribute_values', 'shipping_rates', 'delivery_representative_areas'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropSoftDeletes();
            });
        }
    }
};
