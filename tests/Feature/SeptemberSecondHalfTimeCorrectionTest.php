<?php

namespace Tests\Feature;

use App\Models\DailyTimeReview;
use App\Models\Employee;
use App\Models\HubstaffTimeEntry;
use App\Models\PayrollBonus;
use App\Models\PayrollPeriod;
use App\Models\PayrollResult;
use App\Models\ScheduleType;
use App\Models\WorkScheduleTemplate;
use App\Services\PayrollCalculationService;
use App\Services\SeptemberSecondHalfTimeCorrectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class SeptemberSecondHalfTimeCorrectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_verified_times_and_weekend_overtime_survive_recalculation_without_changing_reviews_or_raw_entries(): void
    {
        foreach (range(1, 7) as $number) {
            PayrollPeriod::create(['name' => "Previous {$number}", 'starts_at' => '2026-01-01', 'ends_at' => '2026-01-01']);
        }
        $previous = PayrollPeriod::create(['name' => 'Previous half', 'starts_at' => '2026-08-26', 'ends_at' => '2026-09-10']);
        $period = PayrollPeriod::create(['name' => 'September half', 'starts_at' => '2026-09-11', 'ends_at' => '2026-09-25', 'status' => 'en_revision', 'limit_payable_to_schedule' => true]);
        $schedule = ScheduleType::create(['name' => 'Diurna', 'code' => 'diurna', 'active' => true]);
        $template = WorkScheduleTemplate::create(['name' => 'Monday to Friday', 'schedule_type' => 'diurna', 'active' => true]);
        foreach (range(1, 5) as $day) {
            $template->days()->create(['day_number' => $day, 'expected_seconds' => 28800, 'is_working_day' => true]);
        }
        $elalf = Employee::create(['name' => 'Elalf Shamir Dominguez Pineda', 'active' => true,
            'schedule_type_id' => $schedule->id, 'work_schedule_template_id' => $template->id,
            'daily_hours' => 8, 'ordinary_weekly_hours' => 40, 'preassigned_overtime_weekly_hours' => 5,
            'hourly_rate' => 62.5, 'overtime_hourly_rate' => 78.125]);
        $marco = Employee::create(['name' => 'Marco Antonio Lara', 'active' => true,
            'schedule_type_id' => $schedule->id, 'daily_hours' => 8,
            'ordinary_weekly_hours' => 40, 'preassigned_overtime_weekly_hours' => 10,
            'salary_calculation_method' => 'hourly_actual_hours',
            'hourly_rate' => 79.1667, 'overtime_hourly_rate' => 98.9625]);

        foreach (['2026-09-07' => 5520, '2026-09-08' => 4620, '2026-09-10' => 4500] as $date => $paidOvertime) {
            DailyTimeReview::create(['payroll_period_id' => $previous->id, 'employee_id' => $marco->id,
                'date' => $date, 'preassigned_overtime_seconds' => 7200,
                'possible_overtime_seconds' => $paidOvertime]);
        }

        foreach (['2026-09-12' => 28740, '2026-09-19' => 36000] as $date => $seconds) {
            $this->entry($period, $elalf, $date, $seconds);
        }
        $marcoTimes = [
            '2026-09-11' => '08:00', '2026-09-12' => '08:00', '2026-09-13' => '01:38',
            '2026-09-14' => '09:36', '2026-09-15' => '08:39', '2026-09-16' => '06:14',
            '2026-09-17' => '08:00', '2026-09-18' => '07:41', '2026-09-19' => '02:00',
            '2026-09-20' => '05:22', '2026-09-21' => '08:29', '2026-09-22' => '07:45',
            '2026-09-23' => '07:49', '2026-09-24' => '07:45', '2026-09-25' => '09:49',
        ];
        foreach ($marcoTimes as $date => $time) {
            [$hours, $minutes] = array_map('intval', explode(':', $time));
            $this->entry($period, $marco, $date, $hours * 3600 + $minutes * 60 + 60);
        }

        $payroll = app(PayrollCalculationService::class);
        $payroll->generateDailyReviews($period);
        $marcoSixteenth = $this->review($period, $marco, '2026-09-16');
        $marcoSixteenth->update(['status' => 'revisado_supervisor', 'justified_absence_seconds' => 6060,
            'supervisor_comment' => 'Justificacion previa']);
        PayrollBonus::create(['payroll_period_id' => $period->id, 'employee_id' => $marco->id,
            'scope_type' => 'employee', 'type' => 'manual', 'amount' => 25, 'status' => 'aprobado']);

        $correction = app(SeptemberSecondHalfTimeCorrectionService::class);
        $this->assertCount(17, $correction->preview($period));
        $this->assertSame(0, Artisan::call('payroll:correct-september-second-half-tracking', ['--period' => $period->id]));
        $this->assertNull($this->review($period, $elalf, '2026-09-12')->verified_tracked_seconds);
        $this->assertSame(17, $correction->apply($period));

        $elalfSaturday = $this->review($period, $elalf, '2026-09-12');
        $this->assertSame(28740, $elalfSaturday->hubstaff_total_seconds);
        $this->assertSame(32040, $elalfSaturday->verified_tracked_seconds);
        $this->assertSame(28800, $elalfSaturday->expected_ordinary_seconds);
        $this->assertSame(3600, $elalfSaturday->preassigned_overtime_seconds);
        $this->assertSame(32040, $elalfSaturday->payable_seconds);
        $this->assertSame(-360, $elalfSaturday->difference_seconds);
        $this->assertSame(31860, $this->review($period, $elalf, '2026-09-19')->payable_seconds);

        $marcoSaturday = $this->review($period, $marco, '2026-09-12');
        $marcoSunday = $this->review($period, $marco, '2026-09-13');
        $this->assertSame(0, $marcoSaturday->expected_ordinary_seconds);
        $this->assertSame(0, $marcoSunday->expected_ordinary_seconds);
        $this->assertGreaterThan(0, $marcoSaturday->payable_seconds);
        $this->assertGreaterThan(0, $marcoSunday->payable_seconds);
        $this->assertSame(21360, $marcoSaturday->possible_overtime_seconds + $marcoSunday->possible_overtime_seconds);
        $this->assertSame(34620, (int) DailyTimeReview::where('payroll_period_id', $period->id)
            ->where('employee_id', $marco->id)->whereDate('date', '>=', '2026-09-14')->whereDate('date', '<=', '2026-09-20')
            ->sum('possible_overtime_seconds'));
        $this->assertSame(28500, $this->review($period, $marco, '2026-09-16')->payable_seconds);
        $this->assertSame('revisado_supervisor', $this->review($period, $marco, '2026-09-16')->status);
        $this->assertSame('Justificacion previa', $this->review($period, $marco, '2026-09-16')->supervisor_comment);
        $this->assertSame(25.0, (float) PayrollBonus::where('payroll_period_id', $period->id)->where('employee_id', $marco->id)->value('amount'));
        $this->assertSame(28860, (int) HubstaffTimeEntry::where('payroll_period_id', $period->id)
            ->where('employee_id', $marco->id)->whereDate('date', '2026-09-12')->value('total_seconds'));
        $this->assertSame(0, $correction->apply($period));

        $payroll->recalculatePeriodPreservingManual($period);
        $this->assertSame(21360, $this->review($period, $marco, '2026-09-12')->possible_overtime_seconds
            + $this->review($period, $marco, '2026-09-13')->possible_overtime_seconds);
        $this->assertSame(32040, $this->review($period, $elalf, '2026-09-12')->payable_seconds);
        $this->assertSame(28500, $this->review($period, $marco, '2026-09-16')->payable_seconds);
        $this->assertNotNull(PayrollResult::where('payroll_period_id', $period->id)->where('employee_id', $marco->id)->first());
    }

    private function entry(PayrollPeriod $period, Employee $employee, string $date, int $seconds): void
    {
        HubstaffTimeEntry::create(['payroll_period_id' => $period->id, 'employee_id' => $employee->id,
            'hubstaff_member' => $employee->name, 'source_provider' => 'trackabi',
            'date' => $date, 'active' => true, 'total_seconds' => $seconds]);
    }

    private function review(PayrollPeriod $period, Employee $employee, string $date): DailyTimeReview
    {
        return DailyTimeReview::where('payroll_period_id', $period->id)
            ->where('employee_id', $employee->id)->whereDate('date', $date)->sole();
    }
}
