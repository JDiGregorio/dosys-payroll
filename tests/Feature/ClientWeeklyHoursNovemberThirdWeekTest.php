<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\DailyTimeReview;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\PayrollResult;
use App\Models\TrackabiDailyActivity;
use App\Services\Reports\ClientWeeklyHoursNovemberThirdWeekService;
use Carbon\CarbonPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientWeeklyHoursNovemberThirdWeekTest extends TestCase
{
    use RefreshDatabase;

    public function test_insights_week_is_read_only_and_capped_to_configured_weekly_hours(): void
    {
        $campaign = Campaign::query()->create(['name' => 'PALMETTO']);
        $employee = Employee::query()->create([
            'name' => 'Test Agent', 'trackabi_name' => 'Agent, Test', 'campaign_id' => $campaign->id,
            'active' => true, 'daily_hours' => 8, 'ordinary_weekly_hours' => 40,
        ]);
        $period = PayrollPeriod::query()->create([
            'name' => 'Noviembre test', 'starts_at' => '2026-10-26', 'ends_at' => '2026-11-10',
            'status' => 'en_revision',
        ]);
        PayrollResult::query()->create(['payroll_period_id' => $period->id, 'employee_id' => $employee->id]);
        $previousDir = sys_get_temp_dir().'/client-weekly-prior-'.uniqid();
        $currentDir = sys_get_temp_dir().'/client-weekly-current-'.uniqid();
        mkdir($previousDir);
        mkdir($currentDir);
        try {
            foreach (CarbonPeriod::create('2026-11-11', '2026-11-15') as $date) {
                $this->writeInsights($previousDir, $date->toDateString(), '00:00');
            }
            foreach (CarbonPeriod::create('2026-11-16', '2026-11-22') as $date) {
                $this->writeInsights($currentDir, $date->toDateString(), $date->isWeekday() ? '07:00' : '00:00');
            }

            $report = app(ClientWeeklyHoursNovemberThirdWeekService::class)->build($previousDir, $currentDir);
            $days = collect($report['days'])->keyBy('date');

            $this->assertTrue($report['safe_to_send_client']);
            $this->assertSame(7, count($report['days']));
            $this->assertSame(40 * 3600, $report['grand_total_seconds']);
            $this->assertSame(8 * 3600, $days['2026-11-16']['billable_seconds']);
            $this->assertSame(7 * 3600, $days['2026-11-16']['desktop_activity_seconds']);
            $this->assertSame(0, $days['2026-11-22']['billable_seconds']);
            $this->assertSame(0, DailyTimeReview::query()->count());
            $this->assertSame(0, TrackabiDailyActivity::query()->count());
        } finally {
            foreach ([$previousDir, $currentDir] as $directory) {
                foreach (glob($directory.'/*') ?: [] as $file) {
                    unlink($file);
                }
                rmdir($directory);
            }
        }
    }

    public function test_unscheduled_work_requires_explicit_approval_and_stays_within_weekly_limit(): void
    {
        $campaign = Campaign::query()->create(['name' => 'PALMETTO']);
        $employee = Employee::query()->create([
            'name' => 'Test Agent', 'trackabi_name' => 'Agent, Test', 'campaign_id' => $campaign->id,
            'active' => true, 'daily_hours' => 8, 'ordinary_weekly_hours' => 40,
            'preassigned_overtime_weekly_hours' => 5,
            'fixed_weekly_off_day' => 6, 'fixed_weekly_off_from_date' => '2026-01-01',
        ]);
        $period = PayrollPeriod::query()->create([
            'name' => 'Noviembre test', 'starts_at' => '2026-10-26', 'ends_at' => '2026-11-10',
            'status' => 'en_revision',
        ]);
        PayrollResult::query()->create(['payroll_period_id' => $period->id, 'employee_id' => $employee->id]);
        $previousDir = sys_get_temp_dir().'/client-weekly-prior-'.uniqid();
        $currentDir = sys_get_temp_dir().'/client-weekly-current-'.uniqid();
        mkdir($previousDir);
        mkdir($currentDir);
        try {
            foreach (CarbonPeriod::create('2026-11-11', '2026-11-15') as $date) {
                $this->writeInsights($previousDir, $date->toDateString(), '00:00');
            }
            foreach (CarbonPeriod::create('2026-11-16', '2026-11-22') as $date) {
                $activity = in_array($date->dayOfWeekIso, [3, 7], true) ? '00:00' : '08:00';
                $this->writeInsights($currentDir, $date->toDateString(), $activity);
            }
            $service = app(ClientWeeklyHoursNovemberThirdWeekService::class);
            $pending = $service->build($previousDir, $currentDir);
            $this->assertFalse($pending['safe_to_send_client']);
            $this->assertSame('WORK_ON_UNSCHEDULED_DAY', $pending['blocking_warnings'][0]['code']);

            $approved = $service->build($previousDir, $currentDir, [
                'Test Agent|2026-11-21' => ['regular' => 8 * 3600, 'overtime' => 3600],
            ]);
            $saturday = collect($approved['days'])->firstWhere('date', '2026-11-21');
            $this->assertTrue($approved['safe_to_send_client']);
            $this->assertSame(9 * 3600, $saturday['billable_seconds']);
            $this->assertSame(45 * 3600, $approved['grand_total_seconds']);
            $this->assertSame(0, DailyTimeReview::query()->count());
        } finally {
            foreach ([$previousDir, $currentDir] as $directory) {
                foreach (glob($directory.'/*') ?: [] as $file) {
                    unlink($file);
                }
                rmdir($directory);
            }
        }
    }

    public function test_six_confirmed_sandro_shifts_are_billed_as_explicit_48_hour_exception(): void
    {
        $campaign = Campaign::query()->create(['name' => 'AUTO FINANCE CENTER']);
        $employee = Employee::query()->create([
            'name' => 'Sandro Kluivert Aplicano Flores', 'trackabi_name' => 'Aplicano, Sandro',
            'campaign_id' => $campaign->id, 'active' => true, 'daily_hours' => 8,
            'ordinary_weekly_hours' => 40,
        ]);
        $period = PayrollPeriod::query()->create([
            'name' => 'Noviembre test', 'starts_at' => '2026-10-26', 'ends_at' => '2026-11-10',
            'status' => 'en_revision',
        ]);
        PayrollResult::query()->create(['payroll_period_id' => $period->id, 'employee_id' => $employee->id]);
        $previousDir = sys_get_temp_dir().'/client-weekly-prior-'.uniqid();
        $currentDir = sys_get_temp_dir().'/client-weekly-current-'.uniqid();
        mkdir($previousDir);
        mkdir($currentDir);
        try {
            foreach (CarbonPeriod::create('2026-11-11', '2026-11-15') as $date) {
                $this->writeInsights($previousDir, $date->toDateString(), '00:00', 'Aplicano, Sandro');
            }
            foreach (CarbonPeriod::create('2026-11-16', '2026-11-22') as $date) {
                $this->writeInsights($currentDir, $date->toDateString(),
                    $date->dayOfWeekIso <= 6 ? '04:00' : '00:00', 'Aplicano, Sandro');
            }
            $report = app(ClientWeeklyHoursNovemberThirdWeekService::class)->build(
                $previousDir, $currentDir, [], true);
            $saturday = collect($report['days'])->firstWhere('date', '2026-11-21');

            $this->assertTrue($report['safe_to_send_client']);
            $this->assertSame(48 * 3600, $report['grand_total_seconds']);
            $this->assertSame(8 * 3600, $saturday['billable_seconds']);
            $this->assertContains('APPROVED_WEEKLY_EXCEPTION', array_column($report['warnings'], 'code'));
            $this->assertSame(0, DailyTimeReview::query()->count());
        } finally {
            foreach ([$previousDir, $currentDir] as $directory) {
                foreach (glob($directory.'/*') ?: [] as $file) {
                    unlink($file);
                }
                rmdir($directory);
            }
        }
    }

    private function writeInsights(string $directory, string $day, string $activity, string $name = 'Agent, Test'): void
    {
        file_put_contents($directory.'/'.$day.'-'.$day.'-insights.csv',
            '"Member Name","Total logged","Total billable","Productive activity time","Unproductive activity time"' . "\n"
            .'"'.$name.'",08:00,08:00,'.$activity.',00:00' . "\n");
    }
}
