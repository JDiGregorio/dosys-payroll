<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Employee extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'weekly_hours' => 'decimal:2',
            'daily_hours' => 'decimal:2',
            'calendar_days' => 'decimal:2',
            'monthly_salary' => 'decimal:2',
            'daily_rate' => 'decimal:4',
            'overtime_hours' => 'decimal:2',
            'hourly_rate' => 'decimal:4',
            'overtime_hourly_rate' => 'decimal:4',
            'monthly_overtime_amount' => 'decimal:2',
            'base_salary' => 'decimal:2',
            'expected_days' => 'decimal:2',
            'expected_total' => 'decimal:2',
            'qa_bonus' => 'decimal:2',
            'productivity_bonus' => 'decimal:2',
            'time_management_bonus' => 'decimal:2',
            'can_work_overtime' => 'boolean',
            'internet_subsidy_amount' => 'decimal:2',
            'applies_private_insurance' => 'boolean',
            'applies_ihss' => 'boolean',
            'applies_isr' => 'boolean',
            'applies_rap' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function workRole(): BelongsTo
    {
        return $this->belongsTo(WorkRole::class);
    }

    public function tierLevel(): BelongsTo
    {
        return $this->belongsTo(TierLevel::class);
    }

    public function scheduleType(): BelongsTo
    {
        return $this->belongsTo(ScheduleType::class);
    }

    public function contractType(): BelongsTo
    {
        return $this->belongsTo(ContractType::class);
    }

    public function supervisorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_user_id');
    }

    public function userAccount(): HasOne
    {
        return $this->hasOne(User::class);
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

    public function payrollResults(): HasMany
    {
        return $this->hasMany(PayrollResult::class);
    }

    public function nameMappings(): HasMany
    {
        return $this->hasMany(EmployeeNameMapping::class);
    }

    public function employeeDeductions(): HasMany
    {
        return $this->hasMany(EmployeeDeduction::class);
    }

    public function additionalDeductions(): HasMany
    {
        return $this->hasMany(EmployeeAdditionalDeduction::class);
    }

    public function payrollDeductions(): HasMany
    {
        return $this->hasMany(PayrollDeduction::class);
    }

    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        return $query->when(
            $user?->isSupervisor(),
            fn (Builder $query) => $query->where('supervisor_user_id', $user->id),
        );
    }
}
