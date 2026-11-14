<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('deduction_types')->where('code', 'lunch')->exists()) {
            DB::table('deduction_types')->insert([
                'name' => 'Almuerzo',
                'code' => 'lunch',
                'default_amount' => 0,
                'default_percentage' => 0,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $typeId = DB::table('deduction_types')->where('code', 'lunch')->value('id');
        if ($typeId && ! DB::table('payroll_deductions')->where('deduction_type_id', $typeId)->exists()
            && ! DB::table('employee_deductions')->where('deduction_type_id', $typeId)->exists()) {
            DB::table('deduction_types')->where('id', $typeId)->delete();
        }
    }
};
