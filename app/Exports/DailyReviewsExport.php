<?php

namespace App\Exports;

use App\Models\DailyTimeReview;
use App\Models\PayrollPeriod;
use App\Services\TimeParserService;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class DailyReviewsExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping
{
    public function __construct(
        private PayrollPeriod $period,
        private ?TimeParserService $timeParser = null,
    ) {
        $this->timeParser ??= app(TimeParserService::class);
    }

    public function query()
    {
        return DailyTimeReview::query()
            ->with(['employee.campaign', 'employee.team'])
            ->where('payroll_period_id', $this->period->id)
            ->orderBy('date')
            ->orderBy('employee_id');
    }

    public function headings(): array
    {
        return [
            'Fecha',
            'Empleado',
            'Campaña',
            'Team',
            'Horas esperadas',
            'Horas Hubstaff',
            'Idle reportado',
            'Idle justificado',
            'Idle no justificado',
            'Ausencia justificada',
            'Ausencia no justificada',
            'Horas extra preasignadas trabajadas',
            'Horas pagables',
            'Diferencia',
            'Estado',
            'Comentario supervisor',
            'Comentario RRHH',
        ];
    }

    public function map($row): array
    {
        return [
            $row->date->toDateString(),
            $row->employee?->name,
            $row->employee?->campaign?->name,
            $row->employee?->team?->name,
            $this->timeParser->secondsToDecimalHours($row->expected_seconds),
            $this->timeParser->secondsToDecimalHours($row->hubstaff_total_seconds),
            $this->timeParser->secondsToDecimalHours($row->hubstaff_idle_seconds),
            $this->timeParser->secondsToDecimalHours($row->justified_idle_seconds),
            $this->timeParser->secondsToDecimalHours($row->unjustified_idle_seconds),
            $this->timeParser->secondsToDecimalHours($row->justified_absence_seconds),
            $this->timeParser->secondsToDecimalHours($row->unjustified_absence_seconds),
            $this->timeParser->secondsToDecimalHours($row->possible_overtime_seconds),
            $this->timeParser->secondsToDecimalHours($row->payable_seconds),
            $this->timeParser->secondsToDecimalHours($row->difference_seconds),
            $row->status,
            $row->supervisor_comment,
            $row->rrhh_comment,
        ];
    }
}
