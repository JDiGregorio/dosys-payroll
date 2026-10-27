<?php

namespace App\Services\Trackabi;

use App\Models\DailyTimeReview;
use App\Models\PayrollPeriod;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class TrackabiInsightsDirectoryImportService
{
    public function __construct(private readonly TrackabiInsightsCsvImportService $csv) {}

    public function preview(PayrollPeriod $period, string $directory): array
    {
        if (! is_dir($directory) || ! is_readable($directory)) {
            throw new RuntimeException('El directorio de Insights no existe o no puede leerse.');
        }

        $files = glob(rtrim($directory, '/').'/*.csv');
        if ($files === false || $files === []) {
            throw new RuntimeException('El directorio no contiene archivos CSV.');
        }
        sort($files, SORT_STRING);
        $dates = [];
        $reports = [];
        foreach ($files as $path) {
            $filename = basename($path);
            if (! preg_match('/^(\d{4}-\d{2}-\d{2})-\1-insights\.csv$/D', $filename, $matches)
                || ! Carbon::hasFormat($matches[1], 'Y-m-d')) {
                throw new RuntimeException("DATE_REQUIRED: el archivo {$filename} no identifica un solo día de forma inequívoca.");
            }
            $date = $matches[1];
            if ($date < $period->starts_at->toDateString() || $date > $period->ends_at->toDateString()) {
                throw new RuntimeException("El CSV {$filename} está fuera del período #{$period->id}.");
            }
            if (isset($dates[$date])) {
                throw new RuntimeException("Hay más de un CSV para {$date}.");
            }
            $dates[$date] = true;
            $reports[] = ['path' => $path, ...$this->csv->preview($period, Carbon::parse($date), $path)];
        }

        $expectedDates = [];
        foreach (CarbonPeriod::create($period->starts_at, $period->ends_at) as $date) {
            $expectedDates[] = $date->toDateString();
        }
        $missingDates = array_values(array_diff($expectedDates, array_keys($dates)));
        $unmatched = collect($reports)->sum(fn (array $report): int => count($report['employees_unmatched']));
        $errors = collect($reports)->sum(fn (array $report): int => count($report['errors']));
        $csvDays = collect($reports)->flatMap(fn (array $report) => array_map(
            fn (array $row): string => $row['employee_id'].'|'.$report['date'],
            $report['rows'],
        ))->flip();
        $requiredDays = DailyTimeReview::query()
            ->where('payroll_period_id', $period->id)
            ->where('expected_hubstaff_seconds', '>', 0)
            ->whereHas('employee', fn ($query) => $query->where('paid_without_tracking', false))
            ->get(['employee_id', 'date']);
        $missingExpectedDays = $requiredDays->filter(fn (DailyTimeReview $review): bool =>
            ! $csvDays->has($review->employee_id.'|'.$review->date->toDateString()),
        )->count();

        return [
            'period_id' => $period->id,
            'files' => $reports,
            'dates' => array_keys($dates),
            'missing_dates' => $missingDates,
            'rows' => (int) collect($reports)->sum('rows_count'),
            'matched' => (int) collect($reports)->sum('employees_matched'),
            'unmatched' => (int) $unmatched,
            'ignored' => (int) collect($reports)->sum(fn (array $report): int => count($report['ignored_rows'])),
            'errors' => (int) $errors,
            'productive_seconds' => (int) collect($reports)->sum('productive_total_seconds'),
            'unproductive_seconds' => (int) collect($reports)->sum('unproductive_total_seconds'),
            'measured_seconds' => (int) collect($reports)->sum('measured_total_seconds'),
            'explicit_zero_rows' => (int) collect($reports)->sum(fn (array $report): int => count(array_filter(
                $report['rows'], fn (array $row): bool => $row['measured_seconds'] === 0,
            ))),
            'missing_expected_employee_days' => $missingExpectedDays,
            'would_alert_after_review' => (int) collect($reports)->sum(fn (array $report): int => count(array_filter(
                $report['rows'], fn (array $row): bool => $row['review_action'] === 'alert_after_review'
                    && $row['action'] !== 'skip_same_file',
            ))),
            'would_insert' => (int) collect($reports)->sum('would_insert'),
            'would_update' => (int) collect($reports)->sum('would_update'),
            'would_skip' => (int) collect($reports)->sum('would_skip'),
            'would_create_reviews' => (int) collect($reports)->sum('would_create_reviews'),
            'safe_to_import' => $missingDates === [] && $unmatched === 0 && $errors === 0,
        ];
    }

    public function import(PayrollPeriod $period, string $directory): array
    {
        $plan = $this->preview($period, $directory);
        if (! $plan['safe_to_import']) {
            throw new RuntimeException('El batch tiene fechas faltantes, empleados sin mapear o errores; no se importó ningún CSV.');
        }

        $written = DB::transaction(function () use ($period, $plan): int {
            $total = 0;
            foreach ($plan['files'] as $file) {
                $result = $this->csv->import($period, Carbon::parse($file['date']), $file['path']);
                $total += $result['written'];
            }

            return $total;
        });

        return [...$plan, 'written' => $written];
    }
}
