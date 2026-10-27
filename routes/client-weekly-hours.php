<?php

use App\Services\Reports\ClientWeeklyHoursExporter;
use App\Services\Reports\ClientWeeklyHoursReportService;
use Illuminate\Support\Facades\Artisan;

Artisan::command('payroll:export-client-weekly-hours
    {--from=2026-09-21 : Inicio del primer reporte}
    {--to=2026-10-10 : Fin del primer reporte}
    {--output=storage/app/reports : Directorio local de salida}
    {--dry-run : Calcula y audita sin crear archivos}
    {--draft : Permite exportar con advertencias, marcando los archivos como borrador}', function (
    ClientWeeklyHoursReportService $builder,
    ClientWeeklyHoursExporter $exporter,
): int {
    $from = (string) $this->option('from');
    $to = (string) $this->option('to');
    if ($from !== '2026-09-21' || $to !== '2026-10-10') {
        $this->error('Esta primera entrega está limitada al 2026-09-21 a 2026-10-10.');

        return self::FAILURE;
    }

    $report = $builder->build($from, $to);
    $this->line("Rango: {$from} a {$to}; semanas: ".count($report['weeks']));
    $this->line('Campañas: '.count($report['campaigns']).'; empleados: '.$report['employee_count'].'; empleado-semanas: '.$report['employee_weeks']);
    $this->line('Grand Total Client Hours: '.$exporter::hours($report['grand_total_seconds']));
    $this->table(['Campaña', 'Empleados', 'Horas facturables'], array_map(fn (array $campaign): array => [
        $campaign['name'], $campaign['employees'], $exporter::hours($campaign['total_seconds']),
    ], $report['campaigns']));
    $this->table(['Semana', 'Horas facturables'], array_map(fn (array $week): array => [
        $week['start'].' - '.$week['end'], $exporter::hours($week['total_seconds']),
    ], $report['weeks']));
    $this->line('Advertencias: '.count($report['warnings']).'.');
    $this->table(['Código', 'Cantidad'], collect($report['warnings'])->countBy('code')
        ->map(fn (int $count, string $code): array => [$code, $count])->values()->all());
    if ($report['excluded']) {
        $this->table(['Empleado excluido', 'Razón'], array_map(fn (array $row): array => [
            $row['employee'], $row['reason'],
        ], $report['excluded']));
    }
    $this->line('SAFE TO EXPORT FINAL: '.($report['safe_to_send_client'] ? 'YES' : 'NO'));

    if ($this->option('dry-run')) {
        $this->info('Dry-run: no se modificaron datos ni se crearon archivos.');

        return self::SUCCESS;
    }
    if (! $report['safe_to_send_client'] && ! $this->option('draft')) {
        $this->error('Export final bloqueado. Usa --draft para revisar PDF/XLSX sin considerarlos definitivos.');

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
})->purpose('Exporta el reporte semanal de horas facturables al cliente en PDF y XLSX, solo lectura.');
