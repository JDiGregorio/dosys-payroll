<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_additional_deductions', function (Blueprint $table): void {
            if (! Schema::hasColumn('employee_additional_deductions', 'type')) {
                $table->string('type')->default('other')->after('employee_id');
            }
        });

        Schema::table('payroll_deductions', function (Blueprint $table): void {
            if (! Schema::hasColumn('payroll_deductions', 'employee_additional_deduction_id')) {
                $table->foreignId('employee_additional_deduction_id')
                    ->nullable()
                    ->after('deduction_type_id')
                    ->constrained('employee_additional_deductions')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('payroll_deductions', 'additional_type')) {
                $table->string('additional_type')->nullable()->after('employee_additional_deduction_id');
            }
        });

        Schema::table('payroll_results', function (Blueprint $table): void {
            if (! Schema::hasColumn('payroll_results', 'tier_adjustment_bonus_amount')) {
                $table->decimal('tier_adjustment_bonus_amount', 12, 2)->default(0)->after('adjustment_bonus_amount');
            }

            if (! Schema::hasColumn('payroll_results', 'vacation_bonus_amount')) {
                $table->decimal('vacation_bonus_amount', 12, 2)->default(0)->after('tier_adjustment_bonus_amount');
            }

            if (! Schema::hasColumn('payroll_results', 'tier_adjustment_deduction_amount')) {
                $table->decimal('tier_adjustment_deduction_amount', 12, 2)->default(0)->after('vouchers_amount');
            }

            if (! Schema::hasColumn('payroll_results', 'other_deductions_amount')) {
                $table->decimal('other_deductions_amount', 12, 2)->default(0)->after('tier_adjustment_deduction_amount');
            }
        });

        $additionalTypeId = DB::table('deduction_types')
            ->where('code', 'additional')
            ->value('id');

        if ($additionalTypeId) {
            DB::table('employee_additional_deductions')
                ->orderBy('id')
                ->get()
                ->each(function (object $deduction) use ($additionalTypeId): void {
                    DB::table('payroll_deductions')
                        ->whereNull('employee_additional_deduction_id')
                        ->where('payroll_period_id', $deduction->payroll_period_id)
                        ->where('employee_id', $deduction->employee_id)
                        ->where('deduction_type_id', $additionalTypeId)
                        ->where('amount', $deduction->amount)
                        ->where('description', $deduction->description)
                        ->limit(1)
                        ->update([
                            'employee_additional_deduction_id' => $deduction->id,
                            'additional_type' => $deduction->type ?: 'other',
                        ]);
                });
        }
    }

    public function down(): void
    {
        Schema::table('payroll_results', function (Blueprint $table): void {
            $table->dropColumn([
                'tier_adjustment_bonus_amount',
                'vacation_bonus_amount',
                'tier_adjustment_deduction_amount',
                'other_deductions_amount',
            ]);
        });

        Schema::table('payroll_deductions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('employee_additional_deduction_id');
            $table->dropColumn('additional_type');
        });

        Schema::table('employee_additional_deductions', function (Blueprint $table): void {
            $table->dropColumn('type');
        });
    }
};
