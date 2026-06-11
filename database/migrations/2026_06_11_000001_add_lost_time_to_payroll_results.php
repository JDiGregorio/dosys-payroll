<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_results', function (Blueprint $table): void {
            $table->integer('regular_lost_seconds')->default(0)->after('unjustified_absence_seconds');
            $table->integer('overtime_lost_seconds')->default(0)->after('regular_lost_seconds');
            $table->integer('lost_time_seconds')->default(0)->after('overtime_lost_seconds');
            $table->decimal('regular_lost_amount', 12, 2)->default(0)->after('worked_salary_amount');
            $table->decimal('overtime_lost_amount', 12, 2)->default(0)->after('regular_lost_amount');
            $table->decimal('lost_time_amount', 12, 2)->default(0)->after('overtime_lost_amount');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_results', function (Blueprint $table): void {
            $table->dropColumn([
                'regular_lost_seconds',
                'overtime_lost_seconds',
                'lost_time_seconds',
                'regular_lost_amount',
                'overtime_lost_amount',
                'lost_time_amount',
            ]);
        });
    }
};
