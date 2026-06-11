<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollPeriod extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'starts_at' => 'date',
            'ends_at' => 'date',
            'apply_deductions' => 'boolean',
        ];
    }

    public function hubstaffImports(): HasMany
    {
        return $this->hasMany(HubstaffImport::class);
    }

    public function hubstaffTimeEntries(): HasMany
    {
        return $this->hasMany(HubstaffTimeEntry::class);
    }

    public function dailyTimeReviews(): HasMany
    {
        return $this->hasMany(DailyTimeReview::class);
    }

    public function payrollBonuses(): HasMany
    {
        return $this->hasMany(PayrollBonus::class);
    }

    public function payrollOvertimeAdjustments(): HasMany
    {
        return $this->hasMany(PayrollOvertimeAdjustment::class);
    }

    public function payrollDeductions(): HasMany
    {
        return $this->hasMany(PayrollDeduction::class);
    }

    public function deductionTypes(): BelongsToMany
    {
        return $this->belongsToMany(DeductionType::class, 'payroll_period_deduction_type')->withTimestamps();
    }

    public function payrollResults(): HasMany
    {
        return $this->hasMany(PayrollResult::class);
    }
}
