<?php

namespace Database\Seeders;

use App\Models\HourlyRateType;
use Illuminate\Database\Seeder;

class HourlyRateTypeSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['name' => 'Hora ordinaria', 'code' => 'ordinary', 'multiplier' => 1.00],
            ['name' => 'Hora extra diurna', 'code' => 'daytime_overtime', 'multiplier' => 1.25],
            ['name' => 'Hora extra nocturna extensión diurna', 'code' => 'night_overtime_day_extension', 'multiplier' => 1.50],
            ['name' => 'Hora extra nocturna extensión nocturna', 'code' => 'night_overtime_night_extension', 'multiplier' => 1.75],
            ['name' => 'Hora ordinaria en feriado', 'code' => 'holiday_ordinary', 'multiplier' => 2.00],
            ['name' => 'Hora extra diurna en feriado', 'code' => 'holiday_daytime_overtime', 'multiplier' => 2.25],
            ['name' => 'Hora extra nocturna extensión diurna en feriado', 'code' => 'holiday_night_overtime_day_extension', 'multiplier' => 2.50],
            ['name' => 'Hora extra nocturna extensión nocturna en feriado', 'code' => 'holiday_night_overtime_night_extension', 'multiplier' => 2.75],
        ];

        foreach ($rows as $row) {
            HourlyRateType::query()->updateOrCreate(['code' => $row['code']], $row + ['active' => true]);
        }
    }
}
