<?php

use App\Models\Campaign;
use App\Models\Employee;
use App\Models\HubstaffTimeEntry;
use App\Models\PayrollPeriod;
use App\Models\TrackabiProjectMapping;
use App\Services\TimeParserService;
use App\Services\Trackabi\TrackabiDirectApiClient;
use App\Services\Trackabi\TrackabiDirectApiException;
use App\Services\Trackabi\TrackabiCoverageReportService;
use App\Services\Trackabi\TrackabiDirectDiscovery;
use App\Services\Trackabi\TrackabiDirectImportService;
use App\Services\Trackabi\TrackabiDirectTimeEntryValidator;
use App\Services\Trackabi\TrackabiInsightsCsvImportService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;

Artisan::command('trackabi:sync-members {--dry-run : Vista previa sin escrituras} {--apply : Enlazar únicamente correos únicos confirmados}', function (TrackabiDirectApiClient $client): int {
    if ($this->option('dry-run') && $this->option('apply')) {
        $this->error('Usa --dry-run o --apply, no ambos.');

        return self::FAILURE;
    }
    $members = collect();
    try {
        for ($page = 1; $page <= 100; $page++) {
            $response = $client->get('/api/v1/members', ['page' => $page, 'page_size' => 100]);
            $items = array_is_list($response) ? $response : ($response['data'] ?? []);
            if (! is_array($items) || ! array_is_list($items)) {
                throw new \RuntimeException('Formato de miembros Trackabi no reconocido.');
            }
            $members = $members->concat($items);
            if (count($items) < 100) {
                break;
            }
            if ($page === 100) {
                throw new \RuntimeException('Paginación de miembros incompleta; no se enlazará a nadie.');
            }
        }
    } catch (\Throwable $exception) {
        $this->error($exception->getMessage());

        return self::FAILURE;
    }

    $employees = Employee::query()->where('active', true)->get();
    $emailCounts = $members->countBy(fn ($member) => strtolower(trim((string) ($member['email'] ?? ''))));
    $counts = ['exact' => 0, 'linked' => 0, 'unmatched' => 0, 'ambiguous' => 0, 'exempt' => 0];
    $links = [];
    foreach ($members as $member) {
        $id = filter_var($member['id'] ?? null, FILTER_VALIDATE_INT);
        $email = strtolower(trim((string) ($member['email'] ?? '')));
        $name = trim((string) ($member['first_name'] ?? '').' '.(string) ($member['last_name'] ?? ''));
        $matches = $email === '' ? collect() : $employees->filter(fn (Employee $employee) =>
            strtolower(trim((string) $employee->email)) === $email
            || strtolower(trim((string) $employee->trackabi_email)) === $email);
        $claimed = $id ? $employees->filter(fn (Employee $employee) => (int) $employee->trackabi_member_id === $id) : collect();
        if ($matches->count() === 1 && $matches->first()->paid_without_tracking) {
            $status = 'EXEMPT FROM TRACKING';
            $counts['exempt']++;
        } elseif ($matches->count() > 1 || $claimed->count() > 1 || ($id && $claimed->count() === 1
            && $matches->count() === 1 && $claimed->first()->id !== $matches->first()->id)
            || ($id && $matches->count() === 1 && $matches->first()->trackabi_member_id
                && (int) $matches->first()->trackabi_member_id !== $id)
            || ($email !== '' && $emailCounts[$email] > 1)) {
            $status = 'AMBIGUOUS';
            $counts['ambiguous']++;
        } elseif (! $id || $email === '' || $matches->count() !== 1) {
            $status = 'UNMATCHED';
            $counts['unmatched']++;
        } else {
            $employee = $matches->first();
            $alreadyLinked = (int) $employee->trackabi_member_id === $id
                && strtolower(trim((string) $employee->trackabi_email)) === $email;
            $status = $alreadyLinked ? 'ALREADY LINKED' : 'EXACT EMAIL';
            $counts[$alreadyLinked ? 'linked' : 'exact']++;
            if (! $alreadyLinked) {
                $links[] = [$employee, $id, $email, trim((string) ($member['last_name'] ?? '')).', '.trim((string) ($member['first_name'] ?? ''))];
            }
        }
        $this->line("#{$id} {$name} | ".($matches->count() === 1 ? $matches->first()->name : '-')." | {$status}");
    }
    if ($this->option('apply')) {
        foreach ($links as [$employee, $id, $email, $name]) {
            $employee->update([
                'trackabi_member_id' => $id,
                'trackabi_email' => $email,
                'trackabi_name' => $employee->trackabi_name ?: $name,
            ]);
        }
    }
    $this->info('Trackabi members: '.$members->count().'; Exact email: '.$counts['exact']
        .'; Already linked: '.$counts['linked'].'; Unmatched: '.$counts['unmatched']
        .'; Ambiguous: '.$counts['ambiguous'].'; Exempt: '.$counts['exempt']
        .'; enlazados: '.($this->option('apply') ? count($links) : 0));

    return self::SUCCESS;
})->purpose('Vista previa de miembros Trackabi; --apply enlaza solo correos únicos.');

