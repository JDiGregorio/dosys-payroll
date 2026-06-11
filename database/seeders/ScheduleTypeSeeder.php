<?php

namespace Database\Seeders;

use App\Models\ScheduleType;
use Illuminate\Database\Seeder;

class ScheduleTypeSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['name' => 'Diurna', 'code' => 'diurna', 'start_time' => '04:00', 'end_time' => '17:00', 'weekly_hours' => 40, 'daily_hours' => 8, 'description' => 'Jornada diurna con pago por hora normal; hora extra diurna equivale a 125%.'],
            ['name' => 'Mixta', 'code' => 'mixta', 'start_time' => null, 'end_time' => null, 'weekly_hours' => 40, 'daily_hours' => 8, 'description' => 'Combina jornada diurna y nocturna; la jornada nocturna no debe pasar de 3 horas. Aplican recargos según tipo de hora.'],
            ['name' => 'Nocturna', 'code' => 'nocturna', 'start_time' => '19:01', 'end_time' => '04:59', 'weekly_hours' => 40, 'daily_hours' => 8, 'description' => 'Hora extra nocturna con recargo según configuración de tipos de hora.'],
            ['name' => 'Modalidad 4x4', 'code' => '4x4', 'start_time' => null, 'end_time' => null, 'weekly_hours' => 32, 'daily_hours' => 8, 'description' => 'Trabaja 4 días y descansa 4 días, equivalente a 32 horas.'],
            ['name' => 'Rotativa', 'code' => 'rotativa', 'start_time' => null, 'end_time' => null, 'weekly_hours' => 40, 'daily_hours' => 8, 'description' => 'Jornada rotativa con 40 horas ordinarias y 4 horas extra asignadas previamente, para un total operativo de 44 horas.'],
        ];

        foreach ($rows as $row) {
            ScheduleType::query()->updateOrCreate(['code' => $row['code']], $row + ['active' => true]);
        }
    }
}
