<?php

namespace Database\Seeders;

use App\Models\DeductionType;
use Illuminate\Database\Seeder;

class DeductionTypeSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['name' => 'Pan Ame Seguro', 'code' => 'private_insurance', 'calculation_type' => 'fixed'],
            ['name' => 'IHSS', 'code' => 'ihss', 'calculation_type' => 'fixed'],
            ['name' => 'ISR', 'code' => 'isr', 'calculation_type' => 'manual'],
            ['name' => 'RAP', 'code' => 'rap', 'calculation_type' => 'manual'],
            ['name' => 'VALES', 'code' => 'vouchers', 'calculation_type' => 'manual'],
            ['name' => 'Deducciones adicionales', 'code' => 'additional', 'calculation_type' => 'manual'],
        ];

        foreach ($rows as $row) {
            DeductionType::query()->updateOrCreate(['code' => $row['code']], $row + [
                'default_amount' => 0,
                'default_percentage' => 0,
                'active' => true,
            ]);
        }
    }
}
