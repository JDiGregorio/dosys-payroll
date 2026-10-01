<?php

namespace Tests\Feature;

use App\Mail\PayrollVoucherMail;
use App\Models\Employee;
use App\Models\PayrollBonus;
use App\Models\PayrollPeriod;
use App\Models\PayrollResult;
use App\Services\PayrollVoucherBonusPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollVoucherBonusPresenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_september_voucher_separates_lunch_and_holiday_without_changing_amounts(): void
    {
        $period = PayrollPeriod::query()->create([
            'name' => 'Segunda quincena septiembre 2026',
            'starts_at' => '2026-09-11',
            'ends_at' => '2026-09-25',
        ]);
        $employee = Employee::query()->create(['name' => 'Wilman Test', 'email' => 'wilman@example.com']);

        PayrollBonus::withoutEvents(function () use ($period, $employee): void {
            foreach ([
                ['other', 200, 'September 15th - Lunch Bonus', 'aprobado'],
                ['manual', 500, 'Holiday September 15, Independence Day', 'aprobado'],
                ['manual', 99, 'No aplica', 'rechazado'],
            ] as [$type, $amount, $description, $status]) {
                PayrollBonus::query()->create([
                    'payroll_period_id' => $period->id,
                    'employee_id' => $employee->id,
                    'scope_type' => 'employee',
                    'type' => $type,
                    'amount' => $amount,
                    'description' => $description,
                    'status' => $status,
                ]);
            }
        });

        $result = PayrollResult::query()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'extra_bonuses_amount' => 700,
            'extras_total_amount' => 700,
            'net_amount' => 700,
        ]);

        $rows = app(PayrollVoucherBonusPresenter::class)->extraBonusRows($result);
        $this->assertSame([
            ['Bono de almuerzo (15 de septiembre)', 200.0, true],
            ['Pago adicional por feriado trabajado (15 de septiembre)', 500.0, true],
        ], $rows);

        $html = (new PayrollVoucherMail($result->fresh(['employee', 'payrollPeriod'])))->render();
        $this->assertStringContainsString('Bono de almuerzo (15 de septiembre)', $html);
        $this->assertStringContainsString('Pago adicional por feriado trabajado (15 de septiembre)', $html);
        $this->assertStringNotContainsString('Bonos extra cliente', $html);
        $this->assertSame('700.00', $result->fresh()->extra_bonuses_amount);
        $this->assertSame('700.00', $result->fresh()->net_amount);
        $this->assertEquals(799, PayrollBonus::query()->where('payroll_period_id', $period->id)->sum('amount'));
    }

    public function test_alexa_bonus_remains_a_client_bonus_despite_its_description(): void
    {
        $period = PayrollPeriod::query()->create([
            'name' => 'Segunda quincena septiembre 2026',
            'starts_at' => '2026-09-11',
            'ends_at' => '2026-09-25',
        ]);
        $employee = Employee::query()->create(['name' => 'Alexa Valeria Enamorado Ayala']);
        PayrollBonus::withoutEvents(fn () => PayrollBonus::query()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'scope_type' => 'employee',
            'type' => 'other',
            'amount' => 670,
            'description' => 'RRD DAY off bonus',
            'status' => 'aprobado',
        ]));
        $result = PayrollResult::query()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'extra_bonuses_amount' => 670,
        ]);

        $this->assertSame([
            ['Bonos extra cliente', 670.0, true],
        ], app(PayrollVoucherBonusPresenter::class)->extraBonusRows($result));
    }

    public function test_other_periods_keep_the_existing_voucher_grouping(): void
    {
        $period = PayrollPeriod::query()->create([
            'name' => 'Primera quincena octubre 2026',
            'starts_at' => '2026-09-26',
            'ends_at' => '2026-10-10',
        ]);
        $employee = Employee::query()->create(['name' => 'Empleado Test']);
        $result = PayrollResult::query()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'extra_bonuses_amount' => 200,
        ]);

        $this->assertSame([
            ['Bonos extra cliente', 200.0, true],
        ], app(PayrollVoucherBonusPresenter::class)->extraBonusRows($result));
    }
}
