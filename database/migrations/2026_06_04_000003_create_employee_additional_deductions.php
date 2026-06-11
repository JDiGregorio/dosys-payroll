<?php

use App\Models\DeductionType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DeductionType::query()->updateOrCreate(['code' => 'additional'], [
            'name' => 'Deducciones adicionales',
            'calculation_type' => 'manual',
            'default_amount' => 0,
            'default_percentage' => 0,
            'active' => true,
        ]);

        Schema::create('employee_additional_deductions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payroll_period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('description');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_additional_deductions');
    }
};
