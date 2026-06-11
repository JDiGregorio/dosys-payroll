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
        Schema::create('daily_time_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('date')->index();
            $table->integer('expected_seconds')->default(0);
            $table->integer('hubstaff_total_seconds')->default(0);
            $table->integer('hubstaff_regular_seconds')->default(0);
            $table->integer('hubstaff_idle_seconds')->default(0);
            $table->integer('pto_seconds')->default(0);
            $table->integer('holiday_seconds')->default(0);
            $table->integer('paid_break_seconds')->default(0);
            $table->integer('justified_idle_seconds')->default(0);
            $table->integer('unjustified_idle_seconds')->default(0);
            $table->integer('justified_absence_seconds')->default(0);
            $table->integer('unjustified_absence_seconds')->default(0);
            $table->integer('approved_overtime_seconds')->default(0);
            $table->integer('payable_seconds')->default(0);
            $table->integer('difference_seconds')->default(0);
            $table->string('status')->default('pendiente');
            $table->text('supervisor_comment')->nullable();
            $table->timestamps();

            $table->unique(['payroll_period_id', 'employee_id', 'date'], 'daily_review_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_time_reviews');
    }
};
