<?php

namespace App\Imports;

use App\Models\Campaign;
use App\Models\ContractType;
use App\Models\Department;
use App\Models\Employee;
use App\Models\ScheduleType;
use App\Models\Team;
use App\Models\TierLevel;
use App\Models\WorkRole;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class EmployeesImport implements ToCollection, WithHeadingRow
{
    public function collection(Collection $rows): void
    {
        DB::transaction(function () use ($rows): void {
            foreach ($rows as $row) {
                $data = $this->normalizeRow($row);
                $name = $this->string($data, ['name']);

                if ($name === null) {
                    continue;
                }

                $tierLevelId = $this->catalogId(TierLevel::class, $this->string($data, ['tier_level']));
                $weeklyHours = $this->number($data, ['horas_de_trabajo_semanales', 'weekly_hours']);
                $dailyHours = $this->number($data, ['horas_de_trabajo_diarias', 'daily_hours']);
                $overtimeHours = $this->number($data, ['horas_extras', 'overtime_hours']);
                $hourlyRate = $this->number($data, ['salario_base_por_hora_por_nivel', 'hourly_rate']);
                $overtimeHourlyRate = $hourlyRate * 1.25;
                $dni = $this->string($data, ['dni', 'referencia_id', 'referencia']);
                $bankAccountNumber = $this->string($data, ['no_cuenta', 'numero_de_cuenta', 'numero_cuenta', 'bank_account_number']);

                $employeeData = [
                    'hubstaff_name' => $this->string($data, ['hubstaff_name']) ?: $name,
                    'name' => $name,
                    'department_id' => $this->catalogId(Department::class, $this->string($data, ['department'])),
                    'campaign_id' => $this->catalogId(Campaign::class, $this->string($data, ['campaign'])),
                    'team_id' => $this->teamId($this->string($data, ['team']), $this->string($data, ['campaign'])),
                    'work_role_id' => $this->catalogId(WorkRole::class, $this->string($data, ['role'])),
                    'tier_level_id' => $tierLevelId,
                    'schedule_type_id' => $this->scheduleTypeId($this->string($data, ['tipo_de_jornada', 'schedule_type'])),
                    'contract_type_id' => $this->contractTypeId($weeklyHours, $tierLevelId),
                    'schedule_type_name_snapshot' => $this->string($data, ['tipo_de_jornada', 'schedule_type']),
                    'weekly_hours' => $weeklyHours,
                    'daily_hours' => $dailyHours,
                    'overtime_hours' => $overtimeHours,
                    'hourly_rate' => $hourlyRate,
                    'calendar_days' => 30,
                    'monthly_salary' => round($dailyHours * $hourlyRate * 30, 2),
                    'daily_rate' => round($dailyHours * $hourlyRate, 4),
                    'overtime_hourly_rate' => round($overtimeHourlyRate, 4),
                    'base_salary' => $this->number($data, ['salario_base', 'base_salary']),
                    'expected_days' => $this->number($data, ['dias', 'days']),
                    'expected_total' => $this->number($data, ['total']),
                    'qa_bonus' => $this->number($data, ['qa']),
                    'productivity_bonus' => $this->number($data, ['productividad', 'productivity']),
                    'time_management_bonus' => $this->number($data, ['time_management']),
                    'location' => $this->string($data, ['location']),
                    'active' => true,
                ];

                if ($dni !== null) {
                    $employeeData['dni'] = $dni;
                }

                if ($bankAccountNumber !== null) {
                    $employeeData['bank_account_number'] = $bankAccountNumber;
                }

                Employee::query()->updateOrCreate(['name' => $name], $employeeData);
            }
        });
    }

    private function normalizeRow(Collection $row): array
    {
        return $row->mapWithKeys(fn ($value, $key) => [$this->normalizeKey((string) $key) => $value])->all();
    }

    private function normalizeKey(string $key): string
    {
        return (string) Str::of($key)
            ->trim()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_');
    }

    private function string(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $data[$key] ?? null;

            if ($value !== null && trim((string) $value) !== '') {
                return (string) Str::of((string) $value)->squish();
            }
        }

        return null;
    }

    private function number(array $data, array $keys): float
    {
        foreach ($keys as $key) {
            $value = $data[$key] ?? null;

            if ($value !== null && trim((string) $value) !== '') {
                return (float) str_replace([',', '$'], ['', ''], (string) $value);
            }
        }

        return 0.0;
    }

    private function catalogId(string $modelClass, ?string $name): ?int
    {
        if ($name === null) {
            return null;
        }

        return $modelClass::query()->firstOrCreate(['name' => $name], ['active' => true])->id;
    }

    private function teamId(?string $teamName, ?string $campaignName): ?int
    {
        if ($teamName === null) {
            return null;
        }

        $campaignId = $this->catalogId(Campaign::class, $campaignName);

        return Team::query()->firstOrCreate([
            'name' => $teamName,
            'campaign_id' => $campaignId,
        ], [
            'active' => true,
        ])->id;
    }

    private function scheduleTypeId(?string $name): ?int
    {
        if ($name === null) {
            return null;
        }

        return ScheduleType::query()->firstOrCreate([
            'name' => $name,
        ], [
            'code' => Str::of($name)->slug('_')->toString(),
            'weekly_hours' => 0,
            'daily_hours' => 0,
            'active' => true,
        ])->id;
    }

    private function contractTypeId(float $weeklyHours, ?int $tierLevelId): ?int
    {
        if ($tierLevelId && TierLevel::query()->whereKey($tierLevelId)->where('name', 'Tier 1')->exists()) {
            return ContractType::query()->where('code', 'trial_period')->value('id');
        }

        $code = $weeklyHours > 32 ? 'permanent' : 'hourly';

        return ContractType::query()->where('code', $code)->value('id');
    }
}
