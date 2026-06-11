<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payroll_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->integer('expected_seconds')->default(0);
            $table->integer('hubstaff_total_seconds')->default(0);
            $table->integer('justified_idle_seconds')->default(0);
            $table->integer('unjustified_idle_seconds')->default(0);
            $table->integer('justified_absence_seconds')->default(0);
            $table->integer('unjustified_absence_seconds')->default(0);
            $table->integer('payable_seconds')->default(0);
            $table->decimal('hourly_rate', 12, 4)->default(0);
            $table->decimal('base_salary_amount', 12, 2)->default(0);
            $table->decimal('absence_deduction', 12, 2)->default(0);
            $table->decimal('idle_deduction', 12, 2)->default(0);
            $table->decimal('overtime_amount', 12, 2)->default(0);
            $table->decimal('bonuses_amount', 12, 2)->default(0);
            $table->decimal('gross_amount', 12, 2)->default(0);
            $table->decimal('net_amount', 12, 2)->default(0);
            $table->string('status')->default('borrador');
            $table->timestamps();

            $table->unique(['payroll_period_id', 'employee_id'], 'payroll_result_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payroll_results');
    }
};
