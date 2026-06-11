@once
    <style>
        .dr-page {
            display: grid;
            gap: 18px;
        }

        .dr-toolbar {
            display: grid;
            grid-template-columns: minmax(260px, 1fr) auto;
            gap: 14px;
            align-items: end;
            padding: 16px;
            border: 1px solid #d8dee4;
            border-radius: 8px;
            background: #ffffff;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.06);
        }

        .dr-label {
            color: #4b5563;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: .04em;
            text-transform: uppercase;
        }

        .dr-context strong,
        .dr-range strong {
            display: block;
            color: #111827;
            font-size: 14px;
            font-weight: 700;
        }

        .dr-context span,
        .dr-range span {
            display: block;
            margin-top: 2px;
            color: #6b7280;
            font-size: 12px;
        }

        .dr-range {
            min-width: 210px;
            padding: 9px 12px;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            background: #f9fafb;
        }

        .dr-calendar-shell {
            overflow: hidden;
            border: 1px solid #d8dee4;
            border-radius: 8px;
            background: #ffffff;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.06);
        }

        .dr-calendar-scroll {
            overflow-x: auto;
        }

        .dr-calendar {
            min-width: 980px;
        }

        .dr-weekdays,
        .dr-grid {
            display: grid;
            grid-template-columns: repeat(7, minmax(140px, 1fr));
        }

        .dr-weekday {
            padding: 12px 8px;
            border-right: 1px solid #e5e7eb;
            border-bottom: 1px solid #d8dee4;
            background: #f8fafc;
            color: #374151;
            font-size: 12px;
            font-weight: 800;
            text-align: center;
            text-transform: uppercase;
        }

        .dr-weekday:last-child,
        .dr-day:nth-child(7n) {
            border-right: 0;
        }

        .dr-day {
            min-height: 132px;
            padding: 7px;
            border-right: 1px solid #e5e7eb;
            border-bottom: 1px solid #e5e7eb;
            background: #ffffff;
        }

        .dr-day-outside {
            background: #f9fafb;
        }

        .dr-day-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 8px;
            min-height: 24px;
        }

        .dr-date {
            color: #4b5563;
            font-size: 13px;
            font-weight: 700;
        }

        .dr-month {
            display: block;
            color: #9ca3af;
            font-size: 10px;
            font-weight: 600;
            text-transform: lowercase;
        }

        .dr-badge {
            max-width: 84px;
            overflow: hidden;
            padding: 2px 6px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 700;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .dr-badge-empty {
            background: #f3f4f6;
            color: #6b7280;
        }

        .dr-badge-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .dr-badge-reviewed {
            background: #dbeafe;
            color: #1e40af;
        }

        .dr-badge-approved {
            background: #d1fae5;
            color: #065f46;
        }

        .dr-event {
            display: grid;
            gap: 4px;
            margin-top: 8px;
            padding: 7px;
            border: 1px solid #fb7185;
            border-radius: 4px;
            background: #fff1f2;
            color: #9f1239;
            font-size: 11px;
            text-decoration: none;
            transition: border-color .15s ease, background .15s ease, box-shadow .15s ease;
        }

        .dr-event:hover {
            border-color: #e11d48;
            background: #ffe4e6;
            box-shadow: 0 0 0 2px rgba(225, 29, 72, .12);
        }

        .dr-event-ok {
            border-color: #2dd4bf;
            background: #f0fdfa;
            color: #0f766e;
        }

        .dr-event-review {
            border-color: #60a5fa;
            background: #eff6ff;
            color: #1d4ed8;
        }

        .dr-event-row {
            display: flex;
            justify-content: space-between;
            gap: 8px;
            line-height: 1.2;
        }

        .dr-event-row span {
            opacity: .82;
        }

        .dr-event-row strong {
            font-weight: 800;
        }

        .dr-empty {
            margin-top: 8px;
            padding: 7px;
            border: 1px dashed #d1d5db;
            border-radius: 4px;
            color: #6b7280;
            font-size: 11px;
        }

        @media (max-width: 900px) {
            .dr-toolbar {
                grid-template-columns: 1fr;
            }

            .dr-range {
                min-width: 0;
            }
        }

        html.dark .dr-toolbar,
        html.dark .dr-calendar-shell {
            border-color: #374151;
            background: #111827;
        }

        html.dark .dr-range {
            border-color: #4b5563;
            background: #0b1120;
        }

        html.dark .dr-label,
        html.dark .dr-weekday {
            color: #d1d5db;
        }

        html.dark .dr-context strong,
        html.dark .dr-range strong {
            color: #f9fafb;
        }

        html.dark .dr-context span,
        html.dark .dr-range span,
        html.dark .dr-date,
        html.dark .dr-empty {
            color: #9ca3af;
        }

        html.dark .dr-weekday,
        html.dark .dr-day-outside {
            background: #0b1120;
        }

        html.dark .dr-weekday,
        html.dark .dr-day {
            border-color: #374151;
        }

        html.dark .dr-day {
            background: #111827;
        }

        html.dark .dr-month {
            color: #6b7280;
        }
    </style>
@endonce

@php
    use App\Filament\Resources\DailyTimeReviews\DailyTimeReviewResource;
    use App\Models\DailyTimeReview;
    use App\Services\TimeParserService;
    use Carbon\CarbonInterface;
    use Carbon\CarbonPeriod;

    $timeParser = app(TimeParserService::class);
    $period = $record?->payrollPeriod;
    $employee = $record?->employee;
    $reviews = $period && $employee
        ? DailyTimeReview::query()
            ->where('payroll_period_id', $period->id)
            ->where('employee_id', $employee->id)
            ->get()
            ->keyBy(fn (DailyTimeReview $review) => $review->date->toDateString())
        : collect();

    $calendarDays = $period
        ? collect(CarbonPeriod::create(
            $period->starts_at->copy()->startOfWeek(CarbonInterface::MONDAY),
            $period->ends_at->copy()->endOfWeek(CarbonInterface::SUNDAY),
        ))
        : collect();

    $statusLabel = fn (?string $status): string => match ($status) {
        'pendiente' => 'Pendiente',
        'revisado_supervisor', 'aprobado_rrhh' => 'Aplicada',
        default => 'Sin revisión',
    };
@endphp

<div class="dr-page">
    @if (! $period || ! $employee)
        <div class="dr-empty">No hay período o empleado relacionado.</div>
    @else
        <div class="dr-toolbar">
            <div class="dr-context">
                <span class="dr-label">Empleado</span>
                <strong>{{ $employee->name }}</strong>
                <span>{{ $period->name }}</span>
            </div>

            <div class="dr-range">
                <strong>{{ $period->starts_at->format('d/m/Y') }} - {{ $period->ends_at->format('d/m/Y') }}</strong>
                <span>Rango del período</span>
            </div>
        </div>

        <div class="dr-calendar-shell">
            <div class="dr-calendar-scroll">
                <div class="dr-calendar">
                    <div class="dr-weekdays">
                        @foreach (['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'] as $weekday)
                            <div class="dr-weekday">{{ $weekday }}</div>
                        @endforeach
                    </div>

                    <div class="dr-grid">
                        @foreach ($calendarDays as $day)
                            @php
                                $review = $reviews->get($day->toDateString());
                                $insidePeriod = $day->betweenIncluded($period->starts_at, $period->ends_at);
                                $hasHubstaff = (int) ($review?->hubstaff_total_seconds ?? 0) > 0;
                                $isOff = (bool) ($review?->paid_day_off ?? false);
                                $isJustifiedAbsence = $review && ! $hasHubstaff && (int) $review->justified_absence_seconds >= (int) $review->expected_seconds && (int) $review->expected_seconds > 0;
                                $eventClass = match (true) {
                                    $isOff => 'dr-event-ok',
                                    $isJustifiedAbsence => 'dr-event-review',
                                    $review && ! $hasHubstaff => '',
                                    default => match ($review?->status) {
                                        'aprobado_rrhh' => 'dr-event-ok',
                                        'revisado_supervisor' => 'dr-event-review',
                                        default => '',
                                    },
                                };
                                $badgeClass = match ($review?->status) {
                                    'pendiente' => 'dr-badge-pending',
                                    'revisado_supervisor' => 'dr-badge-reviewed',
                                    'aprobado_rrhh' => 'dr-badge-approved',
                                    default => 'dr-badge-empty',
                                };
                            @endphp

                            <div class="dr-day {{ $insidePeriod ? '' : 'dr-day-outside' }}">
                                <div class="dr-day-head">
                                    <div class="dr-date">
                                        {{ $day->format('j') }}
                                        <span class="dr-month">{{ $day->translatedFormat('M') }}</span>
                                    </div>

                                    <span class="dr-badge {{ $badgeClass }}">{{ $statusLabel($review?->status) }}</span>
                                </div>

                                @if ($review)
                                    <a href="{{ DailyTimeReviewResource::getUrl('edit', ['record' => $review]) }}" class="dr-event {{ $eventClass }}">
                                        @if ($isOff)
                                            <div class="dr-event-row">
                                                <span>OFF</span>
                                                <strong></strong>
                                            </div>
                                        @elseif ($isJustifiedAbsence)
                                            <div class="dr-event-row">
                                                <span>Justificado</span>
                                                <strong></strong>
                                            </div>
                                        @elseif (! $hasHubstaff)
                                            <div class="dr-event-row">
                                                <span>Sin registro</span>
                                                <strong></strong>
                                            </div>
                                        @else
                                            <div class="dr-event-row">
                                                <span>Hubstaff</span>
                                                <strong>{{ $timeParser->secondsToHourMinute($review->hubstaff_total_seconds) }} h</strong>
                                            </div>
                                            <div class="dr-event-row">
                                                <span>Pagables</span>
                                                <strong>{{ $timeParser->secondsToHourMinute($review->payable_seconds) }} h</strong>
                                            </div>
                                            <div class="dr-event-row">
                                                <span>Idle</span>
                                                <strong>{{ $timeParser->secondsToHourMinute($review->hubstaff_idle_seconds) }} h</strong>
                                            </div>
                                            <div class="dr-event-row">
                                                <span>Dif.</span>
                                                <strong>{{ $timeParser->secondsToHourMinute($review->difference_seconds) }} h</strong>
                                            </div>
                                        @endif
                                    </a>
                                @elseif ($insidePeriod)
                                    <div class="dr-empty">Sin revisión generada</div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
