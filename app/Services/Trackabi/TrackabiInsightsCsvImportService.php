<?php

namespace App\Services\Trackabi;

use App\Models\DailyTimeReview;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\TrackabiActivityImportBatch;
use App\Models\TrackabiDailyActivity;
use App\Models\TrackingImportAlert;
use App\Services\PayrollCalculationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class TrackabiInsightsCsvImportService
{
    private const HEADERS = [
        'member' => ['member', 'member name', 'employee', 'employee name'],
        'email' => ['email', 'member email', 'employee email'],
        'member_id' => ['member id', 'member_id', 'trackabi member id'],
        'productive' => ['productive activity time', 'productive activity'],
        'unproductive' => ['unproductive activity time', 'unproductive activity'],
        'date' => ['date', 'activity date'],
        'logged' => ['total logged'],
        'billable' => ['total billable'],
    ];

    public function __construct(
        private readonly PayrollCalculationService $payroll,
        private readonly TrackabiInsightsCsvActivityProvider $provider,
        private readonly TrackabiEmployeeIdentityResolver $identityResolver,
        private readonly TrackabiPaidActivityService $paidActivityService,
    ) {}

    public function preview(PayrollPeriod $period, Carbon $date, string $path): array
    {
        $this->assertPeriod($period, $date);
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('El CSV no existe o no puede leerse.');
        }

        $hash = hash_file('sha256', $path);
        if ($hash === false) {
            throw new RuntimeException('No se pudo calcular el hash del CSV.');
        }
        $previousBatch = TrackabiActivityImportBatch::query()
            ->where('payroll_period_id', $period->id)->whereDate('activity_date', $date)->where('file_hash', $hash)->first();
        if (TrackabiActivityImportBatch::query()->where('file_hash', $hash)
            ->whereDate('activity_date', '!=', $date)->exists()) {
            throw new RuntimeException('Este mismo CSV ya se usó para otra fecha; exporta el reporte del día correcto.');
        }
        $employees = Employee::query()->where('active', true)->get();
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('No se pudo abrir el CSV.');
        }

        try {
            if (fread($handle, 3) !== "\xEF\xBB\xBF") {
                rewind($handle);
            }
            $first = fgetcsv($handle);
            if (! is_array($first)) {
                throw new RuntimeException('El CSV está vacío.');
            }
            $headers = $this->headerPositions($first);
            $rows = [];
            $unmatched = [];
            $errors = [];
            $seenEmployees = [];
            $rowNumber = 1;

            while (($fields = fgetcsv($handle)) !== false) {
                $rowNumber++;
                if ($fields === [null] || count(array_filter($fields, fn ($value) => trim((string) $value) !== '')) === 0) {
                    continue;
                }
                if (count($fields) !== count($first)) {
                    $errors[] = "Fila {$rowNumber}: número de columnas distinto al encabezado.";

                    continue;
                }
                $rawValue = fn (string $key): ?string => isset($headers[$key]) ? (string) $fields[$headers[$key]] : null;
                $value = fn (string $key): ?string => isset($headers[$key]) ? trim($rawValue($key)) : null;
                if (isset($headers['date']) && $value('date') !== $date->toDateString()) {
                    $errors[] = "Fila {$rowNumber}: la fecha no coincide con --date.";

                    continue;
                }

                $member = $value('member');
                $email = $value('email');
                $memberId = $value('member_id');
                if ($memberId !== null && $memberId !== '' && (! ctype_digit($memberId) || (int) $memberId < 1)) {
                    $errors[] = "Fila {$rowNumber}: Trackabi Member ID inválido.";

                    continue;
                }
                if (($member === null || $member === '') && ($email === null || $email === '')
                    && ($memberId === null || $memberId === '')) {
                    $errors[] = "Fila {$rowNumber}: falta empleado.";

                    continue;
                }
                $productiveRaw = $value('productive');
                $unproductiveRaw = $value('unproductive');
                try {
                    $activity = $this->provider->normalize(['productive' => $productiveRaw, 'unproductive' => $unproductiveRaw]);
                } catch (\InvalidArgumentException $exception) {
                    $errors[] = "Fila {$rowNumber}: {$exception->getMessage()}";

                    continue;
                }

                $employee = $this->identityResolver->resolve(
                    $employees, $memberId ? (int) $memberId : null, $email, $member,
                );
                if (! $employee) {
                    $unmatched[] = ['row' => $rowNumber, 'member' => $member ?: 'Sin nombre'];

                    continue;
                }
                if (isset($seenEmployees[$employee->id])) {
                    $errors[] = "Fila {$rowNumber}: empleado duplicado en el CSV ({$employee->name}).";

                    continue;
                }
                $seenEmployees[$employee->id] = true;
                $review = DailyTimeReview::query()->where('payroll_period_id', $period->id)
                    ->where('employee_id', $employee->id)->whereDate('date', $date)->first();
                $existing = TrackabiDailyActivity::query()->where('employee_id', $employee->id)
                    ->whereDate('activity_date', $date)->where('source', $this->provider->source())->first();
                $measured = $activity->measuredSeconds();
                $target = $review ? $this->paidActivityService->trackableTargetSeconds($review, $measured) : null;
                $protected = $review && ($review->status !== 'pendiente' || $review->verified_tracked_seconds !== null
                    || (int) $review->supervisor_adjustment_seconds !== 0
                    || (int) $review->justified_absence_seconds !== 0
                    || ($review->lost_time_source !== null
                        && ! in_array($review->lost_time_source, ['historical_estimate', 'trackabi_insights_activity', 'missing_activity', 'missing_timer'], true))
                    || $review->employee?->paid_without_tracking);
                $rows[] = [
                    'employee_id' => $employee->id,
                    'employee' => $employee->name,
                    'row_number' => $rowNumber,
                    'review_id' => $review?->id,
                    'productive_seconds' => $activity->productiveSeconds,
                    'unproductive_seconds' => $activity->unproductiveSeconds,
                    'measured_seconds' => $measured,
                    'target_seconds' => $target,
                    'shortfall_seconds' => $review ? ($review->paid_day_off ? 0 : max($target - $measured, 0)) : null,
                    'over_target_seconds' => $review ? max($measured - $target, 0) : null,
                    'raw' => ['member' => $rawValue('member'), 'productive' => $rawValue('productive'),
                        'unproductive' => $rawValue('unproductive'), 'logged' => $rawValue('logged'),
                        'billable' => $rawValue('billable')],
                    'action' => $previousBatch && $existing
                        ? 'skip_same_file'
                        : ($existing ? 'update' : 'insert'),
                    'review_action' => ! $review ? 'create_review' : ($protected ? 'alert_after_review' : 'apply_pending'),
                ];
            }
        } finally {
            fclose($handle);
        }

        return [
            'period_id' => $period->id,
            'date' => $date->toDateString(),
            'filename' => basename($path),
            'file_hash' => $hash,
            'previous_batch_id' => $previousBatch?->id,
            'rows_count' => count($rows) + count($unmatched) + count($errors),
            'rows' => $rows,
            'employees_matched' => count($rows),
            'employees_unmatched' => $unmatched,
            'errors' => $errors,
            'productive_total_seconds' => array_sum(array_column($rows, 'productive_seconds')),
            'unproductive_total_seconds' => array_sum(array_column($rows, 'unproductive_seconds')),
            'measured_total_seconds' => array_sum(array_column($rows, 'measured_seconds')),
            'would_insert' => count(array_filter($rows, fn ($row) => $row['action'] === 'insert')),
            'would_update' => count(array_filter($rows, fn ($row) => $row['action'] === 'update')),
            'would_skip' => count(array_filter($rows, fn ($row) => $row['action'] === 'skip_same_file')),
            'would_create_reviews' => count(array_filter($rows, fn ($row) => $row['review_action'] === 'create_review')),
        ];
    }

    public function import(PayrollPeriod $period, Carbon $date, string $path): array
    {
        $plan = $this->preview($period, $date, $path);
        if ($plan['errors'] !== []) {
            throw new RuntimeException('El CSV contiene errores; corrígelos antes de importar: '.implode(' ', $plan['errors']));
        }
        if ($plan['employees_unmatched'] !== []) {
            throw new RuntimeException(count($plan['employees_unmatched']).' empleados sin mapear. Resuelve la identidad Trackabi antes de confirmar.');
        }

        return DB::transaction(function () use ($period, $date, $path, $plan): array {
            $lockedPeriod = PayrollPeriod::query()->lockForUpdate()->findOrFail($period->id);
            $this->assertPeriod($lockedPeriod, $date);
            if (hash_file('sha256', $path) !== $plan['file_hash']) {
                throw new RuntimeException('El CSV cambió después de la vista previa. Repite la importación.');
            }
            if ($plan['would_skip'] === count($plan['rows']) && $plan['previous_batch_id'] !== null) {
                return [...$plan, 'written' => 0, 'reviews_updated' => 0];
            }

            if ($plan['would_create_reviews'] > 0) {
                foreach (collect($plan['rows'])->filter(fn ($row) => $row['review_id'] === null)->pluck('employee_id')->unique() as $employeeId) {
                    $this->payroll->recalculateEmployeePreservingManual($lockedPeriod, Employee::query()->findOrFail($employeeId));
                }
                $refreshed = $this->preview($lockedPeriod, $date, $path);
                if ($refreshed['file_hash'] !== $plan['file_hash'] || $refreshed['would_create_reviews'] > 0 || $refreshed['errors'] !== []) {
                    throw new RuntimeException('No se pudo preparar una revisión diaria o el CSV cambió durante la importación.');
                }
                $plan = $refreshed;
            }

            $batch = $plan['previous_batch_id']
                ? TrackabiActivityImportBatch::query()->findOrFail($plan['previous_batch_id'])
                : TrackabiActivityImportBatch::query()->create([
                    'payroll_period_id' => $period->id,
                    'activity_date' => $date->toDateString(),
                    'filename' => $plan['filename'],
                    'file_hash' => $plan['file_hash'],
                    'rows' => $plan['rows_count'],
                    'matched' => $plan['employees_matched'],
                    'unmatched' => count($plan['employees_unmatched']),
                    'imported_by' => auth()->id(),
                    'imported_at' => now(),
                    'metadata' => ['source' => $this->provider->source()],
                ]);
            if ($plan['previous_batch_id']) {
                $batch->update(['matched' => $plan['employees_matched'], 'unmatched' => count($plan['employees_unmatched'])]);
            }
            $written = 0;
            $reviewsUpdated = 0;
            $affected = [];
            foreach ($plan['rows'] as $row) {
                if ($row['action'] === 'skip_same_file') {
                    continue;
                }
                $review = DailyTimeReview::query()->lockForUpdate()->findOrFail($row['review_id']);
                $existing = TrackabiDailyActivity::query()->where('employee_id', $row['employee_id'])
                    ->whereDate('activity_date', $date)->where('source', $this->provider->source())->first();
                $activityChanged = ! $existing
                    || (int) $existing->productive_activity_seconds !== $row['productive_seconds']
                    || (int) $existing->unproductive_activity_seconds !== $row['unproductive_seconds']
                    || (int) $existing->measured_activity_seconds !== $row['measured_seconds']
                    || collect($row['raw'])->some(fn ($value, $key): bool => ($existing->raw_metadata[$key] ?? null) !== $value);
                $values = [
                    'payroll_period_id' => $period->id,
                    'import_batch_id' => $batch->id,
                    'productive_activity_seconds' => $row['productive_seconds'],
                    'unproductive_activity_seconds' => $row['unproductive_seconds'],
                    'measured_activity_seconds' => $row['measured_seconds'],
                    'activity_over_target_seconds' => $row['over_target_seconds'],
                    'raw_metadata' => [...$row['raw'], 'target_seconds_at_import' => $row['target_seconds']],
                    'imported_at' => now(),
                ];
                if ($existing) {
                    $existing->update($values);
                } else {
                    TrackabiDailyActivity::query()->create([
                        'employee_id' => $row['employee_id'],
                        'activity_date' => $date->toDateString(),
                        'source' => $this->provider->source(),
                        ...$values,
                    ]);
                }
                $written++;
                TrackingImportAlert::query()->where('payroll_period_id', $period->id)
                    ->where('code', 'TRACKABI_INSIGHTS_EMPLOYEE_UNMATCHED')
                    ->where('external_id', $this->unmatchedAlertId($date, $plan['file_hash'], $row['row_number']))
                    ->update(['resolved' => true]);
                TrackingImportAlert::query()->where('payroll_period_id', $period->id)
                    ->where('code', 'TRACKABI_ACTIVITY_MISSING')
                    ->where('external_id', 'activity:'.$row['employee_id'].':'.$date->toDateString())
                    ->update(['resolved' => true]);
                if ($review->status !== 'pendiente' || $review->verified_tracked_seconds !== null
                    || (int) $review->supervisor_adjustment_seconds !== 0
                    || (int) $review->justified_absence_seconds !== 0
                    || ($review->lost_time_source !== null
                        && ! in_array($review->lost_time_source, ['historical_estimate', 'trackabi_insights_activity', 'missing_activity', 'missing_timer'], true))
                    || $review->employee?->paid_without_tracking) {
                    if (! $review->employee?->paid_without_tracking
                        && $activityChanged) {
                        TrackingImportAlert::query()->updateOrCreate(
                            ['payroll_period_id' => $period->id, 'code' => 'TRACKABI_ACTIVITY_DATA_AFTER_REVIEW',
                                'external_id' => 'activity:'.$row['employee_id'].':'.$date->toDateString()],
                            ['source_provider' => $this->provider->source(),
                                'context' => ['employee' => $row['employee'], 'date' => $date->toDateString(),
                                    'review_id' => $review->id], 'resolved' => false],
                        );
                    }

                    continue;
                }

                if (! $review->paid_day_off && (int) $review->justified_absence_seconds === 0) {
                    $lostSeconds = $review->hasUntrackedDirectAbsence()
                        ? $review->untrackedDayReviewSeconds()
                        : $row['shortfall_seconds'];
                    $review->update([
                        'estimated_lost_seconds' => $lostSeconds,
                        'unjustified_absence_seconds' => $lostSeconds,
                        'supervisor_adjustment_seconds' => 0,
                        'lost_time_source' => 'trackabi_insights_activity',
                        'lost_time_estimate_metadata' => [
                            'activity_source' => $this->provider->source(), 'batch_id' => $batch->id,
                            'target_seconds' => $row['target_seconds'],
                            'measured_seconds' => $row['measured_seconds'],
                        ],
                    ]);
                    $affected[$row['employee_id']] = true;
                    $reviewsUpdated++;
                }
            }

            foreach ($plan['employees_unmatched'] as $unmatched) {
                TrackingImportAlert::query()->updateOrCreate(
                    ['payroll_period_id' => $period->id, 'code' => 'TRACKABI_INSIGHTS_EMPLOYEE_UNMATCHED',
                        'external_id' => $this->unmatchedAlertId($date, $plan['file_hash'], $unmatched['row'])],
                    ['source_provider' => $this->provider->source(),
                        'context' => ['date' => $date->toDateString(), ...$unmatched], 'resolved' => false],
                );
            }
            foreach (array_keys($affected) as $employeeId) {
                $this->payroll->recalculateEmployeePreservingManual($lockedPeriod, Employee::query()->findOrFail($employeeId));
            }

            return [...$plan, 'written' => $written, 'reviews_updated' => $reviewsUpdated];
        });
    }

    public function reviewNewActivity(DailyTimeReview $review, int $proposedLostSeconds, int $justifiedSeconds, ?string $comment): void
    {
        DB::transaction(function () use ($review, $proposedLostSeconds, $justifiedSeconds, $comment): void {
            $locked = DailyTimeReview::query()->lockForUpdate()->findOrFail($review->id);
            $period = PayrollPeriod::query()->findOrFail($locked->payroll_period_id);
            $this->assertPeriod($period, $locked->date);
            $activity = TrackabiDailyActivity::query()->where('employee_id', $locked->employee_id)
                ->whereDate('activity_date', $locked->date)->where('source', $this->provider->source())->firstOrFail();
            $alert = TrackingImportAlert::query()->where('payroll_period_id', $period->id)
                ->where('code', 'TRACKABI_ACTIVITY_DATA_AFTER_REVIEW')
                ->where('external_id', 'activity:'.$locked->employee_id.':'.$locked->date->toDateString())
                ->where('resolved', false)->lockForUpdate()->firstOrFail();
            $target = max((int) $locked->expected_hubstaff_seconds, 0);
            if ($locked->paid_day_off || $locked->employee?->paid_without_tracking
                || $proposedLostSeconds < 0 || $proposedLostSeconds > $target
                || $justifiedSeconds < 0 || $justifiedSeconds > $proposedLostSeconds) {
                throw new RuntimeException('La propuesta o justificación de actividad no es válida para este día.');
            }
            $baseShortfall = max($target - (int) $activity->measured_activity_seconds, 0);
            $locked->update([
                'estimated_lost_seconds' => $baseShortfall,
                'supervisor_adjustment_seconds' => $proposedLostSeconds - $baseShortfall,
                'justified_absence_seconds' => $justifiedSeconds,
                'unjustified_absence_seconds' => $proposedLostSeconds - $justifiedSeconds,
                'lost_time_source' => $proposedLostSeconds === $baseShortfall
                    ? 'trackabi_insights_activity' : 'supervisor_adjustment',
                'lost_time_estimate_metadata' => [
                    'activity_source' => $this->provider->source(),
                    'batch_id' => $activity->import_batch_id,
                    'target_seconds' => $target,
                    'measured_seconds' => (int) $activity->measured_activity_seconds,
                ],
                'supervisor_comment' => $comment,
                'status' => 'revisado_supervisor',
                'reviewed_by' => auth()->id(),
                'reviewed_at' => now(),
            ]);
            $this->payroll->recalculateDailyReview($locked);
            if ((int) $locked->fresh()->expected_hubstaff_seconds !== $target) {
                throw new RuntimeException('Cambió el horario durante la revisión. Actualiza el cálculo y revisa de nuevo.');
            }
            $this->payroll->recalculateEmployeePayrollResult($period, $locked->employee);
            $alert->update(['resolved' => true]);
        });
    }

    private function assertPeriod(PayrollPeriod $period, Carbon $date): void
    {
        if (! in_array($period->status, ['borrador', 'importado', 'en_revision'], true)) {
            throw new RuntimeException('Cannot import Trackabi activity into closed payroll period.');
        }
        $cutover = (string) config('trackabi_direct.cutover_date');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $cutover) || ! Carbon::hasFormat($cutover, 'Y-m-d')
            || $period->starts_at->toDateString() < $cutover || $date->toDateString() < $period->starts_at->toDateString()
            || $date->toDateString() > $period->ends_at->toDateString()) {
            throw new RuntimeException('La actividad solo puede importarse para un día de un período posterior al cutover.');
        }
        if (config('trackabi.provider') !== 'direct') {
            throw new RuntimeException('Trackabi Direct debe ser el proveedor activo.');
        }
    }

    private function headerPositions(array $header): array
    {
        $positions = [];
        foreach ($header as $index => $name) {
            $normalized = preg_replace('/[\s_]+/u', ' ', strtolower(trim((string) $name)));
            foreach (self::HEADERS as $key => $aliases) {
                if (in_array($normalized, $aliases, true)) {
                    if (isset($positions[$key])) {
                        throw new RuntimeException("Encabezado duplicado: {$key}.");
                    }
                    $positions[$key] = $index;
                }
            }
        }
        foreach (['productive', 'unproductive'] as $required) {
            if (! isset($positions[$required])) {
                throw new RuntimeException("Falta encabezado de actividad {$required} en el CSV.");
            }
        }
        if (! isset($positions['member']) && ! isset($positions['email']) && ! isset($positions['member_id'])) {
            throw new RuntimeException('Falta encabezado Member, Email o Member ID en el CSV.');
        }

        return $positions;
    }

    private function unmatchedAlertId(Carbon $date, string $hash, int $row): string
    {
        return 'insights:'.$date->toDateString().':'.substr($hash, 0, 16).':'.$row;
    }
}
