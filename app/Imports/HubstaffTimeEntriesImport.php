<?php

namespace App\Imports;

use App\Models\Employee;
use App\Models\HubstaffImport;
use App\Models\HubstaffTimeEntry;
use App\Models\PayrollPeriod;
use App\Services\TimeParserService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class HubstaffTimeEntriesImport implements ToCollection, WithHeadingRow
{
    public function __construct(
        private PayrollPeriod $period,
        private ?HubstaffImport $hubstaffImport = null,
        private ?TimeParserService $timeParser = null,
    ) {
        $this->timeParser ??= app(TimeParserService::class);
    }

    public function collection(Collection $rows): void
    {
        DB::transaction(function () use ($rows): void {
            $count = 0;

            foreach ($rows as $row) {
                $data = $this->normalizeRow($row);
                $member = $this->string($data, ['member']);
                $date = $this->date($data['date'] ?? null);

                if ($member === null || $date === null) {
                    continue;
                }

                HubstaffTimeEntry::query()->create([
                    'payroll_period_id' => $this->period->id,
                    'hubstaff_import_id' => $this->hubstaffImport?->id,
                    'employee_id' => $this->resolveEmployeeId($member),
                    'hubstaff_member' => $member,
                    'date' => $date,
                    'project' => $this->string($data, ['project']),
                    'team' => $this->string($data, ['team']),
                    'regular_seconds' => $this->seconds($data, ['regular_hours']),
                    'total_seconds' => $this->totalSeconds($data),
                    'idle_seconds' => $this->seconds($data, ['idle_hr']),
                    'activity_percentage' => $this->numberOrNull($data, ['activity']),
                    'idle_percentage' => $this->numberOrNull($data, ['idle']),
                    'raw_payload' => $this->relevantPayload($data),
                ]);

                $count++;
            }

            if ($this->hubstaffImport) {
                $this->hubstaffImport->update([
                    'rows_count' => $count,
                    'status' => 'processed',
                ]);
            }
        });
    }

    private function resolveEmployeeId(string $member): ?int
    {
        return Employee::query()
            ->where('hubstaff_name', $member)
            ->orWhere('name', $member)
            ->value('id');
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
            ->replace('%', '')
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

    private function seconds(array $data, array $keys): int
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) {
                return max($this->timeParser->parseToSeconds($data[$key]), 0);
            }
        }

        return 0;
    }

    private function totalSeconds(array $data): int
    {
        $totalSeconds = $this->seconds($data, ['total_hours']);

        return $totalSeconds > 0 ? $totalSeconds : $this->seconds($data, ['regular_hours']);
    }

    private function numberOrNull(array $data, array $keys): ?float
    {
        foreach ($keys as $key) {
            $value = $data[$key] ?? null;

            if ($value !== null && trim((string) $value) !== '') {
                return (float) str_replace(['%', '$', ','], ['', '', ''], (string) $value);
            }
        }

        return null;
    }

    private function date(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        if (is_numeric($value)) {
            return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
        }

        return Carbon::parse((string) $value)->toDateString();
    }

    private function relevantPayload(array $data): array
    {
        return array_intersect_key($data, array_flip([
            'date',
            'member',
            'project',
            'team',
            'regular_hours',
            'total_hours',
            'activity',
            'idle',
            'idle_hr',
        ]));
    }
}
