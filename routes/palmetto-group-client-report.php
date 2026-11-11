<?php

use App\Services\Reports\ClientWeeklyHoursExporter;
use App\Services\Reports\PalmettoGroupClientReportService;
use Illuminate\Support\Facades\Artisan;

Artisan::command('payroll:export-palmetto-group-hours
    {--output=storage/app/reports : Directorio local de salida}
    {--dry-run : Calcula y audita sin crear archivos}
    {--draft : Exporta borradores si hay advertencias bloqueantes}', function (
    PalmettoGroupClientReportService $builder,
    ClientWeeklyHoursExporter $exporter,
): int {
    $reports = [1 => $builder->build(1), 2 => $builder->build(2)];
    foreach ($reports as $group => $report) {
        $this->line('PALMETTO '.$group.' | '.$report['from'].' a '.$report['to']
            .' | semanas '.count($report['weeks']).' | empleados '.$report['employee_count']
            .' | horas '.$exporter::hours($report['grand_total_seconds']));
        $this->line('Advertencias: '.count($report['warnings'])
            .'; bloqueantes: '.count($report['blocking_warnings']).'.');
        $this->table(['Código', 'Cantidad'], collect($report['warnings'])->countBy('code')
            ->map(fn (int $count, string $code): array => [$code, $count])->values()->all());
        foreach ($report['blocking_warnings'] as $warning) {
            $this->warn($warning['date'].' | '.$warning['employee'].' | '.$warning['code']);
        }
    }
    if ($this->option('dry-run')) {
        $this->info('Dry-run: sin archivos ni cambios en base de datos.');

        return self::SUCCESS;
    }
    if (! $this->option('draft') && collect($reports)->contains(fn (array $report): bool => ! $report['safe_to_send_client'])) {
        $this->error('Hay advertencias bloqueantes; solo se permite exportar borrador.');

        return self::FAILURE;
    }
    $directory = (string) $this->option('output');
    $directory = str_starts_with($directory, '/') ? $directory : base_path($directory);
    foreach ($reports as $group => $report) {
        $files = $exporter->exportSingleCampaign($report, $directory, (bool) $this->option('draft'));
        $this->info('PALMETTO '.$group.' PDF: '.$files['pdf']);
        $this->info('PALMETTO '.$group.' XLSX: '.$files['xlsx']);
    }

    return self::SUCCESS;
})->purpose('Genera dos reportes semanales históricos de Palmetto por supervisor, solo lectura.');
