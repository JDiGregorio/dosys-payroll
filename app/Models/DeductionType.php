<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeductionType extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'default_amount' => 'decimal:2',
            'default_percentage' => 'decimal:4',
            'active' => 'boolean',
        ];
    }

    public function employeeDeductions(): HasMany
    {
        return $this->hasMany(EmployeeDeduction::class);
    }

    public function payrollDeductions(): HasMany
    {
        return $this->hasMany(PayrollDeduction::class);
    }
}
