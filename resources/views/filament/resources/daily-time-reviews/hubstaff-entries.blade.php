@php
    use App\Models\HubstaffTimeEntry;
    use App\Services\TimeParserService;

    $parser = app(TimeParserService::class);
    $entries = $record
        ? HubstaffTimeEntry::query()
            ->where('payroll_period_id', $record->payroll_period_id)
            ->where('employee_id', $record->employee_id)
            ->whereDate('date', $record->date)
            ->orderBy('id')
            ->get()
        : collect();
@endphp

<div class="hubstaff-detail">
    <div class="hubstaff-detail__header">
        <div>
            <div class="hubstaff-detail__title">Detalle importado desde Hubstaff</div>
            <div class="hubstaff-detail__subtitle">
                {{ $record?->employee?->name }} · {{ $record?->date?->format('d/m/Y') }}
            </div>
        </div>

        <span class="hubstaff-detail__count">
            {{ $entries->count() }} {{ $entries->count() === 1 ? 'registro' : 'registros' }}
        </span>
    </div>

    <div class="hubstaff-detail__scroll">
        <table class="hubstaff-detail__table">
            <colgroup>
                <col style="width: 29%">
                <col style="width: 15%">
                <col style="width: 12%">
                <col style="width: 12%">
                <col style="width: 12%">
                <col style="width: 10%">
                <col style="width: 10%">
            </colgroup>
            <thead>
                <tr>
                    <th>Proyecto</th>
                    <th>Team</th>
                    <th class="hubstaff-detail__number">Regulares</th>
                    <th class="hubstaff-detail__number">Total</th>
                    <th class="hubstaff-detail__number">Idle</th>
                    <th class="hubstaff-detail__number">Actividad</th>
                    <th class="hubstaff-detail__number">Idle %</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($entries as $entry)
                    <tr>
                        <td class="hubstaff-detail__project" title="{{ $entry->project }}">{{ $entry->project ?: 'Sin proyecto' }}</td>
                        <td class="hubstaff-detail__team" title="{{ $entry->team }}">{{ $entry->team ?: 'Sin team' }}</td>
                        <td class="hubstaff-detail__number">{{ $parser->secondsToHourMinuteSecond($entry->regular_seconds) }}</td>
                        <td class="hubstaff-detail__number">{{ $parser->secondsToHourMinuteSecond($entry->total_seconds) }}</td>
                        <td class="hubstaff-detail__number">{{ $parser->secondsToHourMinuteSecond($entry->idle_seconds) }}</td>
                        <td class="hubstaff-detail__number">
                            <span class="hubstaff-detail__activity">
                                {{ $entry->activity_percentage !== null ? number_format((float) $entry->activity_percentage, 2).'%' : '-' }}
                            </span>
                        </td>
                        <td class="hubstaff-detail__number">
                            <span class="hubstaff-detail__idle">
                                {{ $entry->idle_percentage !== null ? number_format((float) $entry->idle_percentage, 2).'%' : '-' }}
                            </span>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="hubstaff-detail__empty">
                            No hay registros de Hubstaff para este empleado y fecha.
                        </td>
                    </tr>
                @endforelse
            </tbody>
            @if ($entries->isNotEmpty())
                <tfoot class="hubstaff-detail__footer">
                    <tr>
                        <td colspan="2">Totales</td>
                        <td class="hubstaff-detail__number">{{ $parser->secondsToHourMinuteSecond((int) $entries->sum('regular_seconds')) }}</td>
                        <td class="hubstaff-detail__number">{{ $parser->secondsToHourMinuteSecond((int) $entries->sum('total_seconds')) }}</td>
                        <td class="hubstaff-detail__number">{{ $parser->secondsToHourMinuteSecond((int) $entries->sum('idle_seconds')) }}</td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
</div>
