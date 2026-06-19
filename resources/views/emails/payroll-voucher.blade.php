@php
    $money = fn ($value): string => 'L ' . number_format((float) $value, 2, '.', ',');
    $number = fn ($value): string => number_format((float) $value, 2, '.', ',');

    $optionalRows = [
        ['Bono referido', $result->referred_bonus_amount],
        ['Ajuste', $result->adjustment_bonus_amount],
        ['Compensación planilla', $result->payroll_compensation_amount],
        ['ISR', $result->isr_amount],
        ['RAP', $result->rap_amount],
        ['VALES', $result->vouchers_amount],
    ];
@endphp

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Voucher de planilla - {{ $periodName }}</title>
</head>
<body style="margin: 0; padding: 0; background: #f4f6f8; color: #111827; font-family: Arial, Helvetica, sans-serif;">
    <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background: #f4f6f8; padding: 24px 0;">
        <tr>
            <td align="center">
                <table width="680" cellpadding="0" cellspacing="0" role="presentation" style="width: 680px; max-width: 94%; background: #ffffff; border: 1px solid #e5e7eb; border-radius: 14px; overflow: hidden;">
                    <tr>
                        <td style="padding: 26px 32px 18px; text-align: center; border-bottom: 1px solid #e5e7eb;">
                            @if (is_file($logoPath))
                                <img src="{{ isset($message) ? $message->embed($logoPath) : asset('images/dosys-logo.jpg') }}" alt="Dosys BPO" style="max-width: 230px; height: auto; margin-bottom: 18px;">
                            @endif
                            <h1 style="margin: 0; color: #0f172a; font-size: 22px; line-height: 1.3;">Voucher de planilla</h1>
                            <p style="margin: 6px 0 0; color: #64748b; font-size: 14px;">{{ $periodName }}</p>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding: 24px 32px;">
                            <p style="margin: 0 0 18px; font-size: 14px; color: #334155;">
                                Estimado/a {{ $result->employee?->name ?? 'empleado' }}, compartimos el detalle de su voucher de planilla.
                            </p>

                            @if (filled($comment))
                                <div style="margin: 0 0 20px; padding: 12px 14px; background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 10px; color: #1e3a8a; font-size: 14px;">
                                    {{ $comment }}
                                </div>
                            @endif

                            <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="border-collapse: collapse; font-size: 14px;">
                                <tr>
                                    <td style="padding: 11px 12px; border: 1px solid #e5e7eb; background: #f8fafc; font-weight: bold;">Nombre empleado</td>
                                    <td style="padding: 11px 12px; border: 1px solid #e5e7eb; text-align: right;">{{ $result->employee?->name }}</td>
                                </tr>
                                <tr>
                                    <td style="padding: 11px 12px; border: 1px solid #e5e7eb; background: #f8fafc; font-weight: bold;">Salario mensual</td>
                                    <td style="padding: 11px 12px; border: 1px solid #e5e7eb; text-align: right;">{{ $money($result->monthly_salary) }}</td>
                                </tr>
                                <tr>
                                    <td style="padding: 11px 12px; border: 1px solid #e5e7eb; background: #f8fafc; font-weight: bold;">Días trabajados</td>
                                    <td style="padding: 11px 12px; border: 1px solid #e5e7eb; text-align: right;">{{ $number($result->worked_days) }}</td>
                                </tr>
                                <tr>
                                    <td style="padding: 11px 12px; border: 1px solid #e5e7eb; background: #f8fafc; font-weight: bold;">Pago quincenal</td>
                                    <td style="padding: 11px 12px; border: 1px solid #e5e7eb; text-align: right;">{{ $money($result->biweekly_salary_amount) }}</td>
                                </tr>
                                <tr>
                                    <td style="padding: 11px 12px; border: 1px solid #e5e7eb; background: #f8fafc; font-weight: bold;">Bono extra cliente</td>
                                    <td style="padding: 11px 12px; border: 1px solid #e5e7eb; text-align: right;">{{ $money($result->extra_bonuses_amount) }}</td>
                                </tr>
                                <tr>
                                    <td style="padding: 11px 12px; border: 1px solid #e5e7eb; background: #f8fafc; font-weight: bold;">Horas extras</td>
                                    <td style="padding: 11px 12px; border: 1px solid #e5e7eb; text-align: right;">{{ $money($result->overtime_amount) }}</td>
                                </tr>
                                <tr>
                                    <td style="padding: 11px 12px; border: 1px solid #e5e7eb; background: #f8fafc; font-weight: bold;">Subsidio por internet</td>
                                    <td style="padding: 11px 12px; border: 1px solid #e5e7eb; text-align: right;">{{ $money($result->internet_subsidy_amount) }}</td>
                                </tr>
                                <tr>
                                    <td style="padding: 11px 12px; border: 1px solid #e5e7eb; background: #f8fafc; font-weight: bold;">Bono QA</td>
                                    <td style="padding: 11px 12px; border: 1px solid #e5e7eb; text-align: right;">{{ $money($result->qa_bonus_amount) }}</td>
                                </tr>
                                <tr>
                                    <td style="padding: 11px 12px; border: 1px solid #e5e7eb; background: #f8fafc; font-weight: bold;">Bono Productividad</td>
                                    <td style="padding: 11px 12px; border: 1px solid #e5e7eb; text-align: right;">{{ $money($result->productivity_bonus_amount) }}</td>
                                </tr>
                                <tr>
                                    <td style="padding: 11px 12px; border: 1px solid #e5e7eb; background: #f8fafc; font-weight: bold;">Bono TM</td>
                                    <td style="padding: 11px 12px; border: 1px solid #e5e7eb; text-align: right;">{{ $money($result->time_management_bonus_amount) }}</td>
                                </tr>

                                @foreach ($optionalRows as [$label, $value])
                                    @if ((float) $value !== 0.0)
                                        <tr>
                                            <td style="padding: 11px 12px; border: 1px solid #e5e7eb; background: #f8fafc; font-weight: bold;">{{ $label }}</td>
                                            <td style="padding: 11px 12px; border: 1px solid #e5e7eb; text-align: right;">{{ $money($value) }}</td>
                                        </tr>
                                    @endif
                                @endforeach

                                <tr>
                                    <td style="padding: 11px 12px; border: 1px solid #e5e7eb; background: #ecfeff; font-weight: bold;">Total Extras</td>
                                    <td style="padding: 11px 12px; border: 1px solid #e5e7eb; text-align: right; font-weight: bold;">{{ $money($result->extras_total_amount) }}</td>
                                </tr>
                                <tr>
                                    <td style="padding: 11px 12px; border: 1px solid #e5e7eb; background: #ecfeff; font-weight: bold;">Total devengado</td>
                                    <td style="padding: 11px 12px; border: 1px solid #e5e7eb; text-align: right; font-weight: bold;">{{ $money($result->gross_amount) }}</td>
                                </tr>
                                <tr>
                                    <td style="padding: 11px 12px; border: 1px solid #e5e7eb; background: #f8fafc; font-weight: bold;">PAN AME</td>
                                    <td style="padding: 11px 12px; border: 1px solid #e5e7eb; text-align: right;">{{ $money($result->private_insurance_amount) }}</td>
                                </tr>
                                <tr>
                                    <td style="padding: 11px 12px; border: 1px solid #e5e7eb; background: #f8fafc; font-weight: bold;">IHSS</td>
                                    <td style="padding: 11px 12px; border: 1px solid #e5e7eb; text-align: right;">{{ $money($result->ihss_amount) }}</td>
                                </tr>
                                <tr>
                                    <td style="padding: 11px 12px; border: 1px solid #e5e7eb; background: #fee2e2; font-weight: bold;">Total deducciones</td>
                                    <td style="padding: 11px 12px; border: 1px solid #e5e7eb; text-align: right; font-weight: bold;">{{ $money($result->total_deductions_amount) }}</td>
                                </tr>
                                <tr>
                                    <td style="padding: 14px 12px; border: 1px solid #e5e7eb; background: #0f172a; color: #ffffff; font-size: 16px; font-weight: bold;">Total a pagar</td>
                                    <td style="padding: 14px 12px; border: 1px solid #e5e7eb; background: #0f172a; color: #ffffff; text-align: right; font-size: 16px; font-weight: bold;">{{ $money($result->net_amount) }}</td>
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
</body>
</html>
