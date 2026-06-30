<?php

namespace App\Jobs;

use App\Mail\PayrollVoucherMail;
use App\Models\PayrollResult;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class SendPayrollVoucher implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public int $payrollResultId,
        public string $email,
        public ?string $comment = null,
    ) {}

    public function handle(): void
    {
        $payrollResult = PayrollResult::query()
            ->with(['employee.campaign', 'employee.team', 'employee.workRole', 'employee.tierLevel', 'payrollPeriod'])
            ->findOrFail($this->payrollResultId);

        if ($payrollResult->employee?->active === false) {
            throw new RuntimeException('El empleado está inactivo y no puede recibir voucher.');
        }

        Mail::to($this->email)->send(new PayrollVoucherMail($payrollResult, $this->comment));

        $payrollResult->forceFill([
            'voucher_delivery_status' => 'sent',
            'voucher_sent_at' => now(),
            'voucher_sent_to' => $this->email,
            'voucher_failed_at' => null,
            'voucher_error' => null,
        ])->save();
    }

    public function failed(?Throwable $exception): void
    {
        PayrollResult::query()
            ->whereKey($this->payrollResultId)
            ->update([
                'voucher_delivery_status' => 'failed',
                'voucher_failed_at' => now(),
                'voucher_error' => Str::limit($exception?->getMessage() ?? 'Error desconocido al enviar voucher.', 1000),
            ]);
    }
}
