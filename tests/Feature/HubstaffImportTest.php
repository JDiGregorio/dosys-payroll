<?php

namespace Tests\Feature;

use App\Imports\HubstaffTimeEntriesImport;
use App\Models\Employee;
use App\Models\EmployeeNameMapping;
use App\Models\PayrollPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;
use Tests\TestCase;

class HubstaffImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_imports_hubstaff_csv_and_maps_employees(): void
    {
        $period = PayrollPeriod::query()->create([
            'name' => 'May 2026',
            'starts_at' => '2026-05-01',
            'ends_at' => '2026-05-15',
        ]);

        $employee = Employee::query()->create([
            'name' => 'Ana Gomez',
            'hubstaff_name' => 'Ana Hubstaff',
            'daily_hours' => 8,
            'hourly_rate' => 10,
        ]);

        $path = storage_path('app/testing-hubstaff.csv');
        file_put_contents($path, implode("\n", [
            'Date,Member,Client,Project,Team,Task ID,To-do,Regular hours,Total hours,Activity %,Idle (%),Idle (hr),Total spent,Regular spent,PTO,PTO spent,Holiday,Holiday spent,Currency',
            '2026-05-01,Ana Hubstaff,Dosys,Operations,Team A,1,Work,07:00:00,08:00:00,80,10,00:30:00,0,0,0,0,0,0,USD',
            '2026-05-01,Unknown Person,Dosys,Operations,Team A,2,Work,02:00,02:00,70,0,0,0,0,0,0,0,0,USD',
        ]));

        Excel::import(new HubstaffTimeEntriesImport($period), $path);

        $this->assertDatabaseHas('hubstaff_time_entries', [
            'hubstaff_member' => 'Ana Hubstaff',
            'employee_id' => $employee->id,
            'total_seconds' => 28800,
            'idle_seconds' => 1800,
            'activity_percentage' => 80,
            'idle_percentage' => 10,
            'client' => null,
            'task_id' => null,
            'todo' => null,
            'total_spent' => 0,
            'regular_spent' => 0,
            'pto_seconds' => 0,
            'pto_spent' => 0,
            'holiday_seconds' => 0,
            'holiday_spent' => 0,
            'currency' => null,
        ]);

        $this->assertDatabaseHas('hubstaff_time_entries', [
            'hubstaff_member' => 'Unknown Person',
            'employee_id' => null,
        ]);
    }

    public function test_it_rejects_files_with_dates_outside_the_selected_period(): void
    {
        $period = PayrollPeriod::query()->create([
            'name' => 'May 2026',
            'starts_at' => '2026-05-01',
            'ends_at' => '2026-05-15',
        ]);
        $path = storage_path('app/testing-hubstaff-wrong-period.csv');
        file_put_contents($path, implode("\n", [
            'Date,Member,Project,Team,Regular hours,Total hours,Activity %,Idle (%),Idle (hr)',
            '2026-06-01,Ana Hubstaff,Operations,Team A,08:00:00,08:00:00,80,10,00:30:00',
        ]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('fuera del período');

        try {
            Excel::import(new HubstaffTimeEntriesImport($period), $path);
        } finally {
            $this->assertDatabaseCount('hubstaff_time_entries', 0);
        }
    }

    public function test_it_uses_saved_employee_name_mappings_for_future_imports(): void
    {
        $period = PayrollPeriod::query()->create([
            'name' => 'June 2026',
            'starts_at' => '2026-06-01',
            'ends_at' => '2026-06-15',
        ]);
        $employee = Employee::query()->create([
            'name' => 'Empleado de planilla',
            'daily_hours' => 8,
            'hourly_rate' => 10,
        ]);
        EmployeeNameMapping::query()->create([
            'employee_id' => $employee->id,
            'hubstaff_member' => 'Nombre diferente en Hubstaff',
            'confidence' => 100,
            'is_active' => true,
        ]);

        $path = storage_path('app/testing-hubstaff-name-mapping.csv');
        file_put_contents($path, implode("\n", [
            'Date,Member,Project,Team,Regular hours,Total hours,Activity %,Idle (%),Idle (hr)',
            '2026-06-01,Nombre diferente en Hubstaff,Operations,Team A,08:00:00,08:00:00,80,10,00:05:00',
        ]));

        Excel::import(new HubstaffTimeEntriesImport($period), $path);

        $this->assertDatabaseHas('hubstaff_time_entries', [
            'hubstaff_member' => 'Nombre diferente en Hubstaff',
            'employee_id' => $employee->id,
        ]);
    }
}
