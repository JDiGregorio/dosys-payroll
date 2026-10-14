<?php

namespace Tests\Feature;

use App\Models\DailyTimeReview;
use App\Models\Employee;
use App\Models\HubstaffTimeEntry;
use App\Models\PayrollPeriod;
use App\Models\PayrollResult;
use App\Models\ScheduleType;
use App\Services\OctoberFirstHalfTimeCorrectionService;
use App\Services\PayrollCalculationService;
use App\Services\Trackabi\TrackabiPaidActivityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OctoberFirstHalfTimeCorrectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_period_ten_corrections_are_idempotent_and_survive_full_recalculation(): void
    {
        config(['trackabi.provider' => 'direct', 'trackabi_direct.cutover_date' => '2026-09-26']);
        $period = PayrollPeriod::create(['id' => 10, 'name' => 'October first half',
            'starts_at' => '2026-09-26', 'ends_at' => '2026-10-10', 'status' => 'en_revision']);
        $previous = PayrollPeriod::create(['id' => 9, 'name' => 'September second half',
            'starts_at' => '2026-09-11', 'ends_at' => '2026-09-25', 'status' => 'cerrado']);
        $day = ScheduleType::create(['name' => 'Diurna', 'code' => 'diurna', 'active' => true]);
        $rotation = ScheduleType::create(['name' => 'Rotativa', 'code' => 'rotativa', 'active' => true]);
        $employees = [];
        foreach (['Wilman Josue Elias Agurcia', 'Emely Mejia', 'Edwin Cruz',
            'Elalf Shamir Dominguez Pineda', 'Marco Antonio Lara'] as $name) {
            $rotating = in_array($name, ['Wilman Josue Elias Agurcia', 'Emely Mejia', 'Edwin Cruz'], true);
            $employees[$name] = Employee::create(['name' => $name, 'active' => true,
                'schedule_type_id' => $rotating ? $rotation->id : $day->id,
                'daily_hours' => $rotating ? 11 : 8,
                'ordinary_weekly_hours' => $rotating ? 44 : 40,
                'preassigned_overtime_weekly_hours' => $name === 'Marco Antonio Lara' ? 10 : ($rotating ? 4 : 5),
                'salary_calculation_method' => $name === 'Marco Antonio Lara' ? 'hourly_actual_hours' : 'semi_monthly_fixed_with_deductions',
                'hourly_rate' => 60, 'overtime_hourly_rate' => 75]);
        }

        foreach (['Wilman Josue Elias Agurcia' => ['2026-09-29'], 'Emely Mejia' => ['2026-10-02'],
            'Edwin Cruz' => ['2026-10-04', '2026-10-05', '2026-10-06']] as $name => $dates) {
            foreach ($dates as $date) {
                $this->entry($period, $employees[$name], $date, 7200);
            }
        }
        foreach (['2026-09-26' => 26820, '2026-10-03' => 22260, '2026-10-10' => 29040] as $date => $seconds) {
            $this->entry($period, $employees['Elalf Shamir Dominguez Pineda'], $date, $seconds + 3600);
        }
        $marcoTimes = [
            '2026-09-27' => '06:28', '2026-09-28' => '09:09', '2026-09-29' => '07:48',
            '2026-09-30' => '07:49', '2026-10-01' => '07:55', '2026-10-02' => '09:44',
            '2026-10-03' => '06:15', '2026-10-05' => '07:40', '2026-10-06' => '08:33',
            '2026-10-07' => '07:43', '2026-10-08' => '09:07', '2026-10-09' => '07:29',
            '2026-10-10' => '07:18',
        ];
        foreach ($marcoTimes as $date => $time) {
            [$hours, $minutes] = array_map('intval', explode(':', $time));
            $this->entry($period, $employees['Marco Antonio Lara'], $date, $hours * 3600 + $minutes * 60 + 60);
        }
        DailyTimeReview::create(['payroll_period_id' => $previous->id,
            'employee_id' => $employees['Elalf Shamir Dominguez Pineda']->id,
            'date' => '2026-09-25', 'preassigned_overtime_seconds' => 4 * 3600]);

        $payroll = app(PayrollCalculationService::class);
        $payroll->generateDailyReviews($period);
        foreach (['2026-09-26', '2026-10-04'] as $date) {
            $this->review($period, $employees['Marco Antonio Lara'], $date)->update(['paid_day_off' => true]);
        }
        $marco = $this->review($period, $employees['Marco Antonio Lara'], '2026-09-29');
        $marco->update(['status' => 'revisado_supervisor', 'supervisor_comment' => 'Comentario existente']);

        $correction = app(OctoberFirstHalfTimeCorrectionService::class);
        $this->assertCount(21, $correction->preview($period));
        $this->assertSame(21, $correction->apply($period));
        $this->assertSame(0, $correction->apply($period));

        $wilman = $this->review($period, $employees['Wilman Josue Elias Agurcia'], '2026-09-29');
        $this->assertTrue($wilman->paid_day_off);
        $this->assertSame(0, $wilman->hubstaff_total_seconds);
        $this->assertSame(0, HubstaffTimeEntry::where('employee_id', $wilman->employee_id)->where('active', true)->count());
        $this->assertSame(7200, (int) HubstaffTimeEntry::where('employee_id', $wilman->employee_id)->value('total_seconds'));

        foreach (['2026-09-26' => [5580, 32400], '2026-10-03' => [10140, 32400],
            '2026-10-10' => [3360, 32400]] as $date => [$gap, $paid]) {
            $review = $this->review($period, $employees['Elalf Shamir Dominguez Pineda'], $date);
            $this->assertSame(28800, $review->expected_ordinary_seconds);
            $this->assertSame(3600, $review->preassigned_overtime_seconds);
            $this->assertSame($gap, $review->justified_absence_seconds);
            $this->assertSame($paid, $review->payable_seconds);
        }

        $marco = $this->review($period, $employees['Marco Antonio Lara'], '2026-09-29');
        $this->assertSame(720, $marco->justified_absence_seconds);
        $this->assertSame(28800, $marco->payable_seconds);
        $this->assertSame('Comentario existente', $marco->supervisor_comment);
        $weekend = $this->review($period, $employees['Marco Antonio Lara'], '2026-10-03');
        $this->assertSame(0, $weekend->expected_ordinary_seconds);
        $this->assertSame(22500, $weekend->possible_overtime_seconds);
        $this->assertSame(22500, $weekend->payable_seconds);
        $this->assertSame(28080, (int) $this->review($period, $employees['Marco Antonio Lara'], '2026-09-29')->verified_tracked_seconds);

        $payroll->recalculatePeriodPreservingManual($period);
        $this->assertSame(32400, $this->review($period, $employees['Elalf Shamir Dominguez Pineda'], '2026-09-26')->payable_seconds);
        $this->assertSame(22500, $this->review($period, $employees['Marco Antonio Lara'], '2026-10-03')->possible_overtime_seconds);
        $this->assertSame(22500, $this->review($period, $employees['Marco Antonio Lara'], '2026-10-03')->payable_seconds);
        $this->assertNotNull(PayrollResult::where('payroll_period_id', $period->id)
            ->where('employee_id', $employees['Marco Antonio Lara']->id)->first());

        $result = PayrollResult::where('payroll_period_id', $period->id)
            ->where('employee_id', $employees['Marco Antonio Lara']->id)->sole();
        $this->assertSame('15.00', (string) $result->worked_days);
        $amounts = $result->only(['worked_salary_amount', 'overtime_amount', 'gross_amount', 'net_amount']);
        $result->update(['worked_days' => 12]);
        $this->assertSame(['before' => '12.00', 'after' => '15.00'], $correction->refreshMarcoWorkedDays($period));
        $this->assertSame($amounts, $result->fresh()->only(array_keys($amounts)));
        $this->assertSame(['before' => '15.00', 'after' => '15.00'], $correction->refreshMarcoWorkedDays($period));

        $marco->refresh()->update(['justified_absence_seconds' => 300]);
        $payroll->recalculateEmployeePreservingManual($period, $employees['Marco Antonio Lara']);
        $this->assertSame(300, $this->review($period, $employees['Marco Antonio Lara'], '2026-09-29')->justified_absence_seconds);
        $this->assertSame(420, (int) PayrollResult::where('payroll_period_id', $period->id)
            ->where('employee_id', $employees['Marco Antonio Lara']->id)->value('lost_time_seconds'));
    }

    private function entry(PayrollPeriod $period, Employee $employee, string $date, int $seconds): void
    {
        HubstaffTimeEntry::create(['payroll_period_id' => $period->id, 'employee_id' => $employee->id,
            'hubstaff_member' => $employee->name, 'source_provider' => 'trackabi_api',
            'date' => $date, 'active' => true, 'total_seconds' => $seconds]);
    }

    private function review(PayrollPeriod $period, Employee $employee, string $date): DailyTimeReview
    {
        return DailyTimeReview::where('payroll_period_id', $period->id)->where('employee_id', $employee->id)
            ->whereDate('date', $date)->sole();
    }
}
