<?php

namespace Database\Seeders;

use App\Models\WorkScheduleTemplate;
use Illuminate\Database\Seeder;

class WorkScheduleTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            [
                'name' => 'Diurna 40h - 5 días x 8h',
                'schedule_type' => 'diurna',
                'description' => 'Cinco días laborables de ocho horas ordinarias.',
                'days' => [8, 8, 8, 8, 8],
            ],
            [
                'name' => 'Diurna 32h - patrón personalizado',
                'schedule_type' => 'diurna',
                'description' => 'Plantilla inicial editable para empleados de 32 horas.',
                'days' => [6.4, 6.4, 6.4, 6.4, 6.4],
            ],
            [
                'name' => 'Diurna 36h - 4 días 7h + 1 día 8h',
                'legacy_names' => ['Diurna 36h - 4 días 7.2h + 1 día 8h'],
                'schedule_type' => 'diurna',
                'description' => 'Patrón exacto de cuatro días de 7 horas y un día de 8 horas.',
                'days' => [7, 7, 7, 7, 8],
            ],
            [
                'name' => 'Diurna 36h - lunes 8h',
                'schedule_type' => 'diurna',
                'description' => 'Patrón 36h con 8 horas el lunes y 7 horas los demás días laborables.',
                'days' => [8, 7, 7, 7, 7],
            ],
            [
                'name' => 'Diurna 36h - martes 8h',
                'schedule_type' => 'diurna',
                'description' => 'Patrón 36h con 8 horas el martes y 7 horas los demás días laborables.',
                'days' => [7, 8, 7, 7, 7],
            ],
            [
                'name' => 'Diurna 36h - miércoles 8h',
                'schedule_type' => 'diurna',
                'description' => 'Patrón 36h con 8 horas el miércoles y 7 horas los demás días laborables.',
                'days' => [7, 7, 8, 7, 7],
            ],
            [
                'name' => 'Diurna 36h - jueves 8h',
                'schedule_type' => 'diurna',
                'description' => 'Patrón 36h con 8 horas el jueves y 7 horas los demás días laborables.',
                'days' => [7, 7, 7, 8, 7],
            ],
            [
                'name' => 'Diurna 36h - viernes 8h',
                'schedule_type' => 'diurna',
                'description' => 'Patrón 36h con 8 horas el viernes y 7 horas los demás días laborables.',
                'days' => [7, 7, 7, 7, 8],
            ],
            [
                'name' => 'Rotativa 4x4',
                'schedule_type' => 'rotativa',
                'description' => 'Cuatro días corridos de trabajo y cuatro días corridos de descanso.',
                'days' => [11, 11, 11, 11],
            ],
            [
                'name' => 'Nocturna personalizada',
                'schedule_type' => 'nocturna',
                'description' => 'Patrón nocturno inicial editable por RRHH.',
                'days' => [8, 8, 8, 8, 8],
            ],
        ];

        foreach ($templates as $definition) {
            foreach ($definition['legacy_names'] ?? [] as $legacyName) {
                WorkScheduleTemplate::query()
                    ->where('name', $legacyName)
                    ->where('name', '!=', $definition['name'])
                    ->update(['name' => $definition['name']]);
            }

            $template = WorkScheduleTemplate::query()
                ->updateOrCreate(
                    ['name' => $definition['name']],
                    [
                        'schedule_type' => $definition['schedule_type'],
                        'description' => $definition['description'],
                        'active' => true,
                    ],
                );

            foreach ($definition['days'] as $index => $hours) {
                $template->days()->updateOrCreate(
                    ['day_number' => $index + 1],
                    [
                        'expected_seconds' => (int) round($hours * 3600),
                        'is_working_day' => true,
                    ],
                );
            }
        }
    }
}
