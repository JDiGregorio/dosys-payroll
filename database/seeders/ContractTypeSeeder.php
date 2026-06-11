<?php

namespace Database\Seeders;

use App\Models\ContractType;
use Illuminate\Database\Seeder;

class ContractTypeSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['name' => 'Por hora', 'code' => 'hourly', 'min_weekly_hours' => null, 'max_weekly_hours' => 32, 'description' => 'Contrato por hora, normalmente menor o igual a 32 horas semanales.'],
            ['name' => 'Permanente', 'code' => 'permanent', 'min_weekly_hours' => 33, 'max_weekly_hours' => null, 'description' => 'Contrato permanente, normalmente entre 33 y 40 horas semanales, aunque algunos empleados pueden cubrir más horas.'],
            ['name' => 'Periodo de prueba', 'code' => 'trial_period', 'min_weekly_hours' => null, 'max_weekly_hours' => null, 'description' => 'Contrato asignado a empleados nuevos en Tier 1.'],
        ];

        foreach ($rows as $row) {
            ContractType::query()->updateOrCreate(['code' => $row['code']], $row + ['active' => true]);
        }
    }
}
