<?php

namespace App\Services\Trackabi;

use App\Models\Campaign;
use App\Models\DailyTimeReview;
use App\Models\Employee;
use App\Models\HubstaffImport;
use App\Models\HubstaffTimeEntry;
use App\Models\PayrollPeriod;
use App\Models\TrackabiProjectMapping;
use App\Models\TrackingImportAlert;
use App\Services\PayrollCalculationService;
use App\Services\TimeParserService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class TrackabiDirectImportService
{
    private const PROTECTED_STATUSES = [
        'revisado_supervisor', 'aprobado_rrhh', 'cerrado', 'locked', 'reviewed', 'approved',
    ];

    public function __construct(
        private readonly TrackabiDirectApiClient $client,
        private readonly PayrollCalculationService $payroll,
        private readonly TimeParserService $timeParser,
        private readonly TrackabiDirectTimeEntryValidator $entryValidator,
    ) {}

    public function assertPeriod(PayrollPeriod $period): void
    {
        if (! in_array($period->status, ['borrador', 'importado', 'en_revision'], true)) {
            throw new RuntimeException("No se puede importar Trackabi en el período #{$period->id}: estado {$period->status}.");
        }

        $cutover = config('trackabi_direct.cutover_date');
        if (! is_string($cutover) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $cutover)
            || ! Carbon::hasFormat($cutover, 'Y-m-d')) {
            throw new RuntimeException('Configura TRACKABI_DIRECT_CUTOVER_DATE con una fecha válida YYYY-MM-DD.');
        }

        if ($period->starts_at->toDateString() < $cutover) {
            throw new RuntimeException("El período #{$period->id} inicia antes del cutover {$cutover}.");
        }

        $overlapping = PayrollPeriod::query()
            ->whereKeyNot($period->id)
            ->whereDate('starts_at', '<', $cutover)
            ->whereDate('ends_at', '>=', $cutover)
            ->exists();
        if ($overlapping) {
            throw new RuntimeException('El cutover cae dentro de un período anterior; debe ser el primer día de un período.');
        }

        $this->client->assertConfigured();
    }

    public function fetchEntries(Carbon $from, Carbon $to, ?int $projectId = null): array
    {
        $pageSize = max(1, min(500, (int) config('trackabi_direct.import_page_size', 100)));
        $maxPages = max(1, (int) config('trackabi_direct.import_max_pages', 100));
        $all = [];

        for ($page = 1; $page <= $maxPages; $page++) {
            $response = $this->client->get('/api/v1/time-entries', array_filter([
                'start_date' => $from->toDateString(),
                'end_date' => $to->toDateString(),
                'project_id' => $projectId,
                'page' => $page,
                'page_size' => $pageSize,
            ], fn ($value): bool => $value !== null));

            if (! array_is_list($response)) {
                throw new RuntimeException('Trackabi devolvió un formato inesperado de time entries.');
            }

            $count = count($response);
            if ($count > $pageSize || array_filter($response, fn ($row): bool => ! is_array($row))) {
                throw new RuntimeException('Trackabi devolvió una página inválida de time entries.');
            }

            array_push($all, ...$response);
            if ($count < $pageSize) {
                return $all;
            }
        }

        throw new RuntimeException('Trackabi alcanzó el máximo de páginas; no se importó un conjunto incompleto.');
    }

    public function audit(Carbon $from, Carbon $to): array
    {
        $this->client->assertConfigured();
        if ($from->gt($to)) {
            throw new RuntimeException('La fecha inicial debe ser anterior o igual a la final.');
        }

        $employees = Employee::query()->where('active', true)->get();
        $seenIds = [];
        $seenFingerprints = [];
        $findings = [];
        $entries = $this->fetchEntries($from, $to);

        foreach ($entries as $entry) {
            $date = $entry['date_logged'] ?? null;
            if (! is_string($date) || $date < $from->toDateString() || $date > $to->toDateString()) {
                continue;
            }

            $issues = [];
            $logged = $this->entryValidator->loggedTime($entry['logged_time'] ?? null);
            if ($logged['issue'] !== null) {
                $issues[] = $logged['issue'];
            }
            $member = $entry['member'] ?? null;
            $employee = $this->resolveEmployee($employees, $member, null);
            if (! $employee) {
                $issues[] = 'missing_employee';
            }
            $project = $entry['project'] ?? null;
            if (! is_array($project) || empty($project['id'])) {
                $issues[] = 'missing_project';
            }
            array_push($issues, ...$this->entryValidator->startEndIssues($entry, $logged['seconds']));

            $id = isset($entry['id']) ? (string) $entry['id'] : '';
            if ($id === '') {
                $issues[] = 'missing_entry_id';
            } elseif (isset($seenIds[$id])) {
                $issues[] = 'duplicate_entry_id';
            }
            $seenIds[$id] = true;

            $fingerprint = $this->entryValidator->duplicateFingerprint($entry);
            if ($fingerprint !== null) {
                if (isset($seenFingerprints[$fingerprint]) && $seenFingerprints[$fingerprint] !== $id) {
                    $issues[] = 'possible_duplicate_content';
                }
                $seenFingerprints[$fingerprint] = $id;
            }
            if ($issues === []) {
                continue;
            }

            $findings[] = [
                'entry_id' => $id ?: 'N/D',
                'employee' => $employee?->name ?? $this->entryValidator->memberName($member),
                'date_logged' => $date,
                'raw_logged_time' => $entry['logged_time'] ?? null,
                'start_time' => $entry['start_time'] ?? null,
                'end_time' => $entry['end_time'] ?? null,
                'project' => is_array($project) ? ($project['name'] ?? $project['id'] ?? null) : null,
                'issues' => array_values(array_unique($issues)),
            ];
        }

        return ['entries_scanned' => count($entries), 'findings' => $findings];
    }

    public function preview(PayrollPeriod $period, ?string $campaignName = null, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $this->assertPeriod($period);
        $from ??= $period->starts_at->copy();
        $to ??= $period->ends_at->copy();
        if ($from->gt($to) || $from->lt($period->starts_at) || $to->gt($period->ends_at)) {
            throw new RuntimeException('El rango debe estar dentro del período de planilla.');
        }

        $campaign = $campaignName && strtolower($campaignName) !== 'all'
            ? Campaign::query()->whereRaw('lower(name) = ?', [strtolower($campaignName)])->first()
            : null;
        if ($campaignName && strtolower($campaignName) !== 'all' && ! $campaign) {
            throw new RuntimeException("No existe la campaña {$campaignName}.");
        }

        $mappings = TrackabiProjectMapping::query()
            ->where('active', true)
            ->when($campaign, fn ($query) => $query->where('campaign_id', $campaign->id))
            ->get()
            ->keyBy('trackabi_project_id');
        if ($mappings->isEmpty()) {
            throw new RuntimeException('No hay proyectos Trackabi mapeados para esta importación.');
        }

        $existingOtherSources = HubstaffTimeEntry::query()
            ->where('payroll_period_id', $period->id)
            ->where('active', true)
            ->where(fn ($query) => $query->whereNull('source_provider')->orWhere('source_provider', '!=', 'trackabi_api'))
            ->exists();
        if ($existingOtherSources) {
            throw new RuntimeException('El período contiene tracking activo de una fuente anterior; no se mezclarán proveedores.');
        }

        $employees = Employee::query()->where('active', true)->get();
        $raw = $campaign
            ? $mappings->keys()->flatMap(fn ($projectId): array => $this->fetchEntries($from, $to, (int) $projectId))->all()
            : $this->fetchEntries($from, $to);
        $rows = [];
        $unmappedProjects = [];
        $unmappedEmployees = [];
        $suspiciousEntries = [];
        $unmappedProjectCoverage = [];
        $seen = [];

        foreach ($raw as $entry) {
            $date = $entry['date_logged'] ?? null;
            if (! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
                || ! Carbon::hasFormat($date, 'Y-m-d')
                || $date < $from->toDateString() || $date > $to->toDateString()) {
                throw new RuntimeException('Trackabi devolvió una fecha ausente, inválida o fuera del rango solicitado.');
            }

            $externalId = $entry['id'] ?? null;
            if (! is_int($externalId) && ! (is_string($externalId) && ctype_digit($externalId))) {
                throw new RuntimeException('Trackabi devolvió un time entry sin ID válido.');
            }
            $externalId = (string) $externalId;
            if (isset($seen[$externalId])) {
                throw new RuntimeException("Trackabi devolvió el ID {$externalId} más de una vez.");
            }
            $seen[$externalId] = true;

            $projectId = $entry['project']['id'] ?? null;
            $mapping = is_numeric($projectId) ? $mappings->get((int) $projectId) : null;
            $employee = $this->resolveEmployee($employees, $entry['member'] ?? [], null);
            $employeeMapping = $employee
                ? $mappings->first(fn (TrackabiProjectMapping $candidate): bool => (int) $candidate->campaign_id === (int) $employee->campaign_id)
                : null;
            $missingProject = $projectId === null && $employeeMapping !== null;
            $logged = $this->entryValidator->loggedTime($entry['logged_time'] ?? null);
            if ($logged['issue'] !== null) {
                $suspiciousEntries[] = [
                    'entry_id' => $externalId,
                    'employee' => $employee?->name ?? $this->entryValidator->memberName($entry['member'] ?? null),
                    'employee_id' => $employee?->id,
                    'member_id' => $entry['member']['id'] ?? null,
                    'date_logged' => $date,
                    'raw_logged_time' => $entry['logged_time'] ?? null,
                    'start_time' => $entry['start_time'] ?? null,
                    'end_time' => $entry['end_time'] ?? null,
                    'project' => $entry['project']['name'] ?? null,
                    'project_id' => is_numeric($projectId) ? (int) $projectId : null,
                    'reason' => $logged['issue'],
                    'reason_code' => $this->entryValidator->reasonCode($entry['logged_time'] ?? null, $logged['issue']),
                    'start_end_issues' => $this->entryValidator->startEndIssues($entry, $logged['seconds']),
                    'previous_active_entry' => HubstaffTimeEntry::query()
                        ->where('payroll_period_id', $period->id)
                        ->where('direct_external_id', $externalId)
                        ->where('active', true)->exists(),
                ];

                continue;
            }
            if (! $mapping && ! $missingProject) {
                $key = is_numeric($projectId) ? (string) $projectId : 'NULL';
                $unmappedProjectCoverage[$key] ??= [
                    'project_id' => is_numeric($projectId) ? (int) $projectId : null,
                    'project_name' => $entry['project']['name'] ?? null,
                    'entries' => 0,
                ];
                $unmappedProjectCoverage[$key]['entries']++;
                $unmappedProjects[] = [
                    'entry_id' => $externalId,
                    'employee' => $employee?->name ?? $this->entryValidator->memberName($entry['member'] ?? null),
                    'employee_id' => $employee?->id,
                    'member_id' => $entry['member']['id'] ?? null,
                    'date' => $date,
                    'project_id' => is_numeric($projectId) ? (int) $projectId : null,
                    'project' => $entry['project']['name'] ?? null,
                ];

                continue;
            }
            if (! $employee) {
                $unmappedEmployees[] = ['member_id' => $entry['member']['id'] ?? null, 'email' => $entry['member']['email'] ?? null, 'date' => $date];

                continue;
            }
            if (! $employeeMapping || ($campaign && (int) $employee->campaign_id !== (int) $campaign->id)) {
                $unmappedEmployees[] = ['member_id' => $entry['member']['id'] ?? null, 'email' => $entry['member']['email'] ?? null, 'date' => $date];

                continue;
            }

            $billable = $this->seconds($entry['billable'] ?? null);
            $review = DailyTimeReview::query()
                ->where('payroll_period_id', $period->id)
                ->where('employee_id', $employee->id)
                ->whereDate('date', $date)
                ->first();
            $protected = $review && in_array($review->status, self::PROTECTED_STATUSES, true);
            $stored = HubstaffTimeEntry::query()
                ->where('payroll_period_id', $period->id)
                ->where('direct_external_id', $externalId)
                ->first();
            if ($stored && ($stored->employee_id !== $employee->id || $stored->date->toDateString() !== $date)) {
                throw new RuntimeException("El time entry {$externalId} cambió de empleado o fecha; requiere revisión manual.");
            }
            $unchanged = $stored && $stored->active && $stored->source_provider === 'trackabi_api'
                && ($stored->trackabi_project_id === null ? null : (int) $stored->trackabi_project_id)
                    === ($projectId === null ? null : (int) $projectId)
                && (int) $stored->total_seconds === $logged['seconds']
                && $stored->raw_payload == $entry;

            $rows[] = [
                'external_id' => $externalId,
                'employee_id' => $employee->id,
                'employee_name' => $employee->name,
                'member_id' => isset($entry['member']['id']) ? (string) $entry['member']['id'] : null,
                'member_email' => strtolower(trim((string) ($entry['member']['email'] ?? ''))),
                'member_name' => trim(($entry['member']['first_name'] ?? '').' '.($entry['member']['last_name'] ?? '')),
                'campaign_id' => $employee->campaign_id,
                'project_id' => $missingProject ? null : (int) $projectId,
                'project_name' => $missingProject ? null : ($entry['project']['name'] ?? $mapping->trackabi_project_name),
                'missing_project' => $missingProject,
                'date' => $date,
                'logged_seconds' => $logged['seconds'],
                'raw_logged_time' => $entry['logged_time'],
                'previous_raw_logged_time' => $stored?->raw_logged_time,
                'billable_seconds' => $billable,
                'task' => $entry['task']['name'] ?? null,
                'time_type' => $entry['time_type'] ?? null,
                'start_time' => $entry['start_time'] ?? null,
                'end_time' => $entry['end_time'] ?? null,
                'raw' => $entry,
                'action' => $protected
                    ? ($unchanged ? 'skip_reviewed' : 'alert_reviewed_change')
                    : ($unchanged ? 'skip_unchanged' : ($stored ? 'update' : 'insert')),
            ];
        }

        return [
            'period_id' => $period->id,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'campaign' => $campaign?->name ?? 'all',
            'projects' => $mappings->map(fn ($mapping) => [$mapping->trackabi_project_id, $mapping->campaign_id])->values()->all(),
            'api_entries' => count($raw),
            'entries' => $rows,
            'employees_matched' => count(array_unique(array_column($rows, 'employee_id'))),
            'unmapped_projects' => array_values(array_unique($unmappedProjects, SORT_REGULAR)),
            'unmapped_project_coverage' => array_values($unmappedProjectCoverage),
            'unmapped_employees' => array_values(array_unique($unmappedEmployees, SORT_REGULAR)),
            'suspicious_entries' => $suspiciousEntries,
            'missing_logged_time' => count(array_filter($raw, fn ($entry): bool => ($entry['logged_time'] ?? null) === null)),
            'total_logged_seconds' => array_sum(array_column($rows, 'logged_seconds')),
            'would_insert' => count(array_filter($rows, fn ($row) => $row['action'] === 'insert')),
            'would_update' => count(array_filter($rows, fn ($row) => $row['action'] === 'update')),
            'would_skip' => count(array_filter($rows, fn ($row) => in_array($row['action'], ['skip_reviewed', 'skip_unchanged', 'alert_reviewed_change'], true))),
            'activity_status' => 'NOT_EXPOSED_BY_PUBLIC_API',
        ];
    }

    public function import(PayrollPeriod $period, ?string $campaignName = null, ?Carbon $from = null, ?Carbon $to = null): array
    {
        try {
            $plan = $this->preview($period, $campaignName, $from, $to);
            $result = DB::transaction(function () use ($period, $plan): array {
                $lockedPeriod = PayrollPeriod::query()->lockForUpdate()->findOrFail($period->id);
                $this->assertPeriod($lockedPeriod);
                if (HubstaffTimeEntry::query()->where('payroll_period_id', $period->id)->where('active', true)
                    ->where(fn ($query) => $query->whereNull('source_provider')->orWhere('source_provider', '!=', 'trackabi_api'))
                    ->exists()) {
                    throw new RuntimeException('Se detectó otra fuente activa durante la importación.');
                }

                foreach ($plan['entries'] as $row) {
                    if ($row['action'] !== 'alert_reviewed_change') {
                        continue;
                    }
                    TrackingImportAlert::query()->updateOrCreate(
                        ['payroll_period_id' => $period->id, 'code' => 'TRACKABI_DIRECT_DATA_AFTER_REVIEW',
                            'external_id' => $row['external_id']],
                        ['source_provider' => 'trackabi_api', 'resolved' => false,
                            'context' => ['employee' => $row['employee_name'], 'date' => $row['date'],
                                'entry_id' => $row['external_id'],
                                'previous_raw_logged_time' => $row['previous_raw_logged_time'],
                                'incoming_raw_logged_time' => $row['raw_logged_time']]],
                    );
                }

                $pendingRows = array_filter($plan['entries'], fn ($row) => in_array($row['action'], ['insert', 'update'], true));
                if ($pendingRows === [] && $plan['suspicious_entries'] === []
                    && $plan['unmapped_projects'] === [] && $plan['unmapped_employees'] === []) {
                    return ['written' => 0, 'affected_employees' => 0, 'estimated_days' => 0];
                }

                $import = $pendingRows !== [] ? HubstaffImport::query()->create([
                    'payroll_period_id' => $period->id,
                    'source_provider' => 'trackabi_api',
                    'original_filename' => "trackabi-direct:{$plan['from']}:{$plan['to']}",
                    'imported_by' => auth()->user()?->email,
                    'status' => 'pending',
                ]) : null;
                $affected = [];
                $written = 0;

                foreach ($plan['entries'] as $row) {
                    if (in_array($row['action'], ['skip_unchanged', 'skip_reviewed', 'alert_reviewed_change'], true)) {
                        continue;
                    }
                    $review = DailyTimeReview::query()->where('payroll_period_id', $period->id)
                        ->where('employee_id', $row['employee_id'])->whereDate('date', $row['date'])
                        ->lockForUpdate()->first();
                    if ($review && in_array($review->status, self::PROTECTED_STATUSES, true)) {
                        continue;
                    }

                    HubstaffTimeEntry::query()->updateOrCreate(
                        ['payroll_period_id' => $period->id, 'direct_external_id' => $row['external_id']],
                        [
                            'hubstaff_import_id' => $import->id,
                            'source_provider' => 'trackabi_api',
                            'active' => true,
                            'external_id' => $row['external_id'],
                            'source_email' => $row['member_email'] ?: null,
                            'source_member_id' => $row['member_id'],
                            'employee_id' => $row['employee_id'],
                            'hubstaff_member' => $row['member_name'] ?: $row['member_email'],
                            'date' => $row['date'],
                            'trackabi_project_id' => $row['project_id'],
                            'project' => $row['project_name'],
                            'todo' => $row['task'],
                            'source_time_type' => $row['time_type'],
                            'source_started_at' => $row['start_time'] ? $row['date'].' '.$row['start_time'] : null,
                            'source_ended_at' => $row['end_time'] ? $row['date'].' '.$row['end_time'] : null,
                            'regular_seconds' => $row['logged_seconds'],
                            'total_seconds' => $row['logged_seconds'],
                            'raw_logged_time' => $row['raw_logged_time'],
                            'billable_seconds' => $row['billable_seconds'],
                            'productive_seconds' => null,
                            'unproductive_seconds' => null,
                            'activity_score' => null,
                            'activity_percentage' => null,
                            'idle_seconds' => 0,
                            'idle_percentage' => null,
                            'adjusted_payable_seconds' => null,
                            'requires_manual_review' => false,
                            'raw_payload' => $row['raw'],
                        ],
                    );
                    $affected[$row['employee_id']] = true;
                    $written++;
                    if ($row['missing_project']) {
                        TrackingImportAlert::query()->updateOrCreate(
                            ['payroll_period_id' => $period->id, 'code' => 'TRACKABI_PROJECT_MISSING_ASSIGNED_BY_EMPLOYEE',
                                'external_id' => $row['external_id']],
                            ['source_provider' => 'trackabi_api', 'resolved' => false,
                                'context' => ['employee' => $row['employee_name'], 'employee_id' => $row['employee_id'],
                                    'date' => $row['date'], 'entry_id' => $row['external_id'], 'project_id' => null]],
                        );
                    }
                    TrackingImportAlert::query()->where('payroll_period_id', $period->id)
                        ->where('code', 'TRACKABI_SUSPICIOUS_LOGGED_TIME')
                        ->where('external_id', $row['external_id'])
                        ->update(['resolved' => true]);
                }

                foreach ($affected as $employeeId => $unused) {
                    $this->payroll->recalculateEmployeePreservingManual($lockedPeriod, Employee::query()->findOrFail($employeeId));
                }

                $estimatedDays = 0;

                if ($plan['unmapped_projects'] !== []) {
                    TrackingImportAlert::query()->where('payroll_period_id', $period->id)
                        ->where('code', 'unmapped_project')->whereNull('external_id')
                        ->where('resolved', false)->update(['resolved' => true]);
                }
                if (collect($plan['entries'])->contains(fn (array $row): bool => $row['missing_project'])) {
                    TrackingImportAlert::query()->where('payroll_period_id', $period->id)
                        ->where('code', 'unmapped_project')->whereNull('external_id')
                        ->where('resolved', false)->update(['resolved' => true]);
                }
                foreach ($plan['unmapped_projects'] as $project) {
                    TrackingImportAlert::query()->updateOrCreate(
                        ['payroll_period_id' => $period->id, 'code' => 'unmapped_project',
                            'external_id' => $project['entry_id']],
                        ['source_provider' => 'trackabi_api', 'context' => $project, 'resolved' => false],
                    );
                }
                foreach ($plan['suspicious_entries'] as $entry) {
                    TrackingImportAlert::query()->updateOrCreate(
                        [
                            'payroll_period_id' => $period->id,
                            'code' => 'TRACKABI_SUSPICIOUS_LOGGED_TIME',
                            'external_id' => $entry['entry_id'],
                        ],
                        [
                            'source_provider' => 'trackabi_api',
                            'context' => $entry,
                            'resolved' => false,
                        ],
                    );
                }
                foreach ($plan['unmapped_employees'] as $member) {
                    TrackingImportAlert::query()->create([
                        'payroll_period_id' => $period->id,
                        'source_provider' => 'trackabi_api',
                        'code' => 'unmapped_employee',
                        'context' => $member,
                    ]);
                }

                $import?->update(['rows_count' => $written, 'status' => 'processed']);

                return ['written' => $written, 'affected_employees' => count($affected), 'estimated_days' => $estimatedDays];
            });
        } catch (\Throwable $exception) {
            if (in_array($period->fresh()->status, ['borrador', 'importado', 'en_revision'], true)) {
                TrackingImportAlert::query()->create([
                    'payroll_period_id' => $period->id,
                    'source_provider' => 'trackabi_api',
                    'code' => 'import_failed',
                    'context' => ['error_type' => class_basename($exception)],
                ]);
            }
            throw $exception;
        }

        return [...$plan, ...$result];
    }

    private function seconds(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }
        if (! is_string($value) || ! preg_match('/^\d+:[0-5]\d:[0-5]\d$/', $value)) {
            throw new RuntimeException('Trackabi devolvió una duración inválida.');
        }

        return $this->timeParser->parseToSeconds($value);
    }

    private function resolveEmployee(Collection $employees, mixed $member, ?int $campaignId): ?Employee
    {
        if (! is_array($member)) {
            return null;
        }

        $eligible = $campaignId === null ? $employees : $employees->where('campaign_id', $campaignId);
        $email = strtolower(trim((string) ($member['email'] ?? '')));
        if ($email !== '') {
            $matches = $eligible->filter(fn (Employee $employee): bool => strtolower(trim((string) $employee->email)) === $email);
            if ($matches->count() === 1) {
                return $matches->first();
            }
        }

        $name = $this->normalizeName(trim(($member['first_name'] ?? '').' '.($member['last_name'] ?? '')));
        if ($name === '') {
            return null;
        }
        $tokens = array_filter(explode(' ', $name));
        $matches = $eligible->filter(function (Employee $employee) use ($tokens): bool {
            $haystack = $this->normalizeName($employee->name.' '.$employee->hubstaff_name);

            return collect($tokens)->every(fn ($token): bool => str_contains($haystack, $token));
        });

        return $matches->count() === 1 ? $matches->first() : null;
    }

    private function normalizeName(string $name): string
    {
        return (string) Str::of(Str::ascii($name))->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->squish();
    }
}
