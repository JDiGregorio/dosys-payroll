<?php

use App\Models\PayrollPeriod;
use App\Services\EmployeeScheduleTransitionService;
use App\Services\PalmettoDebtCollectionsScheduleCorrectionService;
use App\Services\PayrollCalculationService;
use App\Services\RotatingScheduleCorrectionService;
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
