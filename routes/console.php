<?php

use App\Models\PayrollPeriod;
use App\Services\PayrollCalculationService;
use App\Services\RotatingScheduleCorrectionService;
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
