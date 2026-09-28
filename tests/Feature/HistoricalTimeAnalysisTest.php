<?php

namespace Tests\Feature;

use App\Models\DailyTimeReview;
use App\Models\Employee;
use App\Models\HubstaffTimeEntry;
use App\Models\PayrollPeriod;
use App\Services\Trackabi\HistoricalTimeAnalysis;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HistoricalTimeAnalysisTest extends TestCase
{
    use RefreshDatabase;

    public function test_history_excludes_estimates_and_current_days_without_changing_reviews(): void
    {
        $past = PayrollPeriod::create(['name' => 'Past', 'starts_at' => '2026-08-26', 'ends_at' => '2026-09-10']);
        $target = PayrollPeriod::create(['name' => 'Current', 'starts_at' => '2026-09-11', 'ends_at' => '2026-09-25']);
        $employee = Employee::create(['name' => 'Agent', 'active' => true]);
        foreach ([['2026-09-01', $past, 'hubstaff_csv', 28800], ['2026-09-02', $past, 'hubstaff_csv', 28200],
            ['2026-09-03', $past, 'trackabi', 27000], ['2026-09-11', $target, 'hubstaff_csv', 10000]] as [$date, $period, $source, $seconds]) {
            HubstaffTimeEntry::create(['employee_id' => $employee->id, 'payroll_period_id' => $period->id,
                'date' => $date, 'hubstaff_member' => 'Agent', 'source_provider' => $source,
                'total_seconds' => $seconds, 'active' => true]);
            DailyTimeReview::create(['employee_id' => $employee->id, 'payroll_period_id' => $period->id,
                'date' => $date, 'scheduled_work_day' => true, 'expected_hubstaff_seconds' => 28800,
                'hubstaff_total_seconds' => $seconds, 'justified_absence_seconds' => 600]);
        }
        $before = DailyTimeReview::all()->toArray();
        $rows = app(HistoricalTimeAnalysis::class)->analyze($target, [$past->id, $target->id]);
        $this->assertSame(2, $rows[0]['days']);
        $this->assertSame(1, $rows[0]['short_days']);
        $this->assertSame(50.0, $rows[0]['percentage']);
        $this->assertSame(600, $rows[0]['median_short_seconds']);
        $this->assertSame(1, $rows[0]['days_with_justification']);
        $this->assertSame($before, DailyTimeReview::all()->toArray());
        $this->artisan("payroll:time-history --period={$target->id} --history={$past->id}")
            ->expectsOutputToContain('No se modificaron registros')->assertSuccessful();
    }
}
