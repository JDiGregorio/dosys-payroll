<?php

namespace Database\Seeders;

use App\Models\ScheduleType;
use App\Models\TierLevel;
use Illuminate\Database\Seeder;

class TierLevelSeeder extends Seeder
{
    public function run(): void
    {
        $diurnaId = ScheduleType::query()->where('code', 'diurna')->value('id');

        $rows = [
            ['Tier 1', 'Trainee', 'Entry Level', 40, 14200, 59.2],
            ['Tier 2', 'Phone Representative Chat Support', 'Junior Agent', 40, 15000, 62.5],
            ['Tier 3', 'Phone Representative', 'Advanced Agent', 40, 16500, 68.8],
            ['Tier 4', 'Phone Representative', 'Senior Agent/First Leadership', 40, 17500, 72.9],
            ['Tier 5', 'Operation Specialist / QA / Scheduler / Workface / Trainer / Coordinator', 'Coordinator', 40, 19000, 79.2],
            ['Tier 6', 'Supervisor', 'Supervisor', 40, 20500, 85.4],
            ['Tier 7', 'Team Manager', 'Manager', 40, 26600, 110.8],
            ['Tier 8', 'Operations Manager', 'Senior Manager', 40, null, null],
        ];

        foreach ($rows as [$name, $position, $category, $weeklyHours, $monthlySalary, $hourlyRate]) {
            TierLevel::query()->updateOrCreate(['name' => $name], [
                'position_name' => $position,
                'category' => $category,
                'schedule_type_id' => $diurnaId,
                'weekly_hours' => $weeklyHours,
                'monthly_salary' => $monthlySalary,
                'hourly_rate' => $hourlyRate,
                'active' => true,
            ]);
        }
    }
}
