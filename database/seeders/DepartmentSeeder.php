<?php

namespace Database\Seeders;

use App\Models\Department;
use Illuminate\Database\Seeder;

class DepartmentSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['ADMINISTRATION', 'HUMAN RESOURCES', 'OPERATIONS'] as $name) {
            Department::query()->updateOrCreate(['name' => $name], ['active' => true]);
        }
    }
}
