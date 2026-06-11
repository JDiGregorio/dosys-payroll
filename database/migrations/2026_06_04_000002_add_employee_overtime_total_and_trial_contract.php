<?php

use App\Models\ContractType;
use App\Models\Employee;
use App\Models\TierLevel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            if (! Schema::hasColumn('employees', 'monthly_overtime_amount')) {
                $table->decimal('monthly_overtime_amount', 12, 2)->default(0)->after('overtime_hourly_rate');
            }
        });

        $trialContract = ContractType::query()->updateOrCreate(['code' => 'trial_period'], [
            'name' => 'Periodo de prueba',
            'min_weekly_hours' => null,
            'max_weekly_hours' => null,
            'description' => 'Contrato asignado a empleados nuevos en Tier 1.',
            'active' => true,
        ]);

        $tierOneId = TierLevel::query()->where('name', 'Tier 1')->value('id');

        if ($tierOneId) {
            Employee::query()
                ->where('tier_level_id', $tierOneId)
                ->update(['contract_type_id' => $trialContract->id]);
        }

        Employee::query()
            ->where('monthly_overtime_amount', 0)
            ->each(function (Employee $employee): void {
                $hourlyRate = (float) $employee->hourly_rate;
                $overtimeHourlyRate = $hourlyRate * 1.25;

                $employee->update([
                    'overtime_hourly_rate' => round($overtimeHourlyRate, 4),
                    'monthly_overtime_amount' => round((float) $employee->overtime_hours * $overtimeHourlyRate * (30 / 7), 2),
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            if (Schema::hasColumn('employees', 'monthly_overtime_amount')) {
                $table->dropColumn('monthly_overtime_amount');
            }
        });
    }
};
