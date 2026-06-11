<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'profile')) {
                $table->enum('profile', ['rrhh', 'supervisor'])->default('rrhh')->after('password');
            }
            if (! Schema::hasColumn('users', 'employee_id')) {
                $table->foreignId('employee_id')->nullable()->after('profile')->constrained()->nullOnDelete();
            }
            if (! Schema::hasColumn('users', 'active')) {
                $table->boolean('active')->default(true)->after('employee_id');
            }
        });

        Schema::table('employees', function (Blueprint $table) {
            $this->addForeignIfMissing($table, 'campaign_id', 'campaign');
            $this->addForeignIfMissing($table, 'team_id', 'team');
            $this->addForeignIfMissing($table, 'department_id', 'department');
            $this->addForeignIfMissing($table, 'work_role_id', 'role');
            $this->addForeignIfMissing($table, 'tier_level_id', 'tier_level');
            $this->addForeignIfMissing($table, 'schedule_type_id', 'schedule_type');
            $this->addForeignIfMissing($table, 'contract_type_id', 'schedule_type_id');
            if (! Schema::hasColumn('employees', 'supervisor_user_id')) {
                $table->foreignId('supervisor_user_id')->nullable()->after('contract_type_id')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('employees', 'schedule_type_name_snapshot')) {
                $table->string('schedule_type_name_snapshot')->nullable()->after('supervisor_user_id');
            }
            if (! Schema::hasColumn('employees', 'calendar_days')) {
                $table->decimal('calendar_days', 8, 2)->default(30)->after('daily_hours');
            }
            if (! Schema::hasColumn('employees', 'monthly_salary')) {
                $table->decimal('monthly_salary', 12, 2)->default(0)->after('calendar_days');
            }
            if (! Schema::hasColumn('employees', 'daily_rate')) {
                $table->decimal('daily_rate', 12, 4)->default(0)->after('monthly_salary');
            }
            if (! Schema::hasColumn('employees', 'overtime_hourly_rate')) {
                $table->decimal('overtime_hourly_rate', 12, 4)->default(0)->after('hourly_rate');
            }
            if (! Schema::hasColumn('employees', 'can_work_overtime')) {
                $table->boolean('can_work_overtime')->default(true)->after('overtime_hourly_rate');
            }
            if (! Schema::hasColumn('employees', 'internet_subsidy_amount')) {
                $table->decimal('internet_subsidy_amount', 12, 2)->default(0)->after('can_work_overtime');
            }
            if (! Schema::hasColumn('employees', 'applies_private_insurance')) {
                $table->boolean('applies_private_insurance')->default(false)->after('internet_subsidy_amount');
            }
            if (! Schema::hasColumn('employees', 'applies_ihss')) {
                $table->boolean('applies_ihss')->default(true)->after('applies_private_insurance');
            }
            if (! Schema::hasColumn('employees', 'applies_isr')) {
                $table->boolean('applies_isr')->default(false)->after('applies_ihss');
            }
            if (! Schema::hasColumn('employees', 'applies_rap')) {
                $table->boolean('applies_rap')->default(false)->after('applies_isr');
            }
        });

        $this->stringStatusColumn('payroll_periods', 'status');
        $this->stringStatusColumn('daily_time_reviews', 'status');
        $this->stringStatusColumn('payroll_bonuses', 'type');
        $this->stringStatusColumn('payroll_bonuses', 'status');
        $this->stringStatusColumn('payroll_results', 'status');

        Schema::table('payroll_periods', function (Blueprint $table) {
            if (! Schema::hasColumn('payroll_periods', 'pto_included_in_total')) {
                $table->boolean('pto_included_in_total')->default(false)->after('notes');
            }
            if (! Schema::hasColumn('payroll_periods', 'holiday_included_in_total')) {
                $table->boolean('holiday_included_in_total')->default(false)->after('pto_included_in_total');
            }
        });

        Schema::table('daily_time_reviews', function (Blueprint $table) {
            if (! Schema::hasColumn('daily_time_reviews', 'pending_idle_seconds')) {
                $table->integer('pending_idle_seconds')->default(0)->after('paid_break_seconds');
            }
            if (! Schema::hasColumn('daily_time_reviews', 'possible_overtime_seconds')) {
                $table->integer('possible_overtime_seconds')->default(0)->after('unjustified_absence_seconds');
            }
            if (! Schema::hasColumn('daily_time_reviews', 'overtime_rate_type_id')) {
                $table->foreignId('overtime_rate_type_id')->nullable()->after('approved_overtime_seconds')->constrained('hourly_rate_types')->nullOnDelete();
            }
            if (! Schema::hasColumn('daily_time_reviews', 'rrhh_comment')) {
                $table->text('rrhh_comment')->nullable()->after('supervisor_comment');
            }
            if (! Schema::hasColumn('daily_time_reviews', 'reviewed_by')) {
                $table->foreignId('reviewed_by')->nullable()->after('rrhh_comment')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('daily_time_reviews', 'approved_by')) {
                $table->foreignId('approved_by')->nullable()->after('reviewed_by')->constrained('users')->nullOnDelete();
            }
        });

        Schema::table('payroll_bonuses', function (Blueprint $table) {
            if (! Schema::hasColumn('payroll_bonuses', 'scope_type')) {
                $table->enum('scope_type', ['employee', 'team', 'campaign'])->default('employee')->after('payroll_period_id');
            }
            if (! Schema::hasColumn('payroll_bonuses', 'team_id')) {
                $table->foreignId('team_id')->nullable()->after('employee_id')->constrained()->nullOnDelete();
            }
            if (! Schema::hasColumn('payroll_bonuses', 'campaign_id')) {
                $table->foreignId('campaign_id')->nullable()->after('team_id')->constrained()->nullOnDelete();
            }
            if (! Schema::hasColumn('payroll_bonuses', 'status')) {
                $table->enum('status', ['propuesto', 'aprobado', 'rechazado'])->default('propuesto')->after('description');
            }
            if (! Schema::hasColumn('payroll_bonuses', 'proposed_by')) {
                $table->foreignId('proposed_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('payroll_bonuses', 'reviewed_by')) {
                $table->foreignId('reviewed_by')->nullable()->after('proposed_by')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('payroll_bonuses', 'review_comment')) {
                $table->text('review_comment')->nullable()->after('reviewed_by');
            }
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE payroll_bonuses MODIFY employee_id BIGINT UNSIGNED NULL');
        }

        Schema::table('payroll_results', function (Blueprint $table) {
            foreach ($this->payrollResultColumns() as $name => $definition) {
                if (! Schema::hasColumn('payroll_results', $name)) {
                    $definition($table);
                }
            }
        });

        DB::table('payroll_periods')->where('status', 'draft')->update(['status' => 'borrador']);
        DB::table('payroll_periods')->where('status', 'review')->update(['status' => 'en_revision']);
        DB::table('payroll_periods')->where('status', 'approved')->update(['status' => 'aprobado']);
        DB::table('daily_time_reviews')->where('status', 'pending')->update(['status' => 'pendiente']);
        DB::table('daily_time_reviews')->where('status', 'reviewed')->update(['status' => 'revisado_supervisor']);
        DB::table('daily_time_reviews')->where('status', 'approved')->update(['status' => 'aprobado_rrhh']);
        DB::table('payroll_results')->where('status', 'draft')->update(['status' => 'borrador']);
        DB::table('payroll_results')->where('status', 'reviewed')->update(['status' => 'en_revision']);
        DB::table('payroll_results')->where('status', 'approved')->update(['status' => 'aprobado']);
    }

    public function down(): void
    {
        //
    }

    private function addForeignIfMissing(Blueprint $table, string $column, string $after): void
    {
        if (! Schema::hasColumn('employees', $column)) {
            $table->foreignId($column)->nullable()->after($after)->constrained()->nullOnDelete();
        }
    }

    private function stringStatusColumn(string $table, string $column): void
    {
        if (DB::getDriverName() === 'mysql' && Schema::hasColumn($table, $column)) {
            DB::statement("ALTER TABLE {$table} MODIFY {$column} VARCHAR(50)");
        }
    }

    private function payrollResultColumns(): array
    {
        return [
            'monthly_salary' => fn (Blueprint $table) => $table->decimal('monthly_salary', 12, 2)->default(0)->after('employee_id'),
            'daily_rate' => fn (Blueprint $table) => $table->decimal('daily_rate', 12, 4)->default(0)->after('monthly_salary'),
            'overtime_hourly_rate' => fn (Blueprint $table) => $table->decimal('overtime_hourly_rate', 12, 4)->default(0)->after('hourly_rate'),
            'worked_days' => fn (Blueprint $table) => $table->decimal('worked_days', 8, 2)->default(0)->after('overtime_hourly_rate'),
            'worked_hours' => fn (Blueprint $table) => $table->decimal('worked_hours', 10, 2)->default(0)->after('worked_days'),
            'worked_salary_amount' => fn (Blueprint $table) => $table->decimal('worked_salary_amount', 12, 2)->default(0)->after('payable_seconds'),
            'extra_bonuses_amount' => fn (Blueprint $table) => $table->decimal('extra_bonuses_amount', 12, 2)->default(0)->after('worked_salary_amount'),
            'overtime_seconds' => fn (Blueprint $table) => $table->integer('overtime_seconds')->default(0)->after('extra_bonuses_amount'),
            'internet_subsidy_amount' => fn (Blueprint $table) => $table->decimal('internet_subsidy_amount', 12, 2)->default(0)->after('overtime_amount'),
            'qa_bonus_amount' => fn (Blueprint $table) => $table->decimal('qa_bonus_amount', 12, 2)->default(0)->after('internet_subsidy_amount'),
            'productivity_bonus_amount' => fn (Blueprint $table) => $table->decimal('productivity_bonus_amount', 12, 2)->default(0)->after('qa_bonus_amount'),
            'time_management_bonus_amount' => fn (Blueprint $table) => $table->decimal('time_management_bonus_amount', 12, 2)->default(0)->after('productivity_bonus_amount'),
            'payroll_compensation_amount' => fn (Blueprint $table) => $table->decimal('payroll_compensation_amount', 12, 2)->default(0)->after('time_management_bonus_amount'),
            'extras_total_amount' => fn (Blueprint $table) => $table->decimal('extras_total_amount', 12, 2)->default(0)->after('payroll_compensation_amount'),
            'private_insurance_amount' => fn (Blueprint $table) => $table->decimal('private_insurance_amount', 12, 2)->default(0)->after('gross_amount'),
            'ihss_amount' => fn (Blueprint $table) => $table->decimal('ihss_amount', 12, 2)->default(0)->after('private_insurance_amount'),
            'isr_amount' => fn (Blueprint $table) => $table->decimal('isr_amount', 12, 2)->default(0)->after('ihss_amount'),
            'rap_amount' => fn (Blueprint $table) => $table->decimal('rap_amount', 12, 2)->default(0)->after('isr_amount'),
            'vouchers_amount' => fn (Blueprint $table) => $table->decimal('vouchers_amount', 12, 2)->default(0)->after('rap_amount'),
            'total_deductions_amount' => fn (Blueprint $table) => $table->decimal('total_deductions_amount', 12, 2)->default(0)->after('vouchers_amount'),
        ];
    }
};
