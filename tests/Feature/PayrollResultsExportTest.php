<?php

namespace Tests\Feature;

use App\Exports\PayrollResultsExport;
use App\Models\Campaign;
use App\Models\DeductionType;
use App\Models\Employee;
use App\Models\PayrollBonus;
use App\Models\PayrollDeduction;
use App\Models\PayrollPeriod;
use App\Models\PayrollResult;
use App\Models\ScheduleType;
use App\Models\Team;
use App\Models\TierLevel;
use App\Models\WorkRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollResultsExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_uses_requested_columns_and_dynamic_bonus_and_deduction_columns(): void
    {
        $period = PayrollPeriod::query()->create([
            'name' => 'May 2026',
            'starts_at' => '2026-05-01',
            'ends_at' => '2026-05-15',
        ]);
        $campaign = Campaign::query()->create(['name' => 'AUTO FINANCE']);
        $team = Team::query()->create(['name' => 'BDC', 'campaign_id' => $campaign->id]);
        $role = WorkRole::query()->create(['name' => 'Agent']);
        $tier = TierLevel::query()->create(['name' => 'Tier 2']);
        $employee = Employee::query()->create([
            'dni' => '0801-2000-00001',
            'bank_account_number' => '100200300',
            'name' => 'Ailen',
            'campaign_id' => $campaign->id,
            'team_id' => $team->id,
            'work_role_id' => $role->id,
            'tier_level_id' => $tier->id,
        ]);
        $result = PayrollResult::query()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'monthly_salary' => 2400,
            'biweekly_salary_amount' => 1200,
            'daily_rate' => 80,
            'hourly_rate' => 10,
            'overtime_hourly_rate' => 12.5,
            'worked_days' => 15,
            'worked_salary_amount' => 1200,
            'extra_bonuses_amount' => 25,
            'overtime_amount' => 62.5,
            'qa_bonus_amount' => 20,
            'gross_amount' => 1307.5,
            'total_deductions_amount' => 100,
            'net_amount' => 1207.5,
        ]);
        PayrollBonus::query()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'scope_type' => 'employee',
            'type' => 'qa',
            'amount' => 20,
            'status' => 'aprobado',
        ]);
        PayrollBonus::query()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'scope_type' => 'employee',
            'type' => 'manual',
            'amount' => 25,
            'status' => 'aprobado',
        ]);
        $deductionType = DeductionType::query()->create([
            'name' => 'IHSS',
            'code' => 'ihss',
            'calculation_type' => 'fixed',
        ]);
        PayrollDeduction::query()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'deduction_type_id' => $deductionType->id,
            'amount' => 100,
            'status' => 'aprobado',
        ]);

        $export = new PayrollResultsExport($period);
        $headings = $export->headings();
        $values = $export->map($result->fresh('employee'));

        $this->assertSame([
            'Referencia - ID',
            'No. Cuenta',
            'Nombre empleado',
            'Campaña',
            'Equipo',
            'Posición',
            'Salario mensual',
            'Salario quincenal',
            'Tier level',
            'Pago por día',
            'Hora',
            'Hora extra',
            'Días trabajados',
            'Salario',
            'Bonos extras',
            'Horas extras',
            'Bono QA',
            'Total devengado',
            'IHSS',
            'Ajuste Cambio de Tier',
            'Otras deducciones',
            'Total deducciones',
            'Total a pagar',
        ], $headings);
        $this->assertCount(count($headings), $values);
        $this->assertSame('0801-2000-00001', $values[0]);
        $this->assertSame('100200300', $values[1]);
        $this->assertEquals(20, $values[16]);
        $this->assertEquals(100, $values[18]);
    }

    public function test_export_displays_rotating_payroll_days_as_full_biweekly_period_without_changing_calculation(): void
    {
        $period = PayrollPeriod::query()->create([
            'name' => 'Jun 2026',
            'starts_at' => '2026-06-11',
            'ends_at' => '2026-06-25',
        ]);
        $schedule = ScheduleType::query()->create([
            'name' => 'Rotativa',
            'code' => 'rotativa',
            'active' => true,
        ]);
        $employee = Employee::query()->create([
            'name' => 'Rotativo Test',
            'schedule_type_id' => $schedule->id,
        ]);
        $result = PayrollResult::query()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'salary_calculation_method' => 'semi_monthly_fixed_with_deductions',
            'worked_days' => 8,
            'worked_salary_amount' => 7100,
            'gross_amount' => 7100,
            'net_amount' => 7100,
        ]);

        $values = (new PayrollResultsExport($period))->map($result->fresh('employee.scheduleType'));

        $this->assertSame(15.0, $values[12]);
        $this->assertSame('8.00', (string) $result->fresh()->worked_days);
    }

    public function test_export_reflects_lost_time_in_displayed_biweekly_days(): void
    {
        $period = PayrollPeriod::query()->create([
            'name' => 'Jun 2026',
            'starts_at' => '2026-06-11',
            'ends_at' => '2026-06-25',
        ]);
        $schedule = ScheduleType::query()->create([
            'name' => 'Rotativa',
            'code' => 'rotativa',
            'active' => true,
        ]);
        $employee = Employee::query()->create([
            'name' => 'Rotativo con perdida',
            'schedule_type_id' => $schedule->id,
        ]);
        $result = PayrollResult::query()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'salary_calculation_method' => 'semi_monthly_fixed_with_deductions',
            'daily_rate' => 500,
            'worked_days' => 8,
            'lost_time_amount' => 250,
            'worked_salary_amount' => 6850,
            'gross_amount' => 6850,
            'net_amount' => 6850,
        ]);

        $values = (new PayrollResultsExport($period))->map($result->fresh('employee.scheduleType'));

        $this->assertSame(14.5, $values[12]);
        $this->assertSame('8.00', (string) $result->fresh()->worked_days);
    }
}
