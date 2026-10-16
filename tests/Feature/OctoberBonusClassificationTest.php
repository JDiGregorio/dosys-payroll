<?php

namespace Tests\Feature;

use App\Exports\PayrollResultsExport;
use App\Mail\PayrollVoucherMail;
use App\Models\Employee;
use App\Models\PayrollBonus;
use App\Models\PayrollPeriod;
use App\Models\PayrollResult;
use App\Services\OctoberFirstHalfBonusClassificationService;
use App\Services\PayrollVoucherBonusPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OctoberBonusClassificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_reclassification_is_idempotent_and_preserves_payroll_amounts(): void
    {
        $period = PayrollPeriod::query()->create([
            'name' => 'Octubre 2026', 'starts_at' => '2026-09-26', 'ends_at' => '2026-10-10',
        ]);
        $employee = Employee::query()->create(['name' => 'Agente Test']);
        PayrollBonus::withoutEvents(function () use ($period, $employee): void {
            foreach ([
                ['manual', 500, 'Double pay for Morazánico holidays'],
                ['other', 670, 'CLIENT BONUS GIVEN APPROVED RR'],
            ] as [$type, $amount, $description]) {
                PayrollBonus::query()->create([
                    'payroll_period_id' => $period->id, 'employee_id' => $employee->id,
                    'scope_type' => 'employee', 'type' => $type, 'amount' => $amount,
                    'description' => $description, 'status' => 'aprobado',
                ]);
            }
        });
        $result = PayrollResult::query()->create([
            'payroll_period_id' => $period->id, 'employee_id' => $employee->id,
            'extra_bonuses_amount' => 1170, 'gross_amount' => 8000, 'net_amount' => 7600,
        ]);
        $before = $result->fresh()->getAttributes();

        $service = app(OctoberFirstHalfBonusClassificationService::class);
        $this->assertCount(2, $service->preview($period));
        $this->assertSame(2, $service->apply($period));
        $this->assertSame(0, $service->apply($period));
        $this->assertSame($before, $result->fresh()->getAttributes());
        $this->assertSame([
            ['Pago doble Feriado Morazanico', 500.0, true],
            ['Bono Extra Cliente', 670.0, true],
        ], app(PayrollVoucherBonusPresenter::class)->extraBonusRows($result->fresh()));
        $html = (new PayrollVoucherMail($result->fresh(['employee', 'payrollPeriod'])))->render();
        $this->assertStringContainsString('Pago doble Feriado Morazanico', $html);
        $this->assertStringContainsString('Bono Extra Cliente', $html);
        $export = new PayrollResultsExport($period);
        $headings = $export->headings();
        $values = $export->map($result->fresh('employee'));
        $this->assertSame('Bono Extra Cliente', $headings[15]);
        $this->assertSame('Otros bonos (detalle)', $headings[16]);
        $this->assertSame(670.0, $values[15]);
        $this->assertSame('Pago doble Feriado Morazanico: L 500.00', $values[16]);

        $result->extra_bonuses_amount = 1200;
        $this->assertSame([
            ['Bonos extras (sin desglose)', 1200.0, true],
        ], app(PayrollVoucherBonusPresenter::class)->extraBonusRows($result));
    }

    public function test_unexpected_manual_bonus_blocks_all_changes(): void
    {
        $period = PayrollPeriod::query()->create([
            'name' => 'Octubre 2026', 'starts_at' => '2026-09-26', 'ends_at' => '2026-10-10',
        ]);
        PayrollBonus::withoutEvents(fn () => PayrollBonus::query()->create([
            'payroll_period_id' => $period->id, 'scope_type' => 'employee',
            'type' => 'manual', 'amount' => 25, 'description' => 'Otro pago', 'status' => 'aprobado',
        ]));
        try {
            app(OctoberFirstHalfBonusClassificationService::class)->apply($period);
            $this->fail('Expected the unexpected bonus to block classification.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('inesperado', $exception->getMessage());
        }
        $this->assertDatabaseHas('payroll_bonuses', ['payroll_period_id' => $period->id, 'type' => 'manual']);
    }
}
