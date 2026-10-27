@php
    use AppServicesTrackabiTrackabiPaidTimeComplianceService;
    use AppServicesTimeParserService;

    $row = $record
        ? collect(app(TrackabiPaidTimeComplianceService::class)->inspect($record->payrollPeriod))
            ->first(fn (array $item): bool => $item['employee_id'] === $record->employee_id
                && $item['date'] === $record->date->toDateString())
        : null;
    $parser = app(TimeParserService::class);
    $labels = [
        'TRACKABI_LUNCH_NOT_TRACKED' => 'Lunch no marcado completo',
        'TRACKABI_LUNCH_EXCESS' => 'Lunch excedido',
        'TRACKABI_PAID_BREAK_NOT_TRACKED' => 'Break no marcado completo',
        'TRACKABI_PAID_BREAK_EXCESS' => 'Break excedido',
        'TRACKABI_BREAK_OCCURRENCE_MISMATCH' => 'Cantidad de breaks distinta',
    ];
@endphp

@if ($row)
    <div class="overflow-x-auto">
        <table class="w-full text-sm text-left">
            <thead><tr class="border-b">
                <th class="p-2">Tipo</th><th class="p-2">Esperado</th><th class="p-2">Marcado</th>
                <th class="p-2">Diferencia</th><th class="p-2">Ocurrencias</th>
            </tr></thead>
            <tbody>
                <tr class="border-b">
                    <td class="p-2">Lunch</td>
                    <td class="p-2">{{ $parser->secondsToHourMinute($row['lunch_entitlement_seconds']) }}</td>
                    <td class="p-2">{{ $parser->secondsToHourMinute($row['lunch_tracked_seconds']) }}</td>
                    <td class="p-2">{{ ($row['lunch_variance_seconds'] < 0 ? '-' : '+').$parser->secondsToHourMinute(abs($row['lunch_variance_seconds'])) }}</td>
                    <td class="p-2">-</td>
                </tr>
                <tr>
                    <td class="p-2">Break</td>
                    <td class="p-2">{{ $parser->secondsToHourMinute($row['break_entitlement_seconds']) }}</td>
                    <td class="p-2">{{ $parser->secondsToHourMinute($row['break_tracked_seconds']) }}</td>
                    <td class="p-2">{{ ($row['break_variance_seconds'] < 0 ? '-' : '+').$parser->secondsToHourMinute(abs($row['break_variance_seconds'])) }}</td>
                    <td class="p-2">{{ $row['break_tracked_occurrences'] }} / {{ $row['break_expected_occurrences'] }}</td>
                </tr>
            </tbody>
        </table>
        <p class="mt-3 text-sm">{{ $row['alert_codes'] === [] ? 'Correcto' : implode(', ', array_map(fn ($code) => $labels[$code] ?? $code, $row['alert_codes'])) }}</p>
    </div>
@else
    <p class="text-sm">Sin jornada con actividad para auditar en esta fecha.</p>
@endif
