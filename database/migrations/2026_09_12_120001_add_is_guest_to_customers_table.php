<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Distinguishes a customer record created *for* someone at guest
        // checkout (or by Customer Service taking a phone order) from one
        // the person registered themselves.
        //
        // `orders.customer_id` is not nullable, so a guest order still has
        // to attach to a customers row. Without this flag the only way to
        // handle a guest whose email already exists was to silently attach
        // the order to that account — which let anyone place a COD order
        // that lands in a stranger's order history. With it, a guest
        // record can be reused freely (it's nobody's account), while an
        // email belonging to a real registered account asks that person to
        // sign in instead.
        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('is_guest')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('is_guest');
        });
    }
};
