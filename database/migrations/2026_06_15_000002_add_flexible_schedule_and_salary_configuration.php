<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_schedule_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('schedule_type');
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('work_schedule_template_days', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('work_schedule_template_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('day_number');
            $table->integer('expected_seconds')->default(0);
            $table->boolean('is_working_day')->default(true);
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->unique(
                ['work_schedule_template_id', 'day_number'],
                'work_schedule_template_day_unique',
            );
        });

        Schema::table('employees', function (Blueprint $table): void {
            $table->foreignId('work_schedule_template_id')
                ->nullable()
                ->after('schedule_type_id')
                ->constrained()
                ->nullOnDelete();
            $table->string('salary_calculation_method')
                ->default('hourly_actual_hours')
                ->after('work_schedule_template_id');
            $table->decimal('ordinary_weekly_hours', 8, 2)->default(0)->after('weekly_hours');
            $table->decimal('semi_monthly_salary', 12, 2)->default(0)->after('monthly_salary');
            $table->decimal('preassigned_overtime_weekly_hours', 8, 2)->default(0)->after('overtime_hours');
            $table->decimal('preassigned_overtime_period_hours', 8, 2)->default(0)->after('preassigned_overtime_weekly_hours');
            $table->unsignedInteger('rotation_work_days')->nullable()->after('schedule_cycle_anchor_date');
            $table->unsignedInteger('rotation_rest_days')->nullable()->after('rotation_work_days');
            $table->unsignedInteger('paid_lunch_minutes_per_workday')->default(0)->after('rotation_rest_days');
            $table->unsignedInteger('paid_break_minutes_per_workday')->default(0)->after('paid_lunch_minutes_per_workday');
            $table->decimal('hubstaff_expected_hours_per_workday', 8, 2)->nullable()->after('paid_break_minutes_per_workday');
            $table->decimal('paid_hours_per_workday', 8, 2)->nullable()->after('hubstaff_expected_hours_per_workday');
            $table->boolean('lunch_included_in_hubstaff_total')->default(true)->after('paid_hours_per_workday');
            $table->boolean('breaks_included_in_hubstaff_total')->default(true)->after('lunch_included_in_hubstaff_total');
            $table->boolean('salary_values_are_manual')->default(true)->after('breaks_included_in_hubstaff_total');
        });

        Schema::create('employee_schedule_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_schedule_template_id')->nullable()->constrained()->nullOnDelete();
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->date('cycle_start_date')->nullable();
            $table->unsignedInteger('rotation_work_days')->nullable();
            $table->unsignedInteger('rotation_rest_days')->nullable();
            $table->integer('expected_weekly_seconds')->default(0);
            $table->integer('expected_biweekly_seconds')->default(0);
            $table->integer('expected_monthly_seconds')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['employee_id', 'active', 'starts_at', 'ends_at'], 'employee_schedule_assignment_lookup');
        });

        Schema::table('daily_time_reviews', function (Blueprint $table): void {
            $table->boolean('scheduled_work_day')->default(true)->after('date');
            $table->integer('paid_time_not_tracked_seconds')->default(0)->after('paid_break_seconds');
            $table->integer('expected_paid_seconds')->default(0)->after('expected_seconds');
            $table->integer('expected_hubstaff_seconds')->default(0)->after('expected_paid_seconds');
            $table->integer('expected_ordinary_seconds')->default(0)->after('expected_hubstaff_seconds');
            $table->integer('preassigned_overtime_seconds')->default(0)->after('assigned_overtime_seconds');
            $table->integer('additional_overtime_seconds')->default(0)->after('preassigned_overtime_seconds');
            $table->text('overtime_comment')->nullable()->after('rrhh_comment');
        });

        Schema::table('payroll_results', function (Blueprint $table): void {
            $table->string('salary_calculation_method')
                ->default('hourly_actual_hours')
                ->after('employee_id');
            $table->decimal('scheduled_days', 8, 2)->default(0)->after('worked_days');
            $table->integer('expected_hubstaff_seconds')->default(0)->after('expected_seconds');
            $table->integer('expected_paid_seconds')->default(0)->after('expected_hubstaff_seconds');
            $table->integer('preassigned_overtime_seconds')->default(0)->after('overtime_seconds');
            $table->integer('additional_overtime_seconds')->default(0)->after('preassigned_overtime_seconds');
        });

        Schema::table('hubstaff_time_entries', function (Blueprint $table): void {
            $table->boolean('active')->default(true)->after('hubstaff_import_id')->index();
        });

        DB::table('employees')->update([
            'ordinary_weekly_hours' => DB::raw('weekly_hours'),
            'preassigned_overtime_weekly_hours' => DB::raw('overtime_hours'),
            'semi_monthly_salary' => DB::raw('monthly_salary / 2'),
        ]);

        DB::table('daily_time_reviews')->update([
            'scheduled_work_day' => DB::raw('expected_seconds > 0'),
            'expected_ordinary_seconds' => DB::raw('expected_seconds'),
            'preassigned_overtime_seconds' => DB::raw('assigned_overtime_seconds'),
            'expected_paid_seconds' => DB::raw('expected_seconds + assigned_overtime_seconds'),
            'expected_hubstaff_seconds' => DB::raw('expected_seconds + assigned_overtime_seconds'),
        ]);

        DB::table('payroll_results')->update([
            'expected_hubstaff_seconds' => DB::raw('expected_seconds'),
            'expected_paid_seconds' => DB::raw('expected_seconds + overtime_seconds'),
            'preassigned_overtime_seconds' => DB::raw('overtime_seconds'),
        ]);
    }

    public function down(): void
    {
        Schema::table('hubstaff_time_entries', function (Blueprint $table): void {
            $table->dropColumn('active');
        });

        Schema::table('payroll_results', function (Blueprint $table): void {
            $table->dropColumn([
                'salary_calculation_method',
                'scheduled_days',
                'expected_hubstaff_seconds',
                'expected_paid_seconds',
                'preassigned_overtime_seconds',
                'additional_overtime_seconds',
            ]);
        });

        Schema::table('daily_time_reviews', function (Blueprint $table): void {
            $table->dropColumn([
                'scheduled_work_day',
                'paid_time_not_tracked_seconds',
                'expected_paid_seconds',
                'expected_hubstaff_seconds',
                'expected_ordinary_seconds',
                'preassigned_overtime_seconds',
                'additional_overtime_seconds',
                'overtime_comment',
            ]);
        });

        Schema::dropIfExists('employee_schedule_assignments');

        Schema::table('employees', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('work_schedule_template_id');
            $table->dropColumn([
                'salary_calculation_method',
                'ordinary_weekly_hours',
                'semi_monthly_salary',
                'preassigned_overtime_weekly_hours',
                'preassigned_overtime_period_hours',
                'rotation_work_days',
                'rotation_rest_days',
                'paid_lunch_minutes_per_workday',
                'paid_break_minutes_per_workday',
                'hubstaff_expected_hours_per_workday',
                'paid_hours_per_workday',
                'lunch_included_in_hubstaff_total',
                'breaks_included_in_hubstaff_total',
                'salary_values_are_manual',
            ]);
        });

        Schema::dropIfExists('work_schedule_template_days');
        Schema::dropIfExists('work_schedule_templates');
    }
};
