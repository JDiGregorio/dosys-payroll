<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_periods', function (Blueprint $table): void {
            if (! Schema::hasColumn('payroll_periods', 'fortnight')) {
                $table->string('fortnight')->nullable()->after('ends_at');
            }

            if (! Schema::hasColumn('payroll_periods', 'apply_deductions')) {
                $table->boolean('apply_deductions')->default(false)->after('status');
            }
        });

        Schema::create('payroll_period_deduction_type', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payroll_period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('deduction_type_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['payroll_period_id', 'deduction_type_id'], 'period_deduction_type_unique');
        });

        Schema::table('daily_time_reviews', function (Blueprint $table): void {
            if (! Schema::hasColumn('daily_time_reviews', 'paid_day_off')) {
                $table->boolean('paid_day_off')->default(false)->after('holiday_seconds');
            }
        });

        Schema::table('payroll_results', function (Blueprint $table): void {
            if (! Schema::hasColumn('payroll_results', 'biweekly_salary_amount')) {
                $table->decimal('biweekly_salary_amount', 12, 2)->default(0)->after('monthly_salary');
            }

            if (! Schema::hasColumn('payroll_results', 'referred_bonus_amount')) {
                $table->decimal('referred_bonus_amount', 12, 2)->default(0)->after('extra_bonuses_amount');
            }

            if (! Schema::hasColumn('payroll_results', 'adjustment_bonus_amount')) {
                $table->decimal('adjustment_bonus_amount', 12, 2)->default(0)->after('referred_bonus_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_period_deduction_type');
    }
};
