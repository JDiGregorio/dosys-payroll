<?php

use App\Models\DailyTimeReview;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Services\EmployeeScheduleTransitionService;
use App\Services\EmployeeTrainingHoursAdjustmentService;
use App\Services\JulySecondHalfPayrollCorrectionsService;
use App\Services\PalmettoDebtCollectionsScheduleCorrectionService;
use App\Services\PayrollCalculationService;
use App\Services\RotatingScheduleCorrectionService;
use App\Services\TimeParserService;
use Carbon\Carbon;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command(
    'payroll:recalculate-period {period_id : ID del período} {--preserve-manual : Confirma que deben preservarse campos manuales}',
    function (PayrollCalculationService $service): int {
        if (! $this->option('preserve-manual')) {
            $this->error('Por seguridad debes agregar --preserve-manual.');

            return self::FAILURE;
        }

        $period = PayrollPeriod::query()->find((int) $this->argument('period_id'));

        if (! $period) {
            $this->error('No existe el período indicado.');

            return self::FAILURE;
        }

        if ($period->status === 'cerrado') {
            $this->error('El período está cerrado y no será modificado.');

            return self::FAILURE;
        }

        try {
            $service->recalculatePeriodPreservingManual($period);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Cálculos actualizados. Se preservaron justificaciones, comentarios, bonos, deducciones, estados y aprobaciones.');

        return self::SUCCESS;
    },
)->purpose('Recalcula un período sin sobrescribir información manual.');

Artisan::command(
    'payroll:reconcile-justified-idle {--period= : ID del período de planilla} {--employee= : ID del empleado; opcional} {--include-pending : Incluye revisiones pendientes, útil para correcciones puntuales} {--apply : Aplica el ajuste; sin esta opción solo muestra vista previa}',
    function (PayrollCalculationService $service, TimeParserService $parser): int {
        $period = PayrollPeriod::query()->find((int) $this->option('period'));

        if (! $period) {
            $this->error('Debes indicar un período válido con --period=ID.');

            return self::FAILURE;
        }

        if ($period->status === 'cerrado') {
            $this->error('El período está cerrado y no será modificado.');

            return self::FAILURE;
        }

        $employee = null;
        $employeeOption = $this->option('employee');

        if ($employeeOption !== null && $employeeOption !== '') {
            $employee = Employee::query()->find((int) $employeeOption);

            if (! $employee) {
                $this->error('No existe el empleado indicado con --employee=ID.');

                return self::FAILURE;
            }
        }

        $includePending = (bool) $this->option('include-pending');
        $reviews = DailyTimeReview::query()
            ->with('employee')
            ->where('payroll_period_id', $period->id)
            ->when($employee, fn ($query) => $query->where('employee_id', $employee->id))
            ->where('hubstaff_total_seconds', '>', 0)
            ->where(function ($query) {
                $query
                    ->where('unjustified_idle_seconds', '>', 0)
                    ->orWhere('unjustified_absence_seconds', '>', 0);
            })
            ->orderBy('employee_id')
            ->orderBy('date')
            ->get()
            ->filter(function (DailyTimeReview $review) use ($includePending): bool {
                if (! $includePending && $review->status === 'pendiente') {
                    return false;
                }

                $requiredSeconds = (int) $review->expected_paid_seconds
                    ?: (int) $review->expected_seconds + (int) $review->assigned_overtime_seconds;
                $regularLostSeconds = max(
                    $requiredSeconds
                        - (int) $review->hubstaff_total_seconds
                        - (int) $review->paid_time_not_tracked_seconds,
                    0,
                );
                $roundedRegularLostSeconds = (int) round($regularLostSeconds / 60) * 60;
                $regularWillBeCovered = (int) $review->unjustified_absence_seconds <= 0
                    || (
                        (int) $review->justified_absence_seconds >= $roundedRegularLostSeconds
                        && (int) $review->justified_absence_seconds < $regularLostSeconds
                    );
                $idleWillBeCovered = (int) $review->unjustified_idle_seconds > 0
                    && $regularWillBeCovered;

                return ($regularWillBeCovered && (int) $review->unjustified_absence_seconds > 0)
                    || $idleWillBeCovered;
            })
            ->values();

        $rows = $reviews->map(function (DailyTimeReview $review) use ($parser): array {
            $requiredSeconds = (int) $review->expected_paid_seconds
                ?: (int) $review->expected_seconds + (int) $review->assigned_overtime_seconds;
            $regularLostSeconds = max(
                $requiredSeconds
                    - (int) $review->hubstaff_total_seconds
                    - (int) $review->paid_time_not_tracked_seconds,
                0,
            );

            return [
                'employee_id' => $review->employee_id,
                'employee' => $review->employee?->name,
                'date' => $review->date->toDateString(),
                'status' => $review->status,
                'regular_before' => $parser->secondsToHourMinuteSecond((int) $review->unjustified_absence_seconds),
                'regular_after' => (int) $review->unjustified_absence_seconds > 0
                    ? '0:00:00'
                    : $parser->secondsToHourMinuteSecond((int) $review->unjustified_absence_seconds),
                'idle_before' => $parser->secondsToHourMinute((int) $review->unjustified_idle_seconds),
                'idle_after' => (int) $review->unjustified_idle_seconds > 0
                    ? '0:00'
                    : $parser->secondsToHourMinute((int) $review->unjustified_idle_seconds),
                'regular_lost' => $regularLostSeconds,
            ];
        });

        $this->info("Período: {$period->name} ({$period->id})");
        $this->table(
            ['ID', 'Empleado', 'Fecha', 'Estado', 'Faltante antes', 'Faltante después', 'Idle antes', 'Idle después'],
            $rows->map(fn (array $row): array => [
                $row['employee_id'],
                $row['employee'],
                $row['date'],
                $row['status'],
                $row['regular_before'],
                $row['regular_after'],
                $row['idle_before'],
                $row['idle_after'],
            ])->all(),
        );

        if (! $this->option('apply')) {
            $this->warn('Vista previa únicamente. Agrega --apply para ejecutar la reconciliación.');

            return self::SUCCESS;
        }

        $affectedEmployees = $reviews->pluck('employee')->filter()->unique('id');

        foreach ($reviews as $review) {
            $requiredSeconds = (int) $review->expected_paid_seconds
                ?: (int) $review->expected_seconds + (int) $review->assigned_overtime_seconds;
            $regularLostSeconds = max(
                $requiredSeconds
                    - (int) $review->hubstaff_total_seconds
                    - (int) $review->paid_time_not_tracked_seconds,
                0,
            );
            $roundedRegularLostSeconds = (int) round($regularLostSeconds / 60) * 60;
            $regularWillBeCovered = (int) $review->unjustified_absence_seconds <= 0
                || (
                    (int) $review->justified_absence_seconds >= $roundedRegularLostSeconds
                    && (int) $review->justified_absence_seconds < $regularLostSeconds
                );

            if ($regularWillBeCovered && (int) $review->unjustified_absence_seconds > 0) {
                $review->justified_absence_seconds = $regularLostSeconds;
                $review->unjustified_absence_seconds = 0;
            }

            $review->justified_idle_seconds = min(
                max((int) $review->hubstaff_idle_seconds, 0),
                max((int) $review->justified_idle_seconds, 0) + max((int) $review->unjustified_idle_seconds, 0),
            );

            if ($regularWillBeCovered) {
                $review->unjustified_idle_seconds = 0;
            }

            $review->save();
        }

        foreach ($affectedEmployees as $affectedEmployee) {
            $service->recalculateEmployeePayrollResult($period, $affectedEmployee);
        }

        $this->info('Idle reconciliado y planilla de empleados afectados recalculada.');

        return self::SUCCESS;
    },
)->purpose('Reconcilia idle no justificado cuando el tiempo normal del día ya estaba cubierto por justificación.');

Artisan::command(
    'payroll:apply-edwin-cruz-training-hours {--period=4 : ID del período de planilla} {--employee=63 : ID del empleado} {--apply : Aplica el ajuste; sin esta opción solo muestra vista previa}',
    function (EmployeeTrainingHoursAdjustmentService $service): int {
        $period = PayrollPeriod::query()->find((int) $this->option('period'));
        $employee = Employee::query()->find((int) $this->option('employee'));

        if (! $period) {
            $this->error('Debes indicar un período válido con --period=ID.');

            return self::FAILURE;
        }

        if ($period->status === 'cerrado') {
            $this->error('El período está cerrado y no será modificado.');

            return self::FAILURE;
        }

        if (! $employee) {
            $this->error('Debes indicar un empleado válido con --employee=ID.');

            return self::FAILURE;
        }

        if ((int) $employee->id !== 63) {
            $this->error('Este comando está limitado al caso puntual de Edwin Cruz (employee_id=63).');

            return self::FAILURE;
        }

        try {
            $rows = $this->option('apply')
                ? $service->apply($period, $employee)
                : $service->preview($period, $employee);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Período: {$period->name} ({$period->id})");
        $this->info("Empleado: {$employee->name} ({$employee->id})");
        $this->table(
            ['Fecha', 'Tipo', 'Antes pagable', 'Después pagable', 'Regular', 'Extra', 'Justificado'],
            collect($rows)->map(fn (array $row): array => [
                $row['date'],
                $row['type'],
                $row['before_payable'],
                $row['after_payable'],
                $row['after_regular'],
                $row['after_overtime'],
                $row['justified'],
            ])->all(),
        );

        $this->info($this->option('apply')
            ? 'Ajuste aplicado y planilla del empleado recalculada.'
            : 'Vista previa únicamente. Agrega --apply para ejecutar el ajuste.');

        return self::SUCCESS;
    },
)->purpose('Aplica el ajuste puntual de entrenamiento/transición rotativa de Edwin Cruz para el período 26 junio - 10 julio.');

Artisan::command(
    'payroll:apply-july-second-half-corrections {--period=5 : ID del período de planilla} {--apply : Aplica el ajuste; sin esta opción solo muestra vista previa}',
    function (JulySecondHalfPayrollCorrectionsService $service): int {
        $period = PayrollPeriod::query()->find((int) $this->option('period'));

        if (! $period) {
            $this->error('Debes indicar un período válido con --period=ID.');

            return self::FAILURE;
        }

        try {
            $rows = $this->option('apply')
                ? $service->apply($period)
                : $service->preview($period);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Período: {$period->name} ({$period->id})");
        $this->table(
            ['ID', 'Empleado', 'Acción', 'Antes', 'Después'],
            collect($rows)->map(fn (array $row): array => [
                $row['employee_id'],
                $row['employee'],
                $row['action'],
                $row['before'],
                $row['after'],
            ])->all(),
        );

        $this->info($this->option('apply')
            ? 'Ajustes aplicados y planilla de empleados afectados recalculada.'
            : 'Vista previa únicamente. Agrega --apply para ejecutar el ajuste.');

        return self::SUCCESS;
    },
)->purpose('Aplica ajustes puntuales de Priscila, Dereck y Edwin para la segunda quincena de julio 2026.');

Artisan::command(
    'payroll:apply-employee-schedule-transition {--period= : ID del período de planilla} {--employee=Elalf Shamir Dominguez Pineda : Nombre exacto o prefijo del empleado} {--rotative-start=2026-06-11 : Primera fecha bajo jornada rotativa} {--rotative-end=2026-06-13 : Última fecha bajo jornada rotativa} {--diurnal-start=2026-06-14 : Primera fecha bajo jornada diurna} {--apply : Aplica la transición; sin esta opción solo muestra vista previa}',
    function (EmployeeScheduleTransitionService $service): int {
        $period = PayrollPeriod::query()->find((int) $this->option('period'));

        if (! $period) {
            $this->error('Debes indicar un período válido con --period=ID.');

            return self::FAILURE;
        }

        try {
            $row = $this->option('apply')
                ? $service->apply(
                    $period,
                    (string) $this->option('employee'),
                    Carbon::parse($this->option('rotative-start')),
                    Carbon::parse($this->option('rotative-end')),
                    Carbon::parse($this->option('diurnal-start')),
                )
                : $service->preview(
                    $period,
                    (string) $this->option('employee'),
                    Carbon::parse($this->option('rotative-start')),
                    Carbon::parse($this->option('rotative-end')),
                    Carbon::parse($this->option('diurnal-start')),
                );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['Período', 'Empleado', 'Rotativa desde', 'Rotativa hasta', 'Diurna desde', 'Jornada actual previa', 'Plantilla actual previa'],
            [[
                "{$row['period']} ({$row['period_id']})",
                "{$row['employee']} ({$row['employee_id']})",
                $row['rotative_start'],
                $row['rotative_end'],
                $row['diurnal_start'],
                $row['current_schedule'],
                $row['current_template'],
            ]],
        );

        $this->info($this->option('apply')
            ? 'Transición aplicada y período recalculado preservando información manual.'
            : 'Vista previa únicamente. Agrega --apply para ejecutar la transición.');

        return self::SUCCESS;
    },
)->purpose('Aplica una transición de jornada por fechas para un empleado y recalcula preservando información manual.');

Artisan::command(
    'payroll:apply-period-corrections {--period= : ID del período de planilla} {--apply : Aplica las correcciones; sin esta opción solo muestra una vista previa}',
    function (RotatingScheduleCorrectionService $service): int {
        $periodId = (int) $this->option('period');
        $period = PayrollPeriod::query()->find($periodId);

        if (! $period) {
            $this->error('Debes indicar un período válido con --period=ID.');

            return self::FAILURE;
        }

        if ($period->status === 'cerrado') {
            $this->error('El período está cerrado y no será modificado.');

            return self::FAILURE;
        }

        try {
            $rows = $service->preview($period);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Período: {$period->name} ({$period->id})");
        $this->table(
            ['ID', 'Empleado', 'Inicio ciclo', 'Revisiones', 'Revisadas que se preservarán'],
            collect($rows)->map(fn (array $row): array => [
                $row['employee_id'],
                $row['name'],
                $row['anchor_date'],
                $row['reviews'],
                $row['reviewed'],
            ])->all(),
        );

        if (! $this->option('apply')) {
            $this->warn('Vista previa únicamente. Agrega --apply para ejecutar la corrección.');

            return self::SUCCESS;
        }

        try {
            $service->apply($period);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Correcciones aplicadas: jornada rotativa y distribución flexible de horas extra del período.');

        return self::SUCCESS;
    },
)->purpose('Aplica la jornada rotativa y la distribución flexible de horas extra preservando revisiones existentes.');

Artisan::command(
    'payroll:apply-palmetto-36h-schedules {--period= : ID del período de planilla} {--apply : Aplica la corrección; sin esta opción solo muestra una vista previa} {--skip-uninferred : Aplica solo empleados con día de 8 horas inferido y deja pendientes los no inferidos}',
    function (PalmettoDebtCollectionsScheduleCorrectionService $service): int {
        $periodId = (int) $this->option('period');
        $period = PayrollPeriod::query()->find($periodId);

        if (! $period) {
            $this->error('Debes indicar un período válido con --period=ID.');

            return self::FAILURE;
        }

        if ($period->status === 'cerrado') {
            $this->error('El período está cerrado y no será modificado.');

            return self::FAILURE;
        }

        try {
            $rows = $service->preview($period);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Período: {$period->name} ({$period->id})");
        $this->table(
            ['ID', 'Empleado', 'Plantilla actual', 'Día 8h inferido', 'Plantilla nueva', 'Revisiones', 'Revisadas que se preservarán'],
            collect($rows)->map(fn (array $row): array => [
                $row['employee_id'],
                $row['name'],
                $row['current_template'],
                $row['eight_hour_weekday'],
                $row['template'],
                $row['reviews'],
                $row['reviewed'],
            ])->all(),
        );

        if (! $this->option('apply')) {
            $this->warn('Vista previa únicamente. Agrega --apply para ejecutar la corrección.');

            return self::SUCCESS;
        }

        try {
            $service->apply($period, (bool) $this->option('skip-uninferred'));
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Corrección aplicada: plantillas 36h PALMETTO / DEBT COLLECTIONS y recálculo preservando información manual.');

        return self::SUCCESS;
    },
)->purpose('Asigna plantillas 36h variables a PALMETTO / DEBT COLLECTIONS preservando revisiones existentes.');
