<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
            'estimated_lost_seconds' => 'integer',
            'supervisor_adjustment_seconds' => 'integer',
            'lost_time_estimate_metadata' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updated(function (self $review): void {
            $fields = ['estimated_lost_seconds', 'supervisor_adjustment_seconds', 'justified_absence_seconds', 'lost_time_source'];
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

    public function finalEstimatedLostSeconds(): int
    {
        return max(0, $this->provisionalLostSeconds() - max((int) $this->justified_absence_seconds, 0));
    }

    public function payrollTrackedSeconds(): int
    {
        $raw = max((int) $this->hubstaff_total_seconds, 0);
        if (! $this->lost_time_source || $raw <= 0) {
            return $raw;
        }

        $expected = max((int) $this->expected_hubstaff_seconds, 0);
        if ($expected <= 0 || $raw < $expected) {
            return $raw;
        }

        return max($expected - $this->provisionalLostSeconds(), 0);
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
