<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\DailyTimeReview;
use App\Models\Employee;
use App\Models\HubstaffTimeEntry;
use App\Models\PayrollPeriod;
use App\Models\TrackabiActivityImportBatch;
use App\Models\TrackabiDailyActivity;
use App\Services\MarcoOctoberSecondHalfOvertimeCorrectionService;
use App\Services\PayrollCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarcoOctoberSecondHalfOvertimeCorrectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_caps_only_three_weekday_overtime_entries_and_survives_recalculation(): void
    {
        config(['trackabi.provider' => 'direct', 'trackabi_direct.cutover_date' => '2026-09-26']);
        $period = PayrollPeriod::query()->create([
            'id' => 11, 'name' => 'October second half', 'starts_at' => '2026-10-11',
            'ends_at' => '2026-10-25', 'status' => 'en_revision',
        ]);
        $campaign = Campaign::query()->create(['name' => 'PALMETTO']);
        $employee = Employee::query()->create([
            'name' => 'Marco Antonio Lara', 'campaign_id' => $campaign->id,
            'trackabi_member_id' => 63567, 'active' => true, 'daily_hours' => 8,
            'ordinary_weekly_hours' => 40, 'preassigned_overtime_weekly_hours' => 10,
            'overtime_distribution_mode' => 'weekly_flexible', 'hourly_rate' => 60,
            'overtime_hourly_rate' => 75,
            'salary_calculation_method' => 'semi_monthly_fixed_with_deductions',
        ]);
        $measured = [
            '2026-10-13' => 27600, '2026-10-14' => 31920,
            '2026-10-15' => 23400, '2026-10-16' => 23400,
            '2026-10-18' => 28320, '2026-10-19' => 27480,
            '2026-10-20' => 27660, '2026-10-21' => 23400,
            '2026-10-22' => 23400, '2026-10-23' => 23400,
            '2026-10-24' => 27960,
        ];
        foreach ($measured as $date => $seconds) {
            HubstaffTimeEntry::query()->create([
                'payroll_period_id' => $period->id, 'employee_id' => $employee->id,
                'hubstaff_member' => $employee->name, 'source_provider' => 'trackabi_api',
                'date' => $date, 'active' => true, 'total_seconds' => $seconds + 5400,
            ]);
        }
        $payroll = app(PayrollCalculationService::class);
        $payroll->generateDailyReviews($period);
        foreach ($measured as $date => $seconds) {
            $batch = TrackabiActivityImportBatch::query()->create([
                'payroll_period_id' => $period->id, 'activity_date' => $date,
                'filename' => $date.'.csv', 'file_hash' => hash('sha256', $date),
                'rows' => 1, 'matched' => 1, 'unmatched' => 0, 'imported_at' => now(),
            ]);
            TrackabiDailyActivity::query()->create([
                'payroll_period_id' => $period->id, 'employee_id' => $employee->id,
                'import_batch_id' => $batch->id, 'activity_date' => $date,
                'productive_activity_seconds' => $seconds,
                'unproductive_activity_seconds' => 0, 'measured_activity_seconds' => $seconds,
                'imported_at' => now(),
            ]);
            $this->review($period, $employee, $date)->update([
                'lost_time_source' => 'trackabi_insights_activity',
                'lost_time_estimate_metadata' => ['activity_source' => 'trackabi_insights_csv'],
                'status' => 'revisado_supervisor',
            ]);
        }
        foreach (['2026-10-11', '2026-10-12', '2026-10-17', '2026-10-25'] as $date) {
            $this->review($period, $employee, $date)->update([
                'paid_day_off' => true, 'status' => 'revisado_supervisor',
            ]);
        }
        $payroll->recalculateEmployeePreservingManual($period, $employee);
        $service = app(MarcoOctoberSecondHalfOvertimeCorrectionService::class);
        $this->assertSame([4200, 4080, 3960], array_column($service->preview($period), 'current_overtime_seconds'));
        $weekendBefore = [
            $this->review($period, $employee, '2026-10-18')->possible_overtime_seconds,
            $this->review($period, $employee, '2026-10-24')->possible_overtime_seconds,
        ];

        $this->assertSame(3, $service->apply($period));
        $this->assertSame(0, $service->apply($period));
        $payroll->recalculateEmployeePreservingManual($period, $employee);

        foreach (['2026-10-13', '2026-10-19', '2026-10-20'] as $date) {
            $review = $this->review($period, $employee, $date);
            $this->assertSame(3600, (int) $review->possible_overtime_seconds);
            $this->assertSame(32400, (int) $review->payable_seconds);
            $this->assertSame($measured[$date], (int) $review->trackabiActivity->measured_activity_seconds);
        }
        $this->assertSame($weekendBefore, [
            $this->review($period, $employee, '2026-10-18')->possible_overtime_seconds,
            $this->review($period, $employee, '2026-10-24')->possible_overtime_seconds,
        ]);

        $result = \App\Models\PayrollResult::query()->where('payroll_period_id', $period->id)
            ->where('employee_id', $employee->id)->sole();
        $this->assertSame('15.00', (string) $result->worked_days);
        $amountsBefore = $result->only(['worked_salary_amount', 'overtime_amount', 'gross_amount', 'net_amount']);
        $result->update(['worked_days' => 13]);
        $this->assertSame(['before' => '13.00', 'after' => '15.00'], $service->refreshWorkedDays($period));
        $result->refresh();
        $this->assertSame($amountsBefore, $result->only(array_keys($amountsBefore)));
    }

    private function review(PayrollPeriod $period, Employee $employee, string $date): DailyTimeReview
    {
        return DailyTimeReview::query()->where('payroll_period_id', $period->id)
            ->where('employee_id', $employee->id)->whereDate('date', $date)->sole();
    }
}
