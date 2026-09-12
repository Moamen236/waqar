<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_company_statements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipping_company_id')->constrained();
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedInteger('delivered_orders_count');
            $table->decimal('expected_customer_collection', 10, 2);
            $table->decimal('delivery_fees_owed', 10, 2);
            $table->decimal('return_fees_owed', 10, 2);
            // net_amount_expected = expected_customer_collection -
            // delivery_fees_owed - return_fees_owed
            $table->decimal('net_amount_expected', 10, 2);
            $table->decimal('transferred_amount', 10, 2)->default(0);
            // outstanding_amount = net_amount_expected - transferred_amount,
            // updated as transfers are recorded.
            $table->decimal('outstanding_amount', 10, 2);
            $table->string('status')->default('open'); // open|settled
            $table->foreignId('created_by')->constrained('employees');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_company_statements');
    }
};