Artisan::command('trackabi:coverage {--period= : ID del período}', function (TrackabiCoverageReportService $coverage): int {
    $periodId = filter_var($this->option('period'), FILTER_VALIDATE_INT);
    $period = $periodId ? PayrollPeriod::query()->find($periodId) : null;
    if (! $period) {
        $this->error('Indica un período existente con --period=ID.');

        return self::FAILURE;
    }

    try {
        $report = $coverage->report($period);
    } catch (Throwable $exception) {
        $this->error($exception->getMessage());

        return self::FAILURE;
    }

    $days = $report['days'];
    $this->line("Período: #{$period->id} {$period->starts_at->toDateString()} a {$period->ends_at->toDateString()}");
    $this->line("Días calendario: {$report['calendar_days']}");
    $this->line("Empleados activos: {$report['payroll_employees']}; requieren Trackabi: {$report['tracking_required']}; exentos: {$report['tracking_exempt']}");
    $this->line("Empleados con entradas utilizables: {$report['trackabi_employees_found']}; sin entradas utilizables: ".count($report['missing_employees']));
    foreach ($report['missing_employees'] as $employee) {
        $this->line("  #{$employee['id']} {$employee['name']}");
    }
    $this->line('Miembros Trackabi sin mapeo: '.count($report['unmatched_members']));
    $this->line("Revisiones diarias: {$report['daily_reviews']}; días programados: {$days['scheduled_work']}; OFF: {$days['scheduled_off']}");
    $this->line("Días con entradas Direct: {$days['with_time_entries']}; actividad disponible: {$days['activity_available']}; cero explícito: {$days['activity_zero']}; actividad faltante: {$days['activity_missing']}");
    $this->line("Ausencias justificadas: {$days['justified_absence']}; ausencias no justificadas confirmadas: {$days['confirmed_unjustified_absence']}");
    $this->line("Días pagables equivalentes en {$report['payroll_results']} resultados existentes: {$report['payable_day_equivalents']} (provisionales si falta Insights)");
    foreach ($report['unmapped_projects'] as $project) {
        $this->line('Proyecto sin mapeo: ID '.($project['project_id'] ?? 'NULL').' / '.($project['project_name'] ?? 'Sin proyecto')."; entradas: {$project['entries']}");
    }
    foreach ($report['suspicious_reasons'] as $reason => $count) {
        $this->line("Tiempo sospechoso {$reason}: {$count}");
    }

    return self::SUCCESS;
})->purpose('Reporte de cobertura Trackabi de solo lectura para un período abierto.');

Artisan::command('trackabi:map-insights-member {name : Nombre exacto de la fila CSV} {--employee= : ID interno del empleado}', function (): int {
    $name = trim((string) $this->argument('name'));
    $employeeId = filter_var($this->option('employee'), FILTER_VALIDATE_INT);
    if ($name === '' || strlen($name) > 255 || ! $employeeId || $employeeId < 1) {
        $this->error('Indica el nombre exacto del CSV y --employee=ID.');

        return self::FAILURE;
    }
    $employee = Employee::query()->where('active', true)->find($employeeId);
    if (! $employee) {
        $this->error('El ID no corresponde a un empleado activo.');

        return self::FAILURE;
    }
    $existing = Employee::query()->where('trackabi_name', $name)->where('id', '!=', $employee->id)->first();
    if ($existing) {
        $this->error('Ese nombre ya está mapeado a otro empleado. Revisa el mapeo existente.');

        return self::FAILURE;
    }
    $employee->update(['trackabi_name' => $name]);
    $this->info("Nombre '{$name}' mapeado a empleado #{$employee->id} ({$employee->name}).");

    return self::SUCCESS;
})->purpose('Mapea explícitamente un nombre exacto del CSV Insights a un empleado existente.');

