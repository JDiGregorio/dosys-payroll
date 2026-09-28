<?php

namespace Tests\Feature;

use App\Filament\Resources\DailyTimeReviews\DailyTimeReviewResource;
use App\Models\DailyTimeReview;
use App\Models\Employee;
use App\Models\HubstaffTimeEntry;
use App\Models\PayrollPeriod;
use App\Models\PayrollResult;
use App\Services\PayrollCalculationService;
use App\Services\Trackabi\HistoricalLostTimeEstimator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HistoricalLostTimeEstimatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_estimate_is_provisional_irregular_audited_and_survives_recalculation(): void
    {
        $history = PayrollPeriod::create(['name' => 'Hubstaff history', 'starts_at' => '2026-08-10', 'ends_at' => '2026-08-21']);
        $period = PayrollPeriod::create(['name' => 'September', 'starts_at' => '2026-09-11', 'ends_at' => '2026-09-25', 'limit_payable_to_schedule' => true]);
        $employee = Employee::create(['name' => 'Historical test', 'daily_hours' => 8, 'hourly_rate' => 10, 'salary_calculation_method' => 'hourly_actual_hours']);
        config(['trackabi.historical_loss_period_ids' => [$history->id], 'trackabi.estimated_loss_max_minutes' => 28]);

        foreach (['2026-08-10', '2026-08-11', '2026-08-12', '2026-08-13', '2026-08-14', '2026-08-17', '2026-08-18', '2026-08-19', '2026-08-20', '2026-08-21'] as $index => $date) {
            $seconds = $index < 5 ? 27000 : 28800;
            DailyTimeReview::create(['employee_id' => $employee->id, 'payroll_period_id' => $history->id,
                'date' => $date, 'scheduled_work_day' => true, 'expected_hubstaff_seconds' => 28800,
                'hubstaff_total_seconds' => $seconds]);
            HubstaffTimeEntry::create(['employee_id' => $employee->id, 'payroll_period_id' => $history->id,
                'date' => $date, 'hubstaff_member' => $employee->name, 'source_provider' => 'hubstaff_csv',
                'active' => true, 'total_seconds' => $seconds]);
        }

        $dates = ['2026-09-11', '2026-09-14', '2026-09-15', '2026-09-16', '2026-09-17'];
        foreach ($dates as $date) {
            $seconds = $date === '2026-09-17' ? 27000 : 33600;
            DailyTimeReview::create(['employee_id' => $employee->id, 'payroll_period_id' => $period->id,
                'date' => $date, 'scheduled_work_day' => true, 'expected_ordinary_seconds' => 28800,
                'expected_hubstaff_seconds' => 28800, 'expected_paid_seconds' => 28800,
                'hubstaff_total_seconds' => $seconds, 'status' => 'pendiente']);
            HubstaffTimeEntry::create(['employee_id' => $employee->id, 'payroll_period_id' => $period->id,
                'date' => $date, 'hubstaff_member' => $employee->name, 'source_provider' => 'trackabi',
                'active' => true, 'total_seconds' => $seconds]);
        }

        $estimator = app(HistoricalLostTimeEstimator::class);
        $plan = $estimator->plan($period);
        $this->assertCount(2, $plan);
        $this->assertSame(5, $plan->first()['metadata']['short_days']);
        $this->assertSame(10, $plan->first()['metadata']['sample_days']);
        $this->assertTrue($plan->every(fn (array $row): bool => $row['estimated_lost_seconds'] > 0 && $row['estimated_lost_seconds'] <= 1680));
        $this->assertCount(2, $plan->pluck('estimated_lost_seconds')->unique());
        $protected = DailyTimeReview::findOrFail($plan->last()['review_id']);
        $protected->update(['status' => 'revisado_supervisor']);
        $this->assertCount(1, $estimator->plan($period));
        $protected->update(['status' => 'pendiente', 'paid_day_off' => true]);
        $this->assertCount(1, $estimator->plan($period));
        $protected->update(['paid_day_off' => false]);
        $this->assertSame(0, Artisan::call('payroll:estimate-trackabi-loss', ['--period' => $period->id]));
        $this->assertSame(0, (int) DailyTimeReview::where('payroll_period_id', $period->id)->sum('estimated_lost_seconds'));
        $this->assertSame(2, $estimator->apply($period, $plan));
        $this->assertCount(0, $estimator->plan($period));

        $review = DailyTimeReview::findOrFail($plan->first()['review_id']);
        $estimated = $review->estimated_lost_seconds;
        $this->assertSame(33600, $review->hubstaff_total_seconds);
        $this->assertSame(28800 - $estimated, $review->payrollTrackedSeconds());
        $this->assertSame($estimated, $review->finalEstimatedLostSeconds());
        $lostBeforeJustification = (int) PayrollResult::where('payroll_period_id', $period->id)->where('employee_id', $employee->id)->value('lost_time_seconds');
        $this->assertGreaterThanOrEqual($estimated, $lostBeforeJustification);
        $this->assertSame(1, DB::table('daily_review_lost_time_events')->where('daily_time_review_id', $review->id)->count());
        $initialEvent = DB::table('daily_review_lost_time_events')->where('daily_time_review_id', $review->id)->first();
        $this->assertSame(0, (int) (json_decode($initialEvent->before_state, true)['estimated_lost_seconds'] ?? 0));
        $this->assertSame($estimated, (int) json_decode($initialEvent->after_state, true)['estimated_lost_seconds']);
        $this->assertSame(0, Artisan::call('payroll:estimate-trackabi-loss', ['--period' => $period->id]));
        $this->assertSame(0, (int) DailyTimeReview::where('payroll_period_id', $period->id)->whereDate('date', '2026-09-17')->value('estimated_lost_seconds'));
        $this->assertSame(27000, (int) DailyTimeReview::where('payroll_period_id', $period->id)->whereDate('date', '2026-09-17')->value('hubstaff_total_seconds'));

        $half = (int) floor($estimated / 120) * 60;
        $review->update(['justified_absence_seconds' => $half, 'status' => 'revisado_supervisor']);
        app(PayrollCalculationService::class)->recalculateEmployeePreservingManual($period, $employee);
        $this->assertSame($estimated - $half, $review->fresh()->finalEstimatedLostSeconds());
        $this->assertSame($lostBeforeJustification - $half, (int) PayrollResult::where('payroll_period_id', $period->id)->where('employee_id', $employee->id)->value('lost_time_seconds'));

        $review->refresh();
        $review->update(['justified_absence_seconds' => $estimated]);
        app(PayrollCalculationService::class)->recalculateEmployeePreservingManual($period, $employee);
        $this->assertSame(0, $review->fresh()->finalEstimatedLostSeconds());
        $this->assertSame($lostBeforeJustification - $estimated, (int) PayrollResult::where('payroll_period_id', $period->id)->where('employee_id', $employee->id)->value('lost_time_seconds'));
        $this->assertSame(33600, (int) HubstaffTimeEntry::where('payroll_period_id', $period->id)->whereDate('date', $review->date)->value('total_seconds'));
        $this->assertGreaterThanOrEqual(3, DB::table('daily_review_lost_time_events')->where('daily_time_review_id', $review->id)->count());
    }

    public function test_supervisor_can_reduce_or_increase_estimate_without_changing_raw_time(): void
    {
        $period = PayrollPeriod::create(['name' => 'September', 'starts_at' => '2026-09-11', 'ends_at' => '2026-09-25']);
        $employee = Employee::create(['name' => 'Adjustment test', 'daily_hours' => 8, 'hourly_rate' => 10]);
        $review = DailyTimeReview::create(['employee_id' => $employee->id, 'payroll_period_id' => $period->id,
            'date' => '2026-09-11', 'expected_ordinary_seconds' => 28800,
            'expected_hubstaff_seconds' => 28800, 'expected_paid_seconds' => 28800,
            'hubstaff_total_seconds' => 33600, 'estimated_lost_seconds' => 2400,
            'lost_time_source' => 'historical_estimate']);

        $data = DailyTimeReviewResource::secondsFromHourStates([
            'supervisor_lost_hours' => '0:15', 'justified_lost_time_hours' => '0:10',
        ], $review);
        $review->update($data);
        $this->assertSame(-1500, $review->fresh()->supervisor_adjustment_seconds);
        $this->assertSame(300, $review->fresh()->finalEstimatedLostSeconds());
        $this->assertSame('supervisor_adjustment', $review->fresh()->lost_time_source);
        $this->assertSame(33600, $review->fresh()->hubstaff_total_seconds);
    }

    public function test_estimate_and_justification_reduce_unfulfilled_preassigned_overtime_once(): void
    {
        $period = PayrollPeriod::create(['name' => 'September', 'starts_at' => '2026-09-11', 'ends_at' => '2026-09-25', 'limit_payable_to_schedule' => true]);
        $employee = Employee::create(['name' => 'Overtime test', 'daily_hours' => 8, 'hourly_rate' => 10,
            'overtime_hours' => 5, 'overtime_hourly_rate' => 12.5]);
        $review = DailyTimeReview::create(['employee_id' => $employee->id, 'payroll_period_id' => $period->id,
            'date' => '2026-09-11', 'scheduled_work_day' => true, 'hubstaff_total_seconds' => 36000,
            'estimated_lost_seconds' => 1800, 'lost_time_source' => 'historical_estimate']);

        $service = app(PayrollCalculationService::class);
        $service->recalculateDailyReview($review);
        $service->recalculateEmployeePayrollResult($period, $employee);

        $this->assertSame(36000, $review->fresh()->hubstaff_total_seconds);
        $this->assertSame(32400, $review->fresh()->expected_hubstaff_seconds);
        $this->assertSame(30600, $review->fresh()->payable_seconds);
        $this->assertSame(1800, $review->fresh()->possible_overtime_seconds);
        $this->assertSame(1800, (int) PayrollResult::where('payroll_period_id', $period->id)->where('employee_id', $employee->id)->value('preassigned_overtime_seconds'));

        $review->update(['justified_absence_seconds' => 1800, 'status' => 'revisado_supervisor']);
        $service->recalculateDailyReview($review);
        $service->recalculateEmployeePayrollResult($period, $employee);
        $this->assertSame(32400, $review->fresh()->payable_seconds);
        $this->assertSame(3600, $review->fresh()->possible_overtime_seconds);
        $this->assertSame(0, $review->fresh()->finalEstimatedLostSeconds());
    }
}
