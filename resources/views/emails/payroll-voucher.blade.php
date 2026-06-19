@php
    $money = fn ($value): string => 'L ' . number_format((float) $value, 2, '.', ',');

    $bonusRows = [
        ['Bono extra cliente', $result->extra_bonuses_amount, true],
        ['Horas extras', $result->overtime_amount, true],
        ['Subsidio por internet', $result->internet_subsidy_amount, true],
        ['Bono QA', $result->qa_bonus_amount, true],
        ['Bono Productividad', $result->productivity_bonus_amount, true],
        ['Bono TM', $result->time_management_bonus_amount, true],
        ['Bono referido', $result->referred_bonus_amount, (float) $result->referred_bonus_amount !== 0.0],
        ['Ajuste', $result->adjustment_bonus_amount, (float) $result->adjustment_bonus_amount !== 0.0],
        ['Compensación planilla', $result->payroll_compensation_amount, (float) $result->payroll_compensation_amount !== 0.0],
    ];

    $deductionRows = [
        ['PAN AME', $result->private_insurance_amount, true],
        ['IHSS', $result->ihss_amount, true],
        ['ISR', $result->isr_amount, (float) $result->isr_amount !== 0.0],
        ['RAP', $result->rap_amount, (float) $result->rap_amount !== 0.0],
        ['VALES', $result->vouchers_amount, (float) $result->vouchers_amount !== 0.0],
    ];