Artisan::command('trackabi:import-insights {file : CSV oficial de Insights Staff Summary} {--date= : Fecha laboral YYYY-MM-DD} {--period= : ID del período; opcional si la fecha identifica uno solo} {--dry-run : Vista previa sin escrituras}', function (TrackabiInsightsCsvImportService $service, TimeParserService $parser): int {
    $date = (string) $this->option('date');
    if (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) || ! Carbon::hasFormat($date, 'Y-m-d')) {
        $this->error('Indica --date=YYYY-MM-DD.');

        return self::FAILURE;
    }
    $periods = PayrollPeriod::query()->whereDate('starts_at', '<=', $date)->whereDate('ends_at', '>=', $date)
        ->when($this->option('period'), fn ($query) => $query->whereKey((int) $this->option('period')))->get();
    if ($periods->count() !== 1) {
        $this->error('La fecha debe identificar un solo período; indica --period=ID si hace falta.');

        return self::FAILURE;
    }

    try {
        $report = $this->option('dry-run')
            ? $service->preview($periods->first(), Carbon::parse($date), (string) $this->argument('file'))
            : $service->import($periods->first(), Carbon::parse($date), (string) $this->argument('file'));
    } catch (Throwable $exception) {
        $this->error($exception->getMessage());

        return self::FAILURE;
    }

    $this->line("Fecha: {$report['date']}; filas: {$report['rows_count']}; encontrados: {$report['employees_matched']}; sin mapeo: ".count($report['employees_unmatched']));
    $this->line('Productiva: '.$parser->secondsToHourMinuteSecond($report['productive_total_seconds'])
        .'; improductiva: '.$parser->secondsToHourMinuteSecond($report['unproductive_total_seconds'])
        .'; medida: '.$parser->secondsToHourMinuteSecond($report['measured_total_seconds']));
    $this->line("Crear: {$report['would_insert']}; actualizar: {$report['would_update']}; omitir: {$report['would_skip']}; revisiones por crear: {$report['would_create_reviews']}.");
    foreach ($report['employees_unmatched'] as $row) {
        $this->warn("Sin mapeo fila {$row['row']}: {$row['member']}");
    }
    foreach ($report['errors'] as $error) {
        $this->error($error);
    }
    if (! $this->option('dry-run')) {
        $this->line("Actividad escrita: {$report['written']}; revisiones pendientes actualizadas: {$report['reviews_updated']}.");
    }

    return $report['errors'] === [] ? self::SUCCESS : self::FAILURE;
})->purpose('Importa un CSV oficial de Trackabi Insights para una sola fecha, sin MindCloud.');

Artisan::command('trackabi:inspect-time-entry {id : ID oficial de Trackabi}', function (TrackabiDirectApiClient $client, TrackabiDirectTimeEntryValidator $validator): int {
    $id = (string) $this->argument('id');
    if (! ctype_digit($id) || (int) $id < 1) {
        $this->error('Indica un ID numérico positivo.');

        return self::FAILURE;
    }

    try {
        $entry = $client->get('/api/v1/time-entry/'.$id);
        if (! isset($entry['id']) || (string) $entry['id'] !== $id) {
            throw new RuntimeException('Trackabi no confirmó el ID solicitado.');
        }
        $this->line(json_encode($client->sanitize($validator->sanitizedEntry($entry)), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    } catch (Throwable $exception) {
        $this->error($client->sanitize($exception->getMessage()));

        return self::FAILURE;
    }

    return self::SUCCESS;
})->purpose('Inspecciona un time entry por ID con salida sanitizada y sin modificar datos.');

Artisan::command('trackabi:audit-times {--from= : Inicio YYYY-MM-DD} {--to= : Fin YYYY-MM-DD}', function (TrackabiDirectImportService $importer): int {
    $from = (string) $this->option('from');
    $to = (string) $this->option('to');
    if (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $from) || ! Carbon::hasFormat($from, 'Y-m-d')
        || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $to) || ! Carbon::hasFormat($to, 'Y-m-d')
        || $from > $to || Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 31) {
        $this->error('Indica un rango válido YYYY-MM-DD de hasta 31 días.');

        return self::FAILURE;
    }

    try {
        $report = $importer->audit(Carbon::parse($from), Carbon::parse($to));
    } catch (Throwable $exception) {
        $this->error($exception->getMessage());

        return self::FAILURE;
    }

    $this->line("Entradas analizadas: {$report['entries_scanned']}; hallazgos: ".count($report['findings']));
    $this->table(['ID', 'Empleado', 'Fecha', 'Logged raw', 'Inicio', 'Fin', 'Proyecto', 'Hallazgos'],
        array_map(fn ($row): array => [
            $row['entry_id'], $row['employee'], $row['date_logged'],
            is_scalar($row['raw_logged_time']) ? (string) $row['raw_logged_time'] : 'N/D',
            $row['start_time'] ?? 'N/D', $row['end_time'] ?? 'N/D', $row['project'] ?? 'N/D',
            implode(', ', $row['issues']),
        ], $report['findings']));
    $this->line('Solo lectura: no se modificaron registros ni planillas.');

    return self::SUCCESS;
})->purpose('Audita anomalías de time entries Trackabi en un rango sin escribir en la base.');

