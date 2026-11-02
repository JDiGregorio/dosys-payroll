<?php

namespace App\Models;

use App\Services\Trackabi\TrackabiActivityCoverageService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

class DailyTimeReview extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'activity_percentage' => 'decimal:2',
            'idle_percentage' => 'decimal:2',
            'scheduled_work_day' => 'boolean',
            'assigned_overtime_fulfilled' => 'boolean',
            'paid_day_off' => 'boolean',
            'off_worked' => 'boolean',
            'off_worked_verified_seconds' => 'integer',
            'estimated_lost_seconds' => 'integer',
            'supervisor_adjustment_seconds' => 'integer',
            'lost_time_estimate_metadata' => 'array',
            'reviewed_at' => 'datetime',
            'verified_tracked_seconds' => 'integer',
            'verified_tracked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updated(function (self $review): void {
            $fields = ['estimated_lost_seconds', 'supervisor_adjustment_seconds', 'justified_absence_seconds', 'lost_time_source',
                'paid_day_off', 'off_worked', 'off_worked_verified_seconds'];
            if (! $review->wasChanged($fields) || ! $review->lost_time_source) {
                return;
            }

            $before = [];
            $after = [];
            foreach ($fields as $field) {
                $before[$field] = $review->getRawOriginal($field);
                $after[$field] = $review->getAttribute($field);
            }

            DB::table('daily_review_lost_time_events')->insert([
                'daily_time_review_id' => $review->id,
                'actor_user_id' => auth()->id(),
                'source' => $review->lost_time_source,
                'before_state' => json_encode($before),
                'after_state' => json_encode($after),
                'created_at' => now(),
            ]);
        });
    }

    public function payrollPeriod(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class);
    }

    public function hasTrackabiTimer(): bool
    {
        return HubstaffTimeEntry::query()
            ->where('payroll_period_id', $this->payroll_period_id)
            ->where('employee_id', $this->employee_id)
            ->whereDate('date', $this->date)
            ->where('active', true)
            ->whereIn('source_provider', ['trackabi', 'trackabi_api'])
            ->exists();
    }

    public function computableTrackedSeconds(): int
    {
        return min($this->payrollTrackedSeconds(), max((int) $this->expected_hubstaff_seconds, 0));
    }

    public function provisionalLostSeconds(): int
    {
        return max(0, (int) $this->estimated_lost_seconds + (int) $this->supervisor_adjustment_seconds);
    }

    public function offWorkedRequiredSeconds(): int
    {
        $configured = max((int) $this->expected_ordinary_seconds, 0);
        if ($configured > 0) {
            return $configured;
        }

        $employee = $this->employee;
        if (! $employee) {
            return 0;
        }

        return max((int) round(max((float) $employee->paid_hours_per_workday,
            (float) $employee->daily_hours) * 3600), 0);
    }

    public function finalEstimatedLostSeconds(): int
    {
        return max(0, $this->provisionalLostSeconds() - max((int) $this->justified_absence_seconds, 0));
    }

    public function payrollTrackedSeconds(): int
    {
        $raw = max((int) ($this->verified_tracked_seconds ?? $this->hubstaff_total_seconds), 0);
        if ($this->hasUntrackedDirectAbsence()) {
            return 0;
        }
        if ($this->lost_time_source === 'missing_activity') {
            return $this->verified_tracked_seconds !== null
                ? $raw
                : max((int) $this->expected_hubstaff_seconds - ($this->status === 'pendiente'
                    ? 0 : max((int) $this->unjustified_absence_seconds, 0)), 0);
        }
        if ($this->usesMeasuredActivity()) {
            return $this->verified_tracked_seconds !== null
                ? $raw
                : ($this->status === 'pendiente' && $this->trackabiActivity
                    ? max((int) $this->trackabiActivity->measured_activity_seconds, 0)
                    : max((int) $this->expected_hubstaff_seconds - $this->provisionalLostSeconds(), 0));
        }
        if ($this->payrollPeriod
            && app(TrackabiActivityCoverageService::class)->applies($this->payrollPeriod)) {
            return $this->verified_tracked_seconds !== null
                ? $raw
                : max((int) $this->expected_hubstaff_seconds - ($this->status === 'pendiente'
                    ? 0 : max($this->provisionalLostSeconds(), (int) $this->unjustified_absence_seconds)), 0);
        }
        if (! $this->lost_time_source || $raw <= 0) {
            return $raw;
        }

        $expected = max((int) $this->expected_hubstaff_seconds, 0);
        if ($expected <= 0 || $raw < $expected) {
            return $raw;
        }

        return max($expected - $this->provisionalLostSeconds(), 0);
    }

    public function usesMeasuredActivity(): bool
    {
        return $this->lost_time_source !== null
            && isset($this->lost_time_estimate_metadata['activity_source']);
    }

    public function hasUntrackedDirectAbsence(): bool
    {
        $period = $this->payrollPeriod;
        if (! $period || ! app(TrackabiActivityCoverageService::class)->applies($period)
            || $this->paid_day_off
            || $this->employee?->paid_without_tracking
            || $this->untrackedDayReviewSeconds() <= 0
            || $this->verified_tracked_seconds !== null
            || (int) $this->hubstaff_total_seconds > 0
            || $this->hasTrackabiTimer()) {
            return false;
        }

        return ! $this->trackabiActivity
            || (int) $this->trackabiActivity->measured_activity_seconds === 0;
    }

    public function untrackedDayReviewSeconds(): int
    {
        $expected = max((int) $this->expected_ordinary_seconds, 0);
        if ($expected > 0) {
            return $expected;
        }

        $period = $this->payrollPeriod;
        $employee = $this->employee;
        if (! $period || ! app(TrackabiActivityCoverageService::class)->applies($period)
            || ! $employee || ! $this->scheduled_work_day
            || $employee->scheduleType?->code === 'rotativa') {
            return 0;
        }

        $hours = (float) $employee->daily_hours
            ?: (((float) $employee->ordinary_weekly_hours ?: (float) $employee->weekly_hours) / 5);

        return max((int) round($hours * 3600), 0);
    }

    public function trackabiActivity(): HasOne
    {
        return $this->hasOne(TrackabiDailyActivity::class, 'employee_id', 'employee_id')
            ->whereDate('activity_date', $this->date)
            ->latest('imported_at');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function overtimeRateType(): BelongsTo
    {
        return $this->belongsTo(HourlyRateType::class, 'overtime_rate_type_id');
    }
}
