<?php

namespace Database\Seeders;

use App\Models\PayrollPeriod;
use Illuminate\Database\Seeder;

class DemoPayrollPeriodSeeder extends Seeder
{
    public function run(): void
    {
        PayrollPeriod::query()->firstOrCreate([
            'name' => 'Periodo demo',
        ], [
            'starts_at' => now()->startOfMonth()->toDateString(),
            'ends_at' => now()->endOfMonth()->toDateString(),
            'status' => 'borrador',
        ]);
    }
}
