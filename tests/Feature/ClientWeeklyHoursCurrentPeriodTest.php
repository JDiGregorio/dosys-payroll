<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\DailyTimeReview;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\PayrollResult;
use App\Models\TrackabiDailyActivity;
use App\Services\Reports\ClientWeeklyHoursCurrentPeriodService;
use Carbon\CarbonPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientWeeklyHoursCurrentPeriodTest extends TestCase
{
    use RefreshDatabase;

    public function test_insights_projection_is_read_only_and_distinguishes_repeated_off_from_absence(): void
    {
        $campaign = Campaign::query()->create(['name' => 'PALMETTO']);
        $employee = Employee::query()->create([
            'name' => 'Test Agent', 'trackabi_name' => 'Agent, Test', 'campaign_id' => $campaign->id,
            'active' => true, 'daily_hours' => 8, 'ordinary_weekly_hours' => 40,
        ]);
        $period = PayrollPeriod::query()->create([
            'name' => 'Octubre test', 'starts_at' => '2026-10-11', 'ends_at' => '2026-10-25',
            'status' => 'en_revision',
        ]);
        PayrollResult::query()->create(['payroll_period_id' => $period->id, 'employee_id' => $employee->id]);
        foreach (CarbonPeriod::create('2026-10-12', '2026-10-25') as $date) {
            $off = in_array($date->dayOfWeekIso, [2, 7], true);
            DailyTimeReview::query()->create([
                'payroll_period_id' => $period->id, 'employee_id' => $employee->id,
                'date' => $date->toDateString(), 'status' => 'revisado_supervisor',
                'paid_day_off' => $off, 'expected_ordinary_seconds' => $off ? 0 : 28800,
                'expected_hubstaff_seconds' => $off ? 0 : 28800,
                'payable_seconds' => $off ? 0 : 28800,
            ]);
        }
        $directory = sys_get_temp_dir().'/client-weekly-current-'.uniqid();
        mkdir($directory);
        try {
            foreach (CarbonPeriod::create('2026-10-26', '2026-11-01') as $date) {
                $hours = match ($date->dayOfWeekIso) {
                    1, 5 => '08:00', 3 => '07:00', default => '00:00',
                };
                file_put_contents($directory.'/'.$date->toDateString().'-'.$date->toDateString().'-insights.csv',
                    '"Member Name","Total logged","Total billable","Productive activity time","Unproductive activity time"'."\n"
                    .'"Agent, Test",08:00,08:00,'.$hours.',00:00'."\n");
            }
            $before = DailyTimeReview::query()->count();
            $report = app(ClientWeeklyHoursCurrentPeriodService::class)->build($directory);
            $projected = collect($report['days'])->where('date', '>=', '2026-10-26')->keyBy('date');
            $this->assertCount(3, $report['weeks']);
            $this->assertSame(28800, $projected['2026-10-26']['billable_seconds']);
            $this->assertSame('SCHEDULED_OFF', $projected['2026-10-27']['rule']);
            $this->assertSame(28800, $projected['2026-10-28']['billable_seconds']);
            $this->assertSame('NO_ACTIVITY', $projected['2026-10-29']['rule']);
            $this->assertSame(0, $projected['2026-10-29']['billable_seconds']);
            $this->assertSame($before, DailyTimeReview::query()->count());
            $this->assertSame(0, TrackabiDailyActivity::query()->count());
        } finally {
            foreach (glob($directory.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }
}
