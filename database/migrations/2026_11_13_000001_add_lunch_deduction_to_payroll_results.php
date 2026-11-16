<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_results', function (Blueprint $table): void {
            $table->decimal('lunch_deduction_amount', 12, 2)->default(0)->after('rap_amount');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_results', function (Blueprint $table): void {
            $table->dropColumn('lunch_deduction_amount');
        });
    }
};
