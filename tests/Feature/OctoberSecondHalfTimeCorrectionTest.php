<?php

namespace Tests\Feature;

use App\Models\DailyTimeReview;
use App\Models\Employee;
use App\Models\HubstaffTimeEntry;
use App\Models\PayrollPeriod;
use App\Models\TrackabiActivityImportBatch;
use App\Models\TrackabiDailyActivity;
use App\Models\TrackabiDayExclusion;
use App\Services\OctoberSecondHalfTimeCorrectionService;
use App\Services\PayrollCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OctoberSecondHalfTimeCorrectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_corrections_are_scoped_idempotent_and_survive_recalculation(): void
    {
        config(['trackabi.provider' => 'direct', 'trackabi_direct.cutover_date' => '2026-09-26']);
        $period = PayrollPeriod::query()->create([
            'id' => 11, 'name' => 'October second half', 'starts_at' => '2026-10-11',
            'ends_at' => '2026-10-25', 'status' => 'en_revision',
        ]);
        $employees = [];
        foreach (['Seily Ortiz', 'Kelvin Rivera', 'Elalf Dominguez', 'Francisco Bejarano'] as $name) {
            $employees[$name] = Employee::query()->create([
                'name' => $name, 'active' => true, 'daily_hours' => 8,
                'ordinary_weekly_hours' => 40,
                'preassigned_overtime_weekly_hours' => $name === 'Francisco Bejarano' ? 0 : 5,
                'hourly_rate' => 60, 'overtime_hourly_rate' => 75,
            ]);
        }
        $times = [
            ['Seily Ortiz', '2026-10-20', 2760, 120],
            ['Kelvin Rivera', '2026-10-24', 35880, 27900],
            ['Elalf Dominguez', '2026-10-17', 36000, 30840],
            ['Elalf Dominguez', '2026-10-24', 31560, 31500],
            ['Francisco Bejarano', '2026-10-17', 86340, 1980],
            ['Francisco Bejarano', '2026-10-18', 39420, 0],
        ];
        foreach ($times as [$name, $date, $logged, $measured]) {
            HubstaffTimeEntry::query()->create([
                'payroll_period_id' => $period->id, 'employee_id' => $employees[$name]->id,
                'hubstaff_member' => $name, 'source_provider' => 'trackabi_api',
                'date' => $date, 'active' => true, 'total_seconds' => $logged,
            ]);
        }
        $payroll = app(PayrollCalculationService::class);
        $payroll->generateDailyReviews($period);
        foreach ($times as [$name, $date, $logged, $measured]) {
            $batch = TrackabiActivityImportBatch::query()->create([
                'payroll_period_id' => $period->id, 'activity_date' => $date,
                'filename' => $name.$date.'.csv', 'file_hash' => hash('sha256', $name.$date),
                'rows' => 1, 'matched' => 1, 'unmatched' => 0, 'imported_at' => now(),
            ]);
            TrackabiDailyActivity::query()->create([
                'payroll_period_id' => $period->id, 'employee_id' => $employees[$name]->id,
                'import_batch_id' => $batch->id, 'activity_date' => $date,
                'productive_activity_seconds' => $measured,
                'unproductive_activity_seconds' => 0,
                'measured_activity_seconds' => $measured,
                'imported_at' => now(),
            ]);
            $this->review($period, $employees[$name], $date)->update([
                'lost_time_source' => 'trackabi_insights_activity',
                'lost_time_estimate_metadata' => ['activity_source' => 'trackabi_insights_csv'],
            ]);
        }
        foreach ($employees as $employee) {
            $payroll->recalculateEmployeePreservingManual($period, $employee);
        }
        $this->review($period, $employees['Francisco Bejarano'], '2026-10-17')
            ->update(['status' => 'revisado_supervisor', 'supervisor_comment' => 'Medical excuse. IHSS',
                'supervisor_adjustment_seconds' => -22020]);
        $this->review($period, $employees['Elalf Dominguez'], '2026-10-17')
            ->update(['status' => 'revisado_supervisor']);

        $service = app(OctoberSecondHalfTimeCorrectionService::class);
        $this->assertCount(6, $service->preview($period));
        $this->assertGreaterThan(0, $service->apply($period));
        $this->assertSame(0, $service->apply($period));
        foreach ($employees as $employee) {
            $payroll->recalculateEmployeePreservingManual($period, $employee);
        }

        $seily = $this->review($period, $employees['Seily Ortiz'], '2026-10-20');
        $this->assertTrue($seily->paid_day_off);
        $this->assertSame(28800, (int) $seily->payable_seconds);
        $this->assertSame(0, HubstaffTimeEntry::query()->where('employee_id', $employees['Seily Ortiz']->id)->where('active', true)->count());
        $kelvin = $this->review($period, $employees['Kelvin Rivera'], '2026-10-24');
        $this->assertSame(32400, (int) $kelvin->expected_paid_seconds);
        $this->assertSame(32400, (int) $kelvin->payable_seconds);
        $elalf17 = $this->review($period, $employees['Elalf Dominguez'], '2026-10-17');
        $this->assertSame(1560, (int) $elalf17->justified_absence_seconds);
        $this->assertSame(32400, (int) $elalf17->payable_seconds);
        $elalf24 = $this->review($period, $employees['Elalf Dominguez'], '2026-10-24');
        $this->assertSame(0, (int) $elalf24->justified_absence_seconds);
        $this->assertSame(31500, (int) $elalf24->payable_seconds);
        $francisco17 = $this->review($period, $employees['Francisco Bejarano'], '2026-10-17');
        $this->assertSame(28800, (int) $francisco17->justified_absence_seconds);
        $this->assertStringContainsString('Medical excuse. IHSS', $francisco17->supervisor_comment);
        $this->assertSame(28800, (int) $francisco17->payable_seconds);
        $this->assertTrue($this->review($period, $employees['Francisco Bejarano'], '2026-10-18')->paid_day_off);
        $this->assertSame(3, TrackabiDayExclusion::query()->where('payroll_period_id', $period->id)->count());

        $elalf17->update(['justified_absence_seconds' => 900, 'supervisor_comment' => 'Ajuste posterior del supervisor']);
        $payroll->recalculateEmployeePreservingManual($period, $employees['Elalf Dominguez']);
        $this->assertSame(0, $service->apply($period));
        $this->assertSame(900, (int) $elalf17->fresh()->justified_absence_seconds);
        $this->assertSame('Ajuste posterior del supervisor', $elalf17->fresh()->supervisor_comment);
    }

    private function review(PayrollPeriod $period, Employee $employee, string $date): DailyTimeReview
    {
        return DailyTimeReview::query()->where('payroll_period_id', $period->id)
            ->where('employee_id', $employee->id)->whereDate('date', $date)->sole();
    }
}
