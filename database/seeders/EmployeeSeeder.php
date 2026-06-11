<?php

namespace Database\Seeders;

use App\Models\Campaign;
use App\Models\ContractType;
use App\Models\Department;
use App\Models\Employee;
use App\Models\ScheduleType;
use App\Models\Team;
use App\Models\TierLevel;
use App\Models\User;
use App\Models\WorkRole;
use Illuminate\Database\Seeder;

class EmployeeSeeder extends Seeder
{
    public function run(): void
    {
        // Reemplaza o amplía este arreglo con la lista real de empleados.
        $employees = [
            [
                'name' => 'Supervisor Demo',
                'user_email' => 'supervisor@dosys.local',
                'campaign' => 'PALMETTO',
                'team' => 'BDC',
                'department' => 'OPERATIONS',
                'work_role' => 'SUPERVISOR',
                'tier' => 'Tier 6',
                'schedule_type' => 'Diurna',
                'contract_type' => 'Permanente',
                'weekly_hours' => 40,
                'daily_hours' => 8,
                'calendar_days' => 30,
                'overtime_hours' => 0,
                'monthly_salary' => 20500,
                'hourly_rate' => 85.40,
                'overtime_hourly_rate' => 106.75,
                'location' => 'on_site',
                'applies_private_insurance' => false,
                'applies_ihss' => true,
                'applies_isr' => false,
                'applies_rap' => false,
            ],
            [
                'name' => 'Empleado Demo',
                'campaign' => 'PALMETTO',
                'team' => 'BDC',
                'department' => 'OPERATIONS',
                'work_role' => 'DEBT COLLECTOR',
                'tier' => 'Tier 2',
                'schedule_type' => 'Diurna',
                'contract_type' => 'Permanente',
                'weekly_hours' => 40,
                'daily_hours' => 8,
                'calendar_days' => 30,
                'overtime_hours' => 0,
                'monthly_salary' => 15000,
                'hourly_rate' => 62.50,
                'overtime_hourly_rate' => 78.13,
                'location' => 'on_site',
                'applies_private_insurance' => false,
                'applies_ihss' => true,
                'applies_isr' => false,
                'applies_rap' => false,
            ],
        ];

        $supervisorId = User::query()->where('email', 'supervisor@dosys.local')->value('id');

        foreach ($employees as $row) {
            $employee = Employee::query()->updateOrCreate(['name' => $row['name']], [
                'campaign_id' => Campaign::query()->where('name', $row['campaign'])->value('id'),
                'team_id' => Team::query()->where('name', $row['team'])->value('id'),
                'department_id' => Department::query()->where('name', $row['department'])->value('id'),
                'work_role_id' => WorkRole::query()->where('name', $row['work_role'])->value('id'),
                'tier_level_id' => TierLevel::query()->where('name', $row['tier'])->value('id'),
                'schedule_type_id' => ScheduleType::query()->where('name', $row['schedule_type'])->value('id'),
                'contract_type_id' => ContractType::query()->where('name', $row['contract_type'])->value('id'),
                'supervisor_user_id' => empty($row['user_email']) ? $supervisorId : null,
                'schedule_type_name_snapshot' => $row['schedule_type'],
                'weekly_hours' => $row['weekly_hours'],
                'daily_hours' => $row['daily_hours'],
                'calendar_days' => $row['calendar_days'],
                'overtime_hours' => $row['overtime_hours'] ?? 0,
                'monthly_salary' => $row['monthly_salary'],
                'hourly_rate' => $row['hourly_rate'],
                'overtime_hourly_rate' => $row['overtime_hourly_rate'] ?? 0,
                'location' => $row['location'],
                'applies_private_insurance' => $row['applies_private_insurance'],
                'applies_ihss' => $row['applies_ihss'],
                'applies_isr' => $row['applies_isr'],
                'applies_rap' => $row['applies_rap'],
                'active' => true,
            ]);

            if (! empty($row['user_email'])) {
                User::query()
                    ->where('email', $row['user_email'])
                    ->update(['employee_id' => $employee->id]);
            }
        }
    }
}
