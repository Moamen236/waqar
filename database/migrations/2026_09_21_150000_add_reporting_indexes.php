<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the Reports module (REPORTS-MODULE-SPEC.md Section H.1).
 *
 * Index-only: no column is added, removed or changed, and no row is
 * written. Every report is functionally correct without this migration
 * and unusably slow with a real order book, because nothing outside the
 * FK and unique constraints was indexed before now.
 *
 * Composite order is equality-column first, range-column last, which is
 * what lets MySQL seek rather than scan: every report filters a period
 * *within* a status, a warehouse, a type or an actor, never a period on
 * its own. That is also why `created_at` gets a lone index on only the
 * two tables (orders, activity_log) where a bare date sweep is a real
 * access path.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->index(['status', 'created_at'], 'orders_status_created_index');
            $table->index(['order_source', 'created_at'], 'orders_source_created_index');
            $table->index(['payment_status', 'created_at'], 'orders_payment_created_index');
            $table->index(['created_by_employee_id', 'created_at'], 'orders_creator_created_index');
            $table->index(['shipping_governorate_id', 'created_at'], 'orders_gov_created_index');
            $table->index('created_at', 'orders_created_index');
        });

        // The lifecycle source: ORD-02/03/04, EMP-03, and the "delivered
        // on" date basis every sales report offers. `orders` has no
        // delivered_at column by design — this table is that column.
        Schema::table('order_status_history', function (Blueprint $table) {
            $table->index(['order_id', 'created_at'], 'osh_order_created_index');
            $table->index(['to_status', 'created_at'], 'osh_status_created_index');
            $table->index(['changed_by', 'created_at'], 'osh_actor_created_index');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->index(['product_variant_id', 'order_id'], 'order_items_variant_order_index');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->index(['status', 'collected_at'], 'payments_status_collected_index');
            $table->index('collected_at', 'payments_collected_index');
        });

        // The largest table long-term, and the one carrying warehouse
        // attribution for every order (orders has no warehouse column —
        // the reservation movement is how a report finds it).
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->index(['warehouse_id', 'product_variant_id', 'created_at'], 'im_wh_variant_created_index');
            $table->index(['type', 'created_at'], 'im_type_created_index');
            $table->index(['created_by', 'created_at'], 'im_actor_created_index');
            $table->index(['reference_type', 'reference_id'], 'im_reference_index');
        });

        Schema::table('treasury_transactions', function (Blueprint $table) {
            $table->index(['treasury_id', 'created_at'], 'tt_treasury_created_index');
            $table->index(['type', 'created_at'], 'tt_type_created_index');
            $table->index(['created_by', 'created_at'], 'tt_actor_created_index');
            // FIN-02 reads the collecting accountant through this: the
            // treasury transaction referencing the Payment is the only
            // record of who banked the cash.
            $table->index(['reference_type', 'reference_id'], 'tt_reference_index');
        });

        Schema::table('returns', function (Blueprint $table) {
            $table->index(['status', 'created_at'], 'returns_status_created_index');
            $table->index(['stage', 'created_at'], 'returns_stage_created_index');
        });

        Schema::table('return_items', function (Blueprint $table) {
            $table->index('product_variant_id', 'return_items_variant_index');
        });

        Schema::table('refunds', function (Blueprint $table) {
            $table->index(['status', 'processed_at'], 'refunds_status_processed_index');
        });

        // Spatie indexes log_name alone and morphs subject_type/subject_id.
        // Neither helps the two questions the audit reports actually ask:
        // "what did this employee do" and "what happened in this window".
        Schema::table('activity_log', function (Blueprint $table) {
            $table->index(['causer_type', 'causer_id', 'created_at'], 'activity_causer_created_index');
            $table->index(['log_name', 'event', 'created_at'], 'activity_log_event_created_index');
            $table->index('created_at', 'activity_created_index');
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->index(['expense_date', 'expense_category_id'], 'expenses_date_category_index');
            $table->index(['created_by', 'expense_date'], 'expenses_actor_date_index');
        });

        Schema::table('delivery_assignments', function (Blueprint $table) {
            $table->index('assigned_at', 'da_assigned_index');
            $table->index(['assigned_by', 'assigned_at'], 'da_actor_assigned_index');
        });

        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->index(['status', 'created_at'], 'st_status_created_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_status_created_index');
            $table->dropIndex('orders_source_created_index');
            $table->dropIndex('orders_payment_created_index');
            $table->dropIndex('orders_creator_created_index');
            $table->dropIndex('orders_gov_created_index');
            $table->dropIndex('orders_created_index');
        });

        Schema::table('order_status_history', function (Blueprint $table) {
            $table->dropIndex('osh_order_created_index');
            $table->dropIndex('osh_status_created_index');
            $table->dropIndex('osh_actor_created_index');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex('order_items_variant_order_index');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_status_collected_index');
            $table->dropIndex('payments_collected_index');
        });

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropIndex('im_wh_variant_created_index');
            $table->dropIndex('im_type_created_index');
            $table->dropIndex('im_actor_created_index');
            $table->dropIndex('im_reference_index');
        });

        Schema::table('treasury_transactions', function (Blueprint $table) {
            $table->dropIndex('tt_treasury_created_index');
            $table->dropIndex('tt_type_created_index');
            $table->dropIndex('tt_actor_created_index');
            $table->dropIndex('tt_reference_index');
        });

        Schema::table('returns', function (Blueprint $table) {
            $table->dropIndex('returns_status_created_index');
            $table->dropIndex('returns_stage_created_index');
        });

        Schema::table('return_items', function (Blueprint $table) {
            $table->dropIndex('return_items_variant_index');
        });

        Schema::table('refunds', function (Blueprint $table) {
            $table->dropIndex('refunds_status_processed_index');
        });

        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropIndex('activity_causer_created_index');
            $table->dropIndex('activity_log_event_created_index');
            $table->dropIndex('activity_created_index');
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->dropIndex('expenses_date_category_index');
            $table->dropIndex('expenses_actor_date_index');
        });

        Schema::table('delivery_assignments', function (Blueprint $table) {
            $table->dropIndex('da_assigned_index');
            $table->dropIndex('da_actor_assigned_index');
        });

        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->dropIndex('st_status_created_index');
        });
    }
};
