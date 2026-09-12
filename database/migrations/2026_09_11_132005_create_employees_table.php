<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('full_name');
            $table->string('email')->unique();
            $table->string('phone');
            $table->string('password');
            $table->string('residence_address');
            $table->string('national_id_number');
            $table->boolean('is_active')->default(true);
            // Customer Service Team Leader hierarchy (spec Section 15,
            // Question 16). Null for every role except Customer Service;
            // null on a Team Leader's own record too — leaders aren't
            // nested under other leaders.
            $table->foreignId('team_leader_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
