<?php

namespace Tests\Feature;

use App\Exports\PayrollResultsExport;
use App\Mail\PayrollVoucherMail;
use App\Models\DailyTimeReview;
use App\Models\DeductionType;
use App\Models\Employee;
use App\Models\PayrollBonus;
use App\Models\PayrollDeduction;
use App\Models\PayrollPeriod;
use App\Models\PayrollResult;
use App\Models\ScheduleType;
use App\Models\TrackabiDailyActivity;
use App\Services\PayrollCalculationService;
use App\Services\Trackabi\TrackabiPaidActivityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NovemberFirstHalfPayrollAdjustmentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['trackabi.provider' => 'direct', 'trackabi_direct.cutover_date' => '2026-09-26']);
    }

    public function test_supervisor_adjustment_is_visible_as_a_negative_difference_without_changing_raw_activity(): void
    {
        $review = new DailyTimeReview([
            'expected_paid_seconds' => 28800,
            'paid_time_not_tracked_seconds' => 4800,
            'supervisor_adjustment_seconds' => 180,
        ]);
        $review->setRelation('trackabiActivity', new TrackabiDailyActivity([
            'measured_activity_seconds' => 26520,
        ]));

        $displayed = app(TrackabiPaidActivityService::class)->displayedReviewedSeconds($review);

        $this->assertSame(28620, $displayed);
        $this->assertSame(-180, $displayed - (int) $review->expected_paid_seconds);
        $this->assertSame(26520, (int) $review->trackabiActivity->measured_activity_seconds);
    }

    public function test_denisha_training_days_pay_thirteen_days_and_later_justification_changes_salary(): void
    {
        $period = PayrollPeriod::query()->create([
            'id' => 12, 'name' => 'Primera quincena de noviembre',
            'starts_at' => '2026-10-26', 'ends_at' => '2026-11-10', 'status' => 'en_revision',
        ]);
        $schedule = ScheduleType::query()->create([
            'name' => 'Rotativa', 'code' => 'rotativa', 'daily_hours' => 11,
            'weekly_hours' => 44, 'active' => true,
        ]);
        $employee = Employee::query()->create([
            'name' => 'Denisha Marissa Buckley Medina', 'active' => true,
            'schedule_type_id' => $schedule->id, 'daily_hours' => 11,
            'paid_hours_per_workday' => 12, 'ordinary_weekly_hours' => 44,
            'preassigned_overtime_weekly_hours' => 4,
            'salary_calculation_method' => 'semi_monthly_fixed_with_deductions',
            'monthly_salary' => 15000, 'daily_rate' => 500,
            'hourly_rate' => 60, 'overtime_hourly_rate' => 75,
        ]);
        $payroll = app(PayrollCalculationService::class);
        $payroll->regenerateEmployeeDailyReviews($period, $employee);

        $work = [
            '2026-10-29' => 7, '2026-10-30' => 7,
            '2026-11-02' => 8, '2026-11-03' => 8, '2026-11-04' => 8,
            '2026-11-06' => 8, '2026-11-07' => 8, '2026-11-08' => 8,
        ];
        foreach ($work as $date => $hours) {
            $review = DailyTimeReview::query()->where('payroll_period_id', $period->id)
                ->where('employee_id', $employee->id)->whereDate('date', $date)->sole();
            $review->update([
                'verified_tracked_seconds' => $hours * 3600,
                'verified_tracked_source' => 'period_12_supervised_training',
                'justified_absence_seconds' => (11 - $hours) * 3600,
                'lost_time_source' => 'supervisor_adjustment',
                'status' => 'revisado_supervisor',
            ]);
        }
        foreach (['2026-10-31', '2026-11-01', '2026-11-05', '2026-11-09', '2026-11-10'] as $date) {
            DailyTimeReview::query()->where('payroll_period_id', $period->id)
                ->where('employee_id', $employee->id)->whereDate('date', $date)
                ->sole()->update(['paid_day_off' => true, 'status' => 'revisado_supervisor']);
        }
        $payroll->recalculateEmployeePreservingManual($period, $employee);
        $result = PayrollResult::query()->where('payroll_period_id', $period->id)
            ->where('employee_id', $employee->id)->sole();

        $this->assertSame(13.0, (float) $result->worked_days);
        $this->assertSame(6500.0, (float) $result->worked_salary_amount);
        $this->assertSame(0, (int) $result->preassigned_overtime_seconds);
        $this->assertSame(0.0, (float) $result->overtime_amount);
        $this->assertSame(0, (int) DailyTimeReview::query()->where('payroll_period_id', $period->id)
            ->where('employee_id', $employee->id)->whereDate('date', '2026-10-26')->sole()->payable_seconds);

        $novemberSecond = DailyTimeReview::query()->where('payroll_period_id', $period->id)
            ->where('employee_id', $employee->id)->whereDate('date', '2026-11-02')->sole();
        $novemberSecond->update(['justified_absence_seconds' => 7200]);
        $payroll->recalculateEmployeePreservingManual($period, $employee);

        $this->assertLessThan(6500, (float) $result->fresh()->worked_salary_amount);
        $this->assertSame(7200, (int) $novemberSecond->fresh()->justified_absence_seconds);
    }

    public function test_only_preassigned_overtime_rounds_from_thirty_minutes_in_new_period(): void
    {
        $period = PayrollPeriod::query()->create([
            'id' => 12, 'name' => 'Primera quincena de noviembre',
            'starts_at' => '2026-10-26', 'ends_at' => '2026-11-10', 'status' => 'en_revision',
        ]);
        $payroll = app(PayrollCalculationService::class);
        foreach ([5, 0] as $weeklyOvertime) {
            $employee = Employee::query()->create([
                'name' => "Agente OT {$weeklyOvertime}", 'active' => true,
                'daily_hours' => 8, 'ordinary_weekly_hours' => 40,
                'preassigned_overtime_weekly_hours' => $weeklyOvertime,
                'hourly_rate' => 60, 'overtime_hourly_rate' => 75,
            ]);
            $payroll->regenerateEmployeeDailyReviews($period, $employee);
            $review = DailyTimeReview::query()->where('payroll_period_id', $period->id)
                ->where('employee_id', $employee->id)->whereDate('date', '2026-10-26')->sole();
            $review->update(['verified_tracked_seconds' => 8 * 3600 + 35 * 60]);
            $payroll->recalculateEmployeePreservingManual($period, $employee);
            $this->assertSame($weeklyOvertime ? 3600 : 0, (int) $review->fresh()->possible_overtime_seconds);

            if ($weeklyOvertime) {
                $review->update(['verified_tracked_seconds' => 8 * 3600 + 25 * 60]);
                $payroll->recalculateEmployeePreservingManual($period, $employee);
                $this->assertSame(0, (int) $review->fresh()->possible_overtime_seconds);
            }
        }
    }

    public function test_marketing_lunch_and_rap_have_distinct_voucher_and_export_lines(): void
    {
        $period = PayrollPeriod::query()->create([
            'id' => 12, 'name' => 'Primera quincena de noviembre',
            'starts_at' => '2026-10-26', 'ends_at' => '2026-11-10', 'status' => 'en_revision',
        ]);
        $employee = Employee::query()->create(['name' => 'Agente Test', 'active' => true]);
        PayrollBonus::withoutEvents(fn () => PayrollBonus::query()->create([
            'payroll_period_id' => $period->id, 'employee_id' => $employee->id,
            'scope_type' => 'employee', 'type' => 'marketing',
            'description' => 'Bono de Marketing', 'amount' => 500, 'status' => 'aprobado',
        ]));
        $lunch = DeductionType::query()->where('code', 'lunch')->sole();
        $rap = DeductionType::query()->firstOrCreate(['code' => 'rap'], [
            'name' => 'RAP', 'default_amount' => 0, 'default_percentage' => 0, 'active' => true,
        ]);
        foreach ([[$lunch->id, 100], [$rap->id, 35]] as [$typeId, $amount]) {
            PayrollDeduction::query()->create([
                'payroll_period_id' => $period->id, 'employee_id' => $employee->id,
                'deduction_type_id' => $typeId, 'amount' => $amount, 'status' => 'aprobado',
            ]);
        }
        $result = PayrollResult::query()->create([
            'payroll_period_id' => $period->id, 'employee_id' => $employee->id,
            'extra_bonuses_amount' => 500, 'lunch_deduction_amount' => 100,
            'rap_amount' => 35, 'total_deductions_amount' => 135,
        ]);
        $html = (new PayrollVoucherMail($result->load(['employee', 'payrollPeriod'])))->render();
        $this->assertStringContainsString('Bono de Marketing', $html);
        $this->assertStringContainsString('Almuerzo', $html);
        $this->assertStringContainsString('RAP', $html);

        $export = new PayrollResultsExport($period);
        $headings = $export->headings();
        $values = $export->map($result->fresh('employee'));
        $this->assertCount(count($headings), $values);
        $this->assertSame(500.0, (float) $values[array_search('Bono de Marketing', $headings, true)]);
        $this->assertSame(100.0, (float) $values[array_search('Almuerzo', $headings, true)]);
        $this->assertSame(35.0, (float) $values[array_search('RAP', $headings, true)]);
        $this->assertSame('', $values[array_search('Otros bonos (detalle)', $headings, true)]);
    }
}
