@php
    use App\Models\DailyTimeReview;
    use App\Services\TimeParserService;

    $parser = app(TimeParserService::class);
    $weekStart = $record?->date?->copy()->startOfWeek();
    $weekEnd = $record?->date?->copy()->endOfWeek();
    $days = $record && $weekStart && $weekEnd
        ? DailyTimeReview::query()->where('employee_id', $record->employee_id)
            ->whereBetween('date', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->orderBy('date')->get()
        : collect();
    $approved = max((int) round((float) ($record?->employee?->preassigned_overtime_weekly_hours ?? 0) * 3600), 0);
    $completed = (int) $days->sum('possible_overtime_seconds');
@endphp

<div class="overflow-x-auto text-sm">
    <div class="flex gap-8 mb-3">
        <span>OT aprobado: <strong>{{ $parser->secondsToHourMinute($approved) }}</strong></span>
        <span>OT realizado: <strong>{{ $parser->secondsToHourMinute($completed) }}</strong></span>
        <span>OT pendiente: <strong>{{ $parser->secondsToHourMinute(max($approved - $completed, 0)) }}</strong></span>
    </div>
    <table class="w-full text-left">
        <thead><tr class="border-b"><th class="p-2">Fecha</th><th class="p-2">Ordinarias</th><th class="p-2">OT asignado</th><th class="p-2">OT pagable</th></tr></thead>
        <tbody>
            @foreach ($days as $day)
                <tr class="border-b">
                    <td class="p-2">{{ $day->date->format('d/m/Y') }}</td>
                    <td class="p-2">{{ $parser->secondsToHourMinute((int) $day->expected_ordinary_seconds) }}</td>
                    <td class="p-2">{{ $parser->secondsToHourMinute((int) $day->preassigned_overtime_seconds) }}</td>
                    <td class="p-2">{{ $parser->secondsToHourMinute((int) $day->possible_overtime_seconds) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
