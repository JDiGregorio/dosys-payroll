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
            ['name' => 'Modalidad 4x4', 'code' => '4x4', 'start_time' => '07:00', 'end_time' => '19:00', 'weekly_hours' => 40, 'daily_hours' => 10, 'description' => 'Jornada 4x4: cuatro días corridos con 10 horas ordinarias y 1 hora extra diaria, seguidos por cuatro días corridos de descanso.'],
            ['name' => 'Rotativa', 'code' => 'rotativa', 'start_time' => '07:00', 'end_time' => '19:00', 'weekly_hours' => 40, 'daily_hours' => 10, 'description' => 'Jornada 4x4: cuatro días corridos con 10 horas ordinarias y 1 hora extra diaria, seguidos por cuatro días corridos de descanso.'],
        ];

        foreach ($rows as $row) {
            ScheduleType::query()->updateOrCreate(['code' => $row['code']], $row + ['active' => true]);
        }
    }
}
