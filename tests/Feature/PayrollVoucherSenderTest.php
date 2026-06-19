<?php

namespace Tests\Feature;

use App\Mail\PayrollVoucherMail;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\PayrollResult;
use App\Services\PayrollVoucherSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

class PayrollVoucherSenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_sends_payroll_voucher_to_employee_email(): void
    {
        Mail::fake();

        $period = PayrollPeriod::query()->create([
            'name' => 'Primera quincena junio 2026',
            'starts_at' => '2026-06-01',
            'ends_at' => '2026-06-15',
        ]);
        $employee = Employee::query()->create([
            'name' => 'Ailen Test',
            'email' => 'ailen@example.com',
        ]);
        $result = PayrollResult::query()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'monthly_salary' => 15000,
            'biweekly_salary_amount' => 7500,
            'worked_days' => 15,
            'overtime_amount' => 500,
            'internet_subsidy_amount' => 300,
            'qa_bonus_amount' => 100,
            'productivity_bonus_amount' => 200,
            'time_management_bonus_amount' => 50,
            'extras_total_amount' => 1150,
            'gross_amount' => 8650,
            'private_insurance_amount' => 100,
            'ihss_amount' => 250,
            'total_deductions_amount' => 350,
            'net_amount' => 8300,
        ]);

        app(PayrollVoucherSender::class)->send($result, 'Comentario de prueba');

        Mail::assertSent(PayrollVoucherMail::class, function (PayrollVoucherMail $mail) use ($result): bool {
            return $mail->hasTo('ailen@example.com')
                && $mail->payrollResult->is($result)
                && $mail->comment === 'Comentario de prueba';
        });
    }

    public function test_payroll_voucher_mail_renders_period_and_amounts(): void
    {
        $period = PayrollPeriod::query()->create([
            'name' => 'Primera quincena junio 2026',
            'starts_at' => '2026-06-01',
            'ends_at' => '2026-06-15',
        ]);
        $employee = Employee::query()->create([
            'name' => 'Ailen Test',
            'email' => 'ailen@example.com',
        ]);
        $result = PayrollResult::query()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'monthly_salary' => 15000,
            'biweekly_salary_amount' => 7500,
            'worked_days' => 15,
            'overtime_amount' => 500,
            'internet_subsidy_amount' => 300,
            'qa_bonus_amount' => 100,
            'productivity_bonus_amount' => 200,
            'time_management_bonus_amount' => 50,
            'extras_total_amount' => 1150,
            'gross_amount' => 8650,
            'private_insurance_amount' => 100,
            'ihss_amount' => 250,
            'total_deductions_amount' => 350,
            'net_amount' => 8300,
        ]);

        $html = (new PayrollVoucherMail($result->fresh(['employee', 'payrollPeriod']), 'Comentario institucional'))->render();

        $this->assertStringContainsString('Voucher de planilla', $html);
        $this->assertStringContainsString('Primera quincena junio 2026', $html);
        $this->assertStringContainsString('Ailen Test', $html);
        $this->assertStringContainsString('L 8,300.00', $html);
        $this->assertStringContainsString('Comentario institucional', $html);
    }

    public function test_it_does_not_send_voucher_when_employee_has_no_email(): void
    {
        Mail::fake();

        $period = PayrollPeriod::query()->create([
            'name' => 'Primera quincena junio 2026',
            'starts_at' => '2026-06-01',
            'ends_at' => '2026-06-15',
        ]);
        $employee = Employee::query()->create(['name' => 'Sin correo']);
        $result = PayrollResult::query()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
        ]);

        try {
            app(PayrollVoucherSender::class)->send($result);
            $this->fail('Se esperaba una excepción por correo faltante.');
        } catch (RuntimeException $exception) {
            $this->assertSame('El empleado no tiene correo configurado.', $exception->getMessage());
        }

        Mail::assertNothingSent();
    }
}
