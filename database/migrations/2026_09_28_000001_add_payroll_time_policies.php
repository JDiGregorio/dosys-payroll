<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', fn (Blueprint $table) => $table->boolean('paid_without_tracking')->default(false));
        Schema::table('payroll_periods', fn (Blueprint $table) => $table->boolean('limit_payable_to_schedule')->default(false));
    }

    public function down(): void
    {
        Schema::table('employees', fn (Blueprint $table) => $table->dropColumn('paid_without_tracking'));
        Schema::table('payroll_periods', fn (Blueprint $table) => $table->dropColumn('limit_payable_to_schedule'));
    }
};