@endphp

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Voucher de planilla - {{ $periodName }}</title>
</head>
<body style="margin: 0; padding: 0; background: #f4f6f8; color: #111827; font-family: Arial, Helvetica, sans-serif;">
    <div style="background: #f4f6f8; color: #111827; font-family: Arial, Helvetica, sans-serif; padding: 24px 0;">
        <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background: #f4f6f8; color: #111827; padding: 0;">
            <tr>
                <td align="center" style="color: #111827;">
                    <table width="680" cellpadding="0" cellspacing="0" role="presentation" style="width: 680px; max-width: 94%; background: #ffffff; color: #111827; border: 1px solid #e5e7eb; border-radius: 14px; overflow: hidden;">
                        <tr>
                            <td style="padding: 26px 32px 18px; text-align: center; border-bottom: 1px solid #e5e7eb; background: #ffffff; color: #111827;">
                                @if (is_file($logoPath))
                                    <img src="{{ isset($message) ? $message->embed($logoPath) : asset('images/dosys-logo.jpg') }}" alt="Dosys BPO" style="max-width: 230px; height: auto; margin-bottom: 18px;">
                                @endif
                                <h1 style="margin: 0; color: #0f172a; font-size: 22px; line-height: 1.3;">Voucher de planilla</h1>
                                <p style="margin: 6px 0 0; color: #64748b; font-size: 14px;">{{ $periodName }}</p>
                            </td>
                        </tr>

                        <tr>
                            <td style="padding: 24px 32px; background: #ffffff; color: #111827;">
                                <p style="margin: 0 0 18px; font-size: 14px; color: #334155;">
                                    Estimado/a {{ $result->employee?->name ?? 'empleado' }}, compartimos el detalle de su voucher de planilla.
                                </p>

                                @if (filled($comment))
                                    <div style="margin: 0 0 20px; padding: 12px 14px; background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 10px; color: #1e3a8a; font-size: 14px;">
                                        {{ $comment }}
                                    </div>
                                @endif

                                <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="border-collapse: collapse; color: #111827; font-size: 14px; margin-bottom: 18px;">
                                    <tr>
                                        <td colspan="2" style="padding: 11px 12px; background: #0f172a; color: #ffffff; border: 1px solid #0f172a; font-weight: bold;">Datos del empleado</td>
                                    </tr>
                                    <tr>
                                        <td style="padding: 11px 12px; border: 1px solid #e5e7eb; background: #f8fafc; color: #111827; font-weight: bold;">Nombre empleado</td>
                                        <td style="padding: 11px 12px; border: 1px solid #e5e7eb; background: #ffffff; color: #111827; text-align: right;">{{ $result->employee?->name }}</td>
                                    </tr>
                                    <tr>
                                        <td style="padding: 11px 12px; border: 1px solid #e5e7eb; background: #f8fafc; color: #111827; font-weight: bold;">Salario mensual</td>
                                        <td style="padding: 11px 12px; border: 1px solid #e5e7eb; background: #ffffff; color: #111827; text-align: right;">{{ $money($result->monthly_salary) }}</td>
                                    </tr>
                                    <tr>
                                        <td style="padding: 11px 12px; border: 1px solid #e5e7eb; background: #f8fafc; color: #111827; font-weight: bold;">Pago quincenal</td>
                                        <td style="padding: 11px 12px; border: 1px solid #e5e7eb; background: #ffffff; color: #111827; text-align: right;">{{ $money($result->biweekly_salary_amount) }}</td>
                                    </tr>
                                    <tr>
                                        <td style="padding: 12px; border: 1px solid #bfdbfe; background: #dbeafe; color: #1e3a8a; font-weight: bold;">Total devengado</td>
                                        <td style="padding: 12px; border: 1px solid #bfdbfe; background: #dbeafe; color: #1e3a8a; text-align: right; font-weight: bold;">{{ $money($result->worked_salary_amount) }}</td>
                                    </tr>
                                </table>

                                <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="border-collapse: collapse; color: #111827; font-size: 14px; margin-bottom: 18px;">
                                    <tr>
                                        <td colspan="2" style="padding: 11px 12px; background: #065f46; color: #ffffff; border: 1px solid #065f46; font-weight: bold;">Bonificaciones</td>
                                    </tr>
                                    @foreach ($bonusRows as [$label, $value, $visible])
                                        @if ($visible)
                                            <tr>
                                                <td style="padding: 11px 12px; border: 1px solid #d1fae5; background: #f0fdf4; color: #064e3b; font-weight: bold;">{{ $label }}</td>
                                                <td style="padding: 11px 12px; border: 1px solid #d1fae5; background: #ffffff; color: #064e3b; text-align: right;">{{ $money($value) }}</td>
                                            </tr>
                                        @endif
                                    @endforeach
                                    <tr>
                                        <td style="padding: 12px; border: 1px solid #86efac; background: #dcfce7; color: #14532d; font-weight: bold;">Total bonificaciones</td>
                                        <td style="padding: 12px; border: 1px solid #86efac; background: #dcfce7; color: #14532d; text-align: right; font-weight: bold;">{{ $money($result->extras_total_amount) }}</td>
                                    </tr>
                                </table>

                                <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="border-collapse: collapse; color: #111827; font-size: 14px; margin-bottom: 18px;">
                                    <tr>
                                        <td colspan="2" style="padding: 11px 12px; background: #991b1b; color: #ffffff; border: 1px solid #991b1b; font-weight: bold;">Deducciones</td>
                                    </tr>
                                    @foreach ($deductionRows as [$label, $value, $visible])
                                        @if ($visible)
                                            <tr>
                                                <td style="padding: 11px 12px; border: 1px solid #fecaca; background: #fef2f2; color: #7f1d1d; font-weight: bold;">{{ $label }}</td>
                                                <td style="padding: 11px 12px; border: 1px solid #fecaca; background: #ffffff; color: #7f1d1d; text-align: right;">{{ $money($value) }}</td>
                                            </tr>
                                        @endif
                                    @endforeach
                                    <tr>
                                        <td style="padding: 12px; border: 1px solid #fca5a5; background: #fee2e2; color: #7f1d1d; font-weight: bold;">Total deducciones</td>
                                        <td style="padding: 12px; border: 1px solid #fca5a5; background: #fee2e2; color: #7f1d1d; text-align: right; font-weight: bold;">{{ $money($result->total_deductions_amount) }}</td>
                                    </tr>
                                </table>

                                <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="border-collapse: collapse; color: #111827; font-size: 14px;">
                                    <tr>
                                        <td style="padding: 11px 12px; border: 1px solid #e5e7eb; background: #f8fafc; color: #111827; font-weight: bold;">Total devengado</td>
                                        <td style="padding: 11px 12px; border: 1px solid #e5e7eb; background: #ffffff; color: #111827; text-align: right;">{{ $money($result->worked_salary_amount) }}</td>
                                    </tr>
                                    <tr>
                                        <td style="padding: 11px 12px; border: 1px solid #e5e7eb; background: #f8fafc; color: #111827; font-weight: bold;">Total bonificaciones</td>
                                        <td style="padding: 11px 12px; border: 1px solid #e5e7eb; background: #ffffff; color: #111827; text-align: right;">{{ $money($result->extras_total_amount) }}</td>
                                    </tr>
                                    <tr>
                                        <td style="padding: 11px 12px; border: 1px solid #e5e7eb; background: #f8fafc; color: #111827; font-weight: bold;">Total deducciones</td>
                                        <td style="padding: 11px 12px; border: 1px solid #e5e7eb; background: #ffffff; color: #111827; text-align: right;">- {{ $money($result->total_deductions_amount) }}</td>
                                    </tr>
                                    <tr>
                                        <td style="padding: 14px 12px; border: 1px solid #0f172a; background: #0f172a; color: #ffffff; font-size: 16px; font-weight: bold;">Total a pagar</td>
                                        <td style="padding: 14px 12px; border: 1px solid #0f172a; background: #0f172a; color: #ffffff; text-align: right; font-size: 16px; font-weight: bold;">{{ $money($result->net_amount) }}</td>
                                    </tr>
                                </table>
                            </td>
                        </tr>

                        <tr>
                            <td style="padding: 18px 32px; background: #f8fafc; border-top: 1px solid #e5e7eb; color: #64748b; font-size: 12px; text-align: center;">
                                Este correo fue generado por Dosys BPO para fines informativos de planilla.
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </div>
</body>
</html>