Artisan::command('trackabi:api-test', function (TrackabiDirectApiClient $client): int {
    try {
        $client->assertConfigured();
        $client->get('/api/v1/company/profile');
        $this->info('Trackabi API ........ OK');
        $this->info('Authentication ...... OK');
    } catch (TrackabiDirectApiException $exception) {
        $this->error($exception->getMessage());

        return self::FAILURE;
    }
    try {
        $resources = $client->get('/api/v1/resources');
        $this->info('Resources endpoint .. OK');
        $items = $resources['resources'] ?? $resources['data'] ?? $resources;
        $this->line('Resources found ..... '.(is_array($items) && array_is_list($items) ? count($items) : 'Formato no confirmado'));
    } catch (TrackabiDirectApiException $exception) {
        if ($exception->httpStatus !== 404) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->warn('Resources endpoint .. No disponible (404)');
        $this->line('Resources found ..... N/A. Usa trackabi:discover con el OpenAPI oficial.');
    }

    return self::SUCCESS;
})->purpose('Comprueba la API oficial de Trackabi sin usar MindCloud ni modificar datos.');

Artisan::command('trackabi:discover', function (TrackabiDirectDiscovery $discovery): int {
    try {
        $report = $discovery->discover();
    } catch (TrackabiDirectApiException $exception) {
        $this->error($exception->getMessage());

        return self::FAILURE;
    }
    $this->info('OpenAPI Trackabi: '.$report['version']);
    $this->table(['GET publicado / resources solicitado', 'Acceso con esta clave', 'Campos observados (sin valores)'],
        array_map(fn ($row) => array_values($row), $report['rows']));
    $this->table(['Métrica', 'Estado', 'Endpoint GET', 'Campos', 'Filtros / paginación'],
        array_map(fn ($row) => array_values($row), $report['capabilities']));
    if ($report['activity_paths'] === []) {
        $this->warn('Activity / Insights / Productive / Unproductive / Apps: no hay rutas GET publicadas en este OpenAPI.');
    } else {
        $this->warn('Candidatos publicados; requieren confirmar respuesta y semantica antes de crear un provider:');
        foreach ($report['activity_paths'] as $path) {
            $this->line($path);
        }
    }
    if ($report['stored_path']) {
        $this->line('Diagnostico sanitizado guardado en disco local privado: '.$report['stored_path']);
    }
    $this->line('No se modificaron empleados, revisiones ni planillas.');

    return collect($report['rows'])->contains(fn ($row) => $row['status'] === 'Accesible (2xx)')
        ? self::SUCCESS : self::FAILURE;
})->purpose('Descubre y prueba rutas GET oficiales; nunca consulta endpoints de actividad inventados.');

Artisan::command('trackabi:map-project {--project-id= : ID oficial de Trackabi} {--campaign= : Nombre de campaña interna}', function (TrackabiDirectApiClient $client): int {
    $projectId = filter_var($this->option('project-id'), FILTER_VALIDATE_INT);
    $campaignName = trim((string) $this->option('campaign'));
    if (! $projectId || $campaignName === '') {
        $this->error('Indica --project-id=ID y --campaign="Nombre".');

        return self::FAILURE;
    }

    $campaign = Campaign::query()->whereRaw('lower(name) = ?', [strtolower($campaignName)])->first();
    if (! $campaign) {
        $this->error('La campaña interna no existe.');

        return self::FAILURE;
    }

    try {
        $project = $client->get('/api/v1/projects/'.$projectId);
        if ((int) ($project['id'] ?? 0) !== $projectId) {
            throw new RuntimeException('Trackabi no confirmó el ID del proyecto solicitado.');
        }
        TrackabiProjectMapping::query()->updateOrCreate(
            ['trackabi_project_id' => $projectId],
            ['campaign_id' => $campaign->id, 'trackabi_project_name' => $project['name'] ?? null, 'active' => true],
        );
    } catch (Throwable $exception) {
        $this->error($exception->getMessage());

        return self::FAILURE;
    }

    $this->info("Proyecto #{$projectId} mapeado a {$campaign->name}.");

    return self::SUCCESS;
})->purpose('Vincula un ID de proyecto Trackabi confirmado con una campaña interna.');

