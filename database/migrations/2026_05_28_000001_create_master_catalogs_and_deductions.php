<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('work_roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('schedule_types', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('code')->unique();
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->decimal('weekly_hours', 8, 2)->default(0);
            $table->decimal('daily_hours', 8, 2)->default(0);
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('contract_types', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('code')->unique();
            $table->decimal('min_weekly_hours', 8, 2)->nullable();
            $table->decimal('max_weekly_hours', 8, 2)->nullable();
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('hourly_rate_types', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('code')->unique();
            $table->decimal('multiplier', 8, 4)->default(1);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('tier_levels', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('position_name')->nullable();
            $table->string('category')->nullable();
            $table->foreignId('schedule_type_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('weekly_hours', 8, 2)->default(0);
            $table->decimal('monthly_salary', 12, 2)->nullable();
            $table->decimal('hourly_rate', 12, 4)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('deduction_types', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('code')->unique();
            $table->enum('calculation_type', ['fixed', 'percentage', 'manual'])->default('manual');
            $table->decimal('default_amount', 12, 2)->default(0);
            $table->decimal('default_percentage', 8, 4)->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('employee_deductions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('deduction_type_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2)->default(0);
            $table->decimal('percentage', 8, 4)->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['employee_id', 'deduction_type_id']);
        });

        Schema::create('payroll_deductions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('deduction_type_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('description')->nullable();
            $table->enum('status', ['borrador', 'aprobado', 'rechazado'])->default('borrador');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_deductions');
        Schema::dropIfExists('employee_deductions');
        Schema::dropIfExists('deduction_types');
        Schema::dropIfExists('tier_levels');
        Schema::dropIfExists('hourly_rate_types');
        Schema::dropIfExists('contract_types');
        Schema::dropIfExists('schedule_types');
        Schema::dropIfExists('work_roles');
        Schema::dropIfExists('departments');
        Schema::dropIfExists('teams');
        Schema::dropIfExists('campaigns');
    }
};
