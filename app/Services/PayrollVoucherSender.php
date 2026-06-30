<?php

namespace App\Services;

use App\Mail\PayrollVoucherMail;
use App\Models\PayrollResult;
use Illuminate\Support\Facades\Mail;
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

        Mail::to($email)->send(new PayrollVoucherMail($payrollResult, $comment));

        $payrollResult->forceFill([
            'voucher_sent_at' => now(),
            'voucher_sent_to' => $email,
        ])->save();
    }
}