Artisan::command('trackabi:inspect-activity {--employee= : Correo Trackabi} {--date= : Fecha YYYY-MM-DD}', function (TrackabiDirectApiClient $client): int {
    $email = strtolower(trim((string) $this->option('employee')));
    $date = (string) $this->option('date');
    if (! filter_var($email, FILTER_VALIDATE_EMAIL) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
        || ! Carbon::hasFormat($date, 'Y-m-d')) {
        $this->error('Indica --employee=email@domain.com y --date=YYYY-MM-DD.');

        return self::FAILURE;
    }

    try {
        $entries = [];
        $pageSize = 100;
        for ($page = 1; $page <= 100; $page++) {
            $batch = $client->get('/api/v1/time-entries', [
                'start_date' => $date, 'end_date' => $date, 'email' => $email,
                'page' => $page, 'page_size' => $pageSize,
            ]);
            if (! array_is_list($batch)) {
                throw new RuntimeException('Trackabi devolvió un formato inesperado.');
            }
            array_push($entries, ...$batch);
            if (count($batch) < $pageSize) {
                break;
            }
            if ($page === 100) {
                throw new RuntimeException('Se alcanzó el límite de páginas del diagnóstico.');
            }
        }
    } catch (Throwable $exception) {
        $this->error($exception->getMessage());

        return self::FAILURE;
    }

    $seconds = array_sum(array_map(fn (array $entry): int => app(TimeParserService::class)->parseToSeconds($entry['logged_time'] ?? null), $entries));
    $this->line("Miembro: {$email}; fecha: {$date}; entradas: ".count($entries));
    $this->line('Logged time: '.app(TimeParserService::class)->secondsToHourMinuteSecond($seconds));
    foreach (['Desktop/User Activity', 'Productive Activity', 'Unproductive Activity', 'Activity Score', 'Top apps', 'URLs', 'Timeline'] as $metric) {
        $this->line($metric.': NOT EXPOSED BY PUBLIC API (NULL)');
    }

    return self::SUCCESS;
})->purpose('Inspecciona un empleado/día desde la API oficial; no inventa métricas Activity.');

Artisan::command('trackabi:compare-legacy {--period= : Período histórico cerrado}', function (TrackabiDirectImportService $importer): int {
    $period = PayrollPeriod::query()->find((int) $this->option('period'));
    if (! $period) {
        $this->error('Indica --period=ID válido.');

        return self::FAILURE;
    }

    try {
        $entries = $importer->fetchEntries($period->starts_at->copy(), $period->ends_at->copy());
    } catch (Throwable $exception) {
        $this->error($exception->getMessage());

        return self::FAILURE;
    }

    $employees = Employee::query()->get(['id', 'email', 'name'])->keyBy(fn (Employee $employee) => strtolower(trim((string) $employee->email)));
    $direct = collect($entries)->filter(fn ($entry) => is_array($entry) && isset($entry['member']['email'], $entry['date_logged']))
        ->groupBy(function ($entry) use ($employees) {
            $employee = $employees->get(strtolower(trim((string) $entry['member']['email'])));

            return ($employee?->id ?? 'unmapped').'|'.$entry['date_logged'];
        })->map(fn ($days) => (int) $days->sum(fn ($entry) => app(TimeParserService::class)->parseToSeconds($entry['logged_time'] ?? null)));
    $legacy = HubstaffTimeEntry::query()->where('payroll_period_id', $period->id)->where('active', true)
        ->where('source_provider', 'trackabi')->whereNotNull('employee_id')->get()
        ->groupBy(fn ($entry) => $entry->employee_id.'|'.$entry->date->toDateString())
        ->map(fn ($days) => (int) $days->sum('total_seconds'));
    $keys = $direct->keys()->merge($legacy->keys())->unique()->sort();
    $parser = app(TimeParserService::class);
    $this->table(['Empleado ID', 'Fecha', 'Legacy', 'API directa', 'Diferencia'], $keys->map(function ($key) use ($direct, $legacy, $parser): array {
        [$employeeId, $date] = explode('|', $key);

        return [$employeeId, $date, $parser->secondsToHourMinuteSecond((int) $legacy->get($key, 0)),
            $parser->secondsToHourMinuteSecond((int) $direct->get($key, 0)),
            $parser->secondsToHourMinuteSecond((int) $direct->get($key, 0) - (int) $legacy->get($key, 0))];
    })->all());
    $this->line('Solo lectura: ninguna tabla histórica fue modificada.');

    return self::SUCCESS;
})->purpose('Compara API directa con legacy guardado, sin llamar MindCloud ni escribir en DB.');
