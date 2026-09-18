<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A customer Customer Service adds from the dashboard (Section 17) has
     * no email or password at all, the same is_guest arrangement guest
     * checkout already uses — see the is_guest migration for why a record
     * created *for* someone stays unauthenticatable rather than getting a
     * blank/placeholder login. The unique index survives this unchanged:
     * MySQL never treats two NULLs as a duplicate under a unique index, so
     * any number of email-less customers can coexist.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
        });
    }
};
