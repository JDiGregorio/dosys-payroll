<?php

namespace Tests\Feature;

use App\Jobs\SendPayrollVoucher;
use App\Mail\PayrollVoucherMail;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\PayrollResult;
use App\Services\PayrollVoucherSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class PayrollVoucherSenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_queues_payroll_voucher_to_employee_email(): void
    {
        Mail::fake();
        Queue::fake();

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
            'worked_salary_amount' => 7500,
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

        Queue::assertPushed(SendPayrollVoucher::class, function (SendPayrollVoucher $job): bool {
            return $job->email === 'ailen@example.com'
                && $job->comment === 'Comentario de prueba';
        });
        Mail::assertNothingSent();

        $result->refresh();

        $this->assertSame('queued', $result->voucher_delivery_status);
        $this->assertNotNull($result->voucher_queued_at);
        $this->assertNull($result->voucher_sent_at);
        $this->assertSame('ailen@example.com', $result->voucher_sent_to);
        $this->assertStringContainsString('En cola', $result->voucherDeliveryStatus());
    }

    public function test_queued_voucher_job_sends_email_and_marks_result_as_sent(): void
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
            'worked_salary_amount' => 7500,
            'net_amount' => 7500,
            'voucher_delivery_status' => 'queued',
            'voucher_sent_to' => 'ailen@example.com',
        ]);

        (new SendPayrollVoucher($result->id, 'ailen@example.com', 'Comentario de prueba'))->handle();

        Mail::assertSent(PayrollVoucherMail::class, function (PayrollVoucherMail $mail) use ($result): bool {
            return $mail->hasTo('ailen@example.com')
                && $mail->payrollResult->is($result)
                && $mail->comment === 'Comentario de prueba';
        });

        $result->refresh();

        $this->assertSame('sent', $result->voucher_delivery_status);
        $this->assertNotNull($result->voucher_sent_at);
        $this->assertSame('ailen@example.com', $result->voucher_sent_to);
        $this->assertStringContainsString('Enviado', $result->voucherDeliveryStatus());
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
            'worked_salary_amount' => 7500,
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
        $this->assertStringContainsString('Bonificaciones', $html);
        $this->assertStringContainsString('Deducciones', $html);
        $this->assertStringContainsString('Total ingresos extra', $html);
        $this->assertStringContainsString('L 8,300.00', $html);
        $this->assertStringContainsString('Comentario institucional', $html);
        $this->assertStringContainsString('Días trabajados', $html);
    }

    public function test_it_does_not_send_voucher_to_inactive_employee(): void
    {
        Mail::fake();
        Queue::fake();

        $period = PayrollPeriod::query()->create([
            'name' => 'Primera quincena junio 2026',
            'starts_at' => '2026-06-01',
            'ends_at' => '2026-06-15',
        ]);
        $employee = Employee::query()->create([
            'name' => 'Empleado inactivo',
            'email' => 'inactivo@example.com',
            'active' => false,
        ]);
        $result = PayrollResult::query()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
        ]);

        try {
            app(PayrollVoucherSender::class)->send($result);
            $this->fail('Se esperaba una excepción por empleado inactivo.');
        } catch (RuntimeException $exception) {
            $this->assertSame('El empleado está inactivo y no puede recibir voucher.', $exception->getMessage());
        }

        Mail::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_it_does_not_send_voucher_when_employee_has_no_email(): void
    {
        Mail::fake();
        Queue::fake();

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
        Queue::assertNothingPushed();
    }
}
