<?php

use App\Services\Reports\ClientWeeklyHoursCurrentPeriodService;
use App\Services\Reports\ClientWeeklyHoursExporter;
use Illuminate\Support\Facades\Artisan;

Artisan::command('payroll:export-client-weekly-hours-current
    {--csv-dir=storage/app/trackabi/client-report-input-2026-10-26_2026-11-01 : Siete CSV diarios de Insights}
    {--output=storage/app/reports : Directorio local de salida}
    {--dry-run : Calcula y audita sin crear archivos}
    {--draft : Permite exportar con advertencias bloqueantes}', function (
    ClientWeeklyHoursCurrentPeriodService $builder,
    ClientWeeklyHoursExporter $exporter,
): int {
    $csvDir = (string) $this->option('csv-dir');
    $csvDir = str_starts_with($csvDir, '/') ? $csvDir : base_path($csvDir);
    $report = $builder->build($csvDir);
    $this->line('Rango: '.$report['from'].' a '.$report['to'].'; semanas: '.count($report['weeks']));
    $this->table(['Campaña', 'Empleados', 'Horas facturables'], array_map(fn (array $campaign): array => [
        $campaign['name'], $campaign['employees'], $exporter::hours($campaign['total_seconds']),
    ], $report['campaigns']));
    $this->table(['Semana', 'Horas facturables'], array_map(fn (array $week): array => [
        $week['start'].' - '.$week['end'], $exporter::hours($week['total_seconds']),
    ], $report['weeks']));
    $this->line('Advertencias: '.count($report['warnings']).'; bloqueantes: '.count($report['blocking_warnings']).'.');
    $this->table(['Código', 'Cantidad'], collect($report['warnings'])->countBy('code')
        ->map(fn (int $count, string $code): array => [$code, $count])->values()->all());
    foreach ($report['blocking_warnings'] as $warning) {
        $this->warn($warning['date'].' | '.$warning['employee'].' | '.$warning['code'].' | '.$warning['message']);
    }
    $this->line('SAFE TO EXPORT FINAL: '.($report['safe_to_send_client'] ? 'YES' : 'NO'));

    if ($this->option('dry-run')) {
        return self::SUCCESS;
    }
    if (! $report['safe_to_send_client'] && ! $this->option('draft')) {
        $this->error('Hay advertencias bloqueantes; solo se permite exportar borrador.');

        return self::FAILURE;
    }
    $directory = (string) $this->option('output');
    $directory = str_starts_with($directory, '/') ? $directory : base_path($directory);
    $files = $exporter->export($report, $directory, (bool) $this->option('draft'));
    $this->info('PDF: '.$files['pdf']);
    foreach ($files['campaign_pdfs'] as $campaign => $path) {
        $this->info('PDF '.$campaign.': '.$path);
    }
    $this->info('XLSX: '.$files['xlsx']);

    return self::SUCCESS;
})->purpose('Reporte cliente 12/oct-01/nov: dos semanas revisadas y una semana Insights solo lectura.');
