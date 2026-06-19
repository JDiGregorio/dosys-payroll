<?php

namespace App\Mail;

use App\Models\PayrollResult;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PayrollVoucherMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public PayrollResult $payrollResult,
        public ?string $comment = null,
    ) {}

    public function envelope(): Envelope
    {
        $periodName = $this->payrollResult->payrollPeriod?->name ?? 'período';

        return new Envelope(
            subject: "Voucher de planilla - {$periodName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.payroll-voucher',
            with: [
                'result' => $this->payrollResult,
                'comment' => $this->comment,
                'periodName' => $this->payrollResult->payrollPeriod?->name ?? 'Período de planilla',
                'logoPath' => public_path('images/dosys-logo.jpg'),
            ],
        );
    }
}
