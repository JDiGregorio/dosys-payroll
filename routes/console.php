<?php

use App\Models\PayrollPeriod;
use App\Services\RotatingScheduleCorrectionService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

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
            ['ID', 'Empleado', 'Inicio ciclo', 'Revisiones', 'Revisadas que se reiniciarán'],
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

        $this->info('Correcciones aplicadas: jornada 4x4 y distribución flexible de horas extra del período.');

        return self::SUCCESS;
    },
)->purpose('Aplica la jornada 4x4 y la distribución flexible de horas extra preservando revisiones existentes.');
