<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\DailyTimeReview;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\PayrollResult;
use App\Models\TrackabiDailyActivity;
use App\Services\Reports\ClientWeeklyHoursNovemberReportService;
use Carbon\CarbonPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientWeeklyHoursNovemberReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_combined_report_uses_saved_reviews_then_csv_activity_without_writing_payroll(): void
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
        foreach (['2026-11-09', '2026-11-10'] as $day) {
            DailyTimeReview::query()->create([
                'payroll_period_id' => $period->id, 'employee_id' => $employee->id,
                'date' => $day, 'status' => 'revisado_supervisor',
                'expected_ordinary_seconds' => 8 * 3600, 'expected_hubstaff_seconds' => 8 * 3600,
                'payable_seconds' => 8 * 3600,
            ]);
        }
        $directory = sys_get_temp_dir().'/client-weekly-november-'.uniqid();
        mkdir($directory);
        try {
            foreach (CarbonPeriod::create('2026-11-11', '2026-11-15') as $date) {
                $hours = $date->isWeekday() ? '07:00' : '00:00';
                file_put_contents($directory.'/'.$date->toDateString().'-'.$date->toDateString().'-insights.csv',
                    '"Member Name","Total logged","Total billable","Productive activity time","Unproductive activity time"' . "\n"
                    .'"Agent, Test",08:00,08:00,'.$hours.',00:00' . "\n");
            }
            $before = DailyTimeReview::query()->count();
            $report = app(ClientWeeklyHoursNovemberReportService::class)->build($directory);
            $week = $report['weeks'][1];
            $projected = collect($report['days'])->where('date', '>=', '2026-11-11')->keyBy('date');

            $this->assertSame('2026-11-09', $week['start']);
            $this->assertSame(40 * 3600, $week['total_seconds']);
            $this->assertSame(8 * 3600, $projected['2026-11-11']['billable_seconds']);
            $this->assertSame('trackabi_insights_csv_read_only', $projected['2026-11-11']['activity_source']);
            $this->assertSame(0, $projected['2026-11-14']['billable_seconds']);
            $this->assertSame($before, DailyTimeReview::query()->count());
            $this->assertSame(0, TrackabiDailyActivity::query()->count());
        } finally {
            foreach (glob($directory.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    public function test_confirmed_off_without_csv_and_authorized_saturday_are_report_only(): void
    {
        $campaign = Campaign::query()->create(['name' => 'AUTO FINANCE CENTER']);
        $period = PayrollPeriod::query()->create([
            'name' => 'Noviembre test', 'starts_at' => '2026-10-26', 'ends_at' => '2026-11-10',
            'status' => 'en_revision',
        ]);
        $denisha = Employee::query()->create([
            'name' => 'Denisha Marissa Buckley Medina', 'trackabi_name' => 'Buckley, Denisha',
            'campaign_id' => $campaign->id, 'active' => true, 'daily_hours' => 11,
            'ordinary_weekly_hours' => 44, 'preassigned_overtime_weekly_hours' => 4,
        ]);
        $elalf = Employee::query()->create([
            'name' => 'Elalf Shamir Dominguez Pineda', 'trackabi_name' => 'Dominguez, Elalf',
            'campaign_id' => $campaign->id, 'active' => true, 'daily_hours' => 8,
            'ordinary_weekly_hours' => 40, 'preassigned_overtime_weekly_hours' => 5,
        ]);
        $sandro = Employee::query()->create([
            'name' => 'Sandro Kluivert Aplicano Flores', 'trackabi_name' => 'Aplicano, Sandro',
            'campaign_id' => $campaign->id, 'active' => true, 'daily_hours' => 8,
            'ordinary_weekly_hours' => 40,
        ]);
        $marco = Employee::query()->create([
            'name' => 'Marco Antonio Lara', 'trackabi_name' => 'Lara, Marco',
            'campaign_id' => $campaign->id, 'active' => true, 'daily_hours' => 8,
            'ordinary_weekly_hours' => 40, 'preassigned_overtime_weekly_hours' => 10,
            'overtime_distribution_mode' => 'weekly_flexible',
        ]);
        foreach ([$denisha, $elalf, $sandro, $marco] as $employee) {
            PayrollResult::query()->create(['payroll_period_id' => $period->id, 'employee_id' => $employee->id]);
        }
        $directory = sys_get_temp_dir().'/client-weekly-november-special-'.uniqid();
        mkdir($directory);
        try {
            foreach (CarbonPeriod::create('2026-11-11', '2026-11-15') as $date) {
                $day = $date->toDateString();
                $elalfHours = in_array($day, ['2026-11-12', '2026-11-13', '2026-11-14'], true) ? '08:00' : '00:00';
                $denishaRow = $day >= '2026-11-13'
                    ? '"Buckley, Denisha",08:00,08:00,'.($day === '2026-11-13' ? '04:00,03:44' : '00:00,00:00') . "\n"
                    : '';
                file_put_contents($directory.'/'.$day.'-'.$day.'-insights.csv',
                    '"Member Name","Total logged","Total billable","Productive activity time","Unproductive activity time"' . "\n"
                    .$denishaRow.'"Dominguez, Elalf",08:00,08:00,'.$elalfHours.',00:00' . "\n"
                    .'"Aplicano, Sandro",08:00,08:00,'.($day === '2026-11-11' ? '04:00' : '00:00').',00:00' . "\n"
                    .'"Lara, Marco",08:00,08:00,'.match ($day) {
                        '2026-11-11' => '04:50',
                        '2026-11-12' => '08:46',
                        '2026-11-13' => '10:12',
                        default => '00:00',
                    }.',00:00' . "\n");
            }
            $report = app(ClientWeeklyHoursNovemberReportService::class)->build($directory);
            $days = collect($report['days'])->keyBy(fn (array $day): string => $day['employee_id'].'|'.$day['date']);

            $this->assertSame('SCHEDULED_OFF', $days[$denisha->id.'|2026-11-11']['rule']);
            $this->assertSame(0, $days[$denisha->id.'|2026-11-11']['billable_seconds']);
            $this->assertSame(0, $days[$denisha->id.'|2026-11-12']['billable_seconds']);
            $this->assertSame('CLIENT_CONFIRMED_WORK', $days[$denisha->id.'|2026-11-13']['rule']);
            $this->assertSame(12 * 3600, $days[$denisha->id.'|2026-11-13']['billable_seconds']);
            $this->assertSame(7 * 3600 + 44 * 60, $days[$denisha->id.'|2026-11-13']['desktop_activity_seconds']);
            $this->assertSame(9 * 3600, $days[$elalf->id.'|2026-11-14']['billable_seconds']);
            $this->assertSame(8 * 3600, $days[$elalf->id.'|2026-11-14']['expected_regular_seconds']);
            $this->assertSame(3600, $days[$elalf->id.'|2026-11-14']['approved_ot_seconds']);
            $this->assertSame(8 * 3600, $days[$sandro->id.'|2026-11-11']['billable_seconds']);
            $this->assertSame(0, $days[$sandro->id.'|2026-11-12']['billable_seconds']);
            $this->assertSame(8 * 3600, $days[$marco->id.'|2026-11-11']['billable_seconds']);
            $this->assertSame(9 * 3600, $days[$marco->id.'|2026-11-12']['billable_seconds']);
            $this->assertSame(10 * 3600, $days[$marco->id.'|2026-11-13']['billable_seconds']);
            $this->assertSame(0, DailyTimeReview::query()->count());
        } finally {
            foreach (glob($directory.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    public function test_report_rounds_to_half_hour_or_next_hour_without_exceeding_daily_target(): void
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
        foreach (['2026-11-02' => '07:40', '2026-11-09' => '06:20', '2026-11-10' => '06:50'] as $day => $hours) {
            [$h, $m] = array_map('intval', explode(':', $hours));
            $seconds = $h * 3600 + $m * 60;
            DailyTimeReview::query()->create([
                'payroll_period_id' => $period->id, 'employee_id' => $employee->id,
                'date' => $day, 'status' => 'revisado_supervisor',
                'expected_ordinary_seconds' => 8 * 3600, 'expected_hubstaff_seconds' => 8 * 3600,
                'payable_seconds' => $seconds, 'verified_tracked_seconds' => $seconds,
            ]);
        }
        $directory = sys_get_temp_dir().'/client-weekly-november-rounding-'.uniqid();
        mkdir($directory);
        try {
            foreach (CarbonPeriod::create('2026-11-11', '2026-11-15') as $date) {
                $day = $date->toDateString();
                file_put_contents($directory.'/'.$day.'-'.$day.'-insights.csv',
                    '"Member Name","Total logged","Total billable","Productive activity time","Unproductive activity time"' . "\n"
                    .'"Agent, Test",00:00,00:00,00:00,00:00' . "\n");
            }
            $report = app(ClientWeeklyHoursNovemberReportService::class)->build($directory);
            $days = collect($report['days'])->keyBy('date');

            $this->assertSame(8 * 3600, $days['2026-11-02']['billable_seconds']);
            $this->assertSame(6 * 3600 + 30 * 60, $days['2026-11-09']['billable_seconds']);
            $this->assertSame(7 * 3600, $days['2026-11-10']['billable_seconds']);
            $this->assertSame(6 * 3600 + 20 * 60, $days['2026-11-09']['raw_billable_seconds']);
            $this->assertSame(6 * 3600 + 20 * 60,
                (int) DailyTimeReview::query()->whereDate('date', '2026-11-09')->firstOrFail()->payable_seconds);
        } finally {
            foreach (glob($directory.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }
}
