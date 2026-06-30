<?php

namespace App\Services;

use App\Jobs\SendPayrollVoucher;
use App\Models\PayrollResult;
use RuntimeException;

class PayrollVoucherSender
{
    public function send(PayrollResult $payrollResult, ?string $comment = null): void
    {
        $payrollResult->loadMissing(['employee.campaign', 'employee.team', 'employee.workRole', 'employee.tierLevel', 'payrollPeriod']);

        if ($payrollResult->employee?->active === false) {
            throw new RuntimeException('El empleado está inactivo y no puede recibir voucher.');
        }

        $email = trim((string) $payrollResult->employee?->email);

        if ($email === '') {
            throw new RuntimeException('El empleado no tiene correo configurado.');
        }

        $payrollResult->forceFill([
            'voucher_delivery_status' => 'queued',
            'voucher_queued_at' => now(),
            'voucher_sent_to' => $email,
            'voucher_failed_at' => null,
            'voucher_error' => null,
        ])->save();

        SendPayrollVoucher::dispatch($payrollResult->id, $email, $comment);
    }
}
