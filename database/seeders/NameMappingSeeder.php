<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\EmployeeNameMapping;
use Illuminate\Database\Seeder;

class NameMappingSeeder extends Seeder
{
    public function run(): void
    {
        // Reemplaza estos ejemplos con los nombres reales tal como aparecen en Hubstaff.
        $mappings = [
            ['hubstaff_member' => 'Empleado Demo', 'employee_name' => 'Empleado Demo'],
        ];

        foreach ($mappings as $mapping) {
            $employee = Employee::query()->where('name', $mapping['employee_name'])->first();

            if (! $employee) {
                continue;
            }

            EmployeeNameMapping::query()->updateOrCreate(['hubstaff_member' => $mapping['hubstaff_member']], [
                'employee_id' => $employee->id,
                'confidence' => 100,
                'is_active' => true,
            ]);
        }
    }
}
