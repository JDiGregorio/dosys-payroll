<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Employee;
use App\Models\HubstaffTimeEntry;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\Services\Reports\ClientWeeklyHoursExporter;
use App\Services\Reports\ClientWeeklyHoursReportService;
use App\Services\Reports\PalmettoGroupClientReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class PalmettoGroupClientReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_group_report_includes_inactive_tracked_worker_and_supervisor_confirmed_work_without_writes(): void
    {
        $campaign = Campaign::query()->create(['name' => 'PALMETTO']);
        User::query()->forceCreate(['id' => 6, 'name' => 'Jasmin', 'email' => 'jasmin@example.test', 'password' => 'test']);
        Employee::query()->create(['id' => 42, 'name' => 'Jasmin Supervisor', 'campaign_id' => $campaign->id]);
        $yadira = Employee::query()->create([
            'id' => 13, 'name' => 'Yadira Dariela Ebanks Canaca',
            'campaign_id' => $campaign->id, 'supervisor_user_id' => 6,
        ]);
        $inactive = Employee::query()->create([
            'id' => 57, 'name' => 'Former Agent', 'campaign_id' => $campaign->id,
            'supervisor_user_id' => 6, 'active' => false,
        ]);
        $withoutTracking = Employee::query()->create([
            'id' => 24, 'name' => 'No Tracking', 'campaign_id' => $campaign->id,
            'supervisor_user_id' => 6,
        ]);
        $period = PayrollPeriod::query()->create([
            'name' => 'Historical test', 'starts_at' => '2026-06-01',
            'ends_at' => '2026-09-20', 'status' => 'cerrado',
        ]);
        foreach ([$yadira, $inactive] as $employee) {
            HubstaffTimeEntry::query()->create([
                'payroll_period_id' => $period->id, 'employee_id' => $employee->id,
                'hubstaff_member' => $employee->name, 'date' => '2026-06-01',
                'total_seconds' => 28800, 'active' => true,
            ]);
        }
        $day = fn (Employee $employee, string $date, int $billable, string $rule): array => [
            'employee_id' => $employee->id, 'employee' => $employee->name, 'campaign' => 'PALMETTO',
            'date' => $date, 'week_start' => $date, 'week_end' => $date,
            'expected_regular_seconds' => $employee->id === 13 ? 25200 : 28800,
            'approved_ot_seconds' => 0, 'base_seconds' => $employee->id === 13 ? 25200 : 28800,
            'expected_trackable_seconds' => $employee->id === 13 ? 25200 : 28800,
            'activity_source' => 'daily_review_consolidated', 'desktop_activity_seconds' => null,
            'justified_seconds' => $employee->id === 13 ? 25200 : 0,
            'remaining_gap_seconds' => 0, 'rule' => $rule, 'billable_seconds' => $billable,
            'comment' => $employee->id === 13 ? 'sí trabajó las horas' : '',
            'review_status' => 'revisado_supervisor', 'schedule_name' => null,
            'expected_weekly_regular_seconds' => 144000, 'expected_weekly_ot_seconds' => 0,
        ];
        $member = fn (Employee $employee, string $date, int $seconds): array => [
            'employee_id' => $employee->id, 'employee' => $employee->name, 'campaign' => 'PALMETTO',
            'daily' => [$date => $seconds], 'total_seconds' => $seconds,
            'expected_weekly_regular_seconds' => 144000, 'expected_weekly_ot_seconds' => 0,
        ];
        $base = [
            'days' => [
                $day($inactive, '2026-06-01', 28799, 'LOSS_30_MIN_OR_MORE_DEDUCTED'),
                $day($withoutTracking, '2026-06-01', 28800, 'FULL_SCHEDULE'),
                $day($yadira, '2026-09-15', 0, 'JUSTIFIED_ABSENCE'),
            ],
            'weeks' => [
                ['start' => '2026-06-01', 'end' => '2026-06-07', 'members' => [
                    $member($inactive, '2026-06-01', 28799), $member($withoutTracking, '2026-06-01', 28800),
                ]],
                ['start' => '2026-09-14', 'end' => '2026-09-20', 'members' => [
                    $member($yadira, '2026-09-15', 0),
                ]],
            ],
            'warnings' => [
                ['code' => 'UNRESOLVED_REVIEW', 'employee' => $inactive->name,
                    'date' => '2026-06-01', 'message' => 'Pending'],
                ['code' => 'AMBIGUOUS_FULL_DAY_JUSTIFICATION', 'employee' => $yadira->name,
                    'date' => '2026-09-15', 'message' => 'Potential work'],
                ['code' => 'CLIENT_REPORT_DATA_MISSING', 'employee' => $withoutTracking->name,
                    'date' => '2026-06-01', 'message' => 'No tracking'],
            ],
        ];
        $historical = Mockery::mock(ClientWeeklyHoursReportService::class);
        $historical->shouldReceive('build')->once()->with('2026-06-01', '2026-09-20')->andReturn($base);
        $this->app->instance(ClientWeeklyHoursReportService::class, $historical);
        $before = HubstaffTimeEntry::query()->count();

        $report = app(PalmettoGroupClientReportService::class)->build(1);

        $this->assertSame('PALMETTO 1', $report['campaigns'][0]['name']);
        $this->assertSame(2, $report['employee_count']);
        $this->assertSame(53940, $report['grand_total_seconds']);
        $this->assertSame(53940, array_sum(array_column($report['weeks'], 'total_seconds')));
        $this->assertSame(28799, $report['days'][0]['raw_billable_seconds']);
        $this->assertSame(28740, $report['days'][0]['billable_seconds']);
        $this->assertSame('SUPERVISOR_CONFIRMED_WORK_TRACKER_FAILURE', $report['days'][1]['rule']);
        $this->assertSame('supervisor_confirmed_work', $report['days'][1]['activity_source']);
        $this->assertTrue($report['safe_to_send_client']);
        $this->assertSame($before, HubstaffTimeEntry::query()->count());

        $directory = sys_get_temp_dir().'/palmetto-group-report-'.uniqid();
        try {
            $files = app(ClientWeeklyHoursExporter::class)->exportSingleCampaign($report, $directory, false);
            $this->assertSame('%PDF', substr(file_get_contents($files['pdf']), 0, 4));
            $this->assertSame('PK', substr(file_get_contents($files['xlsx']), 0, 2));
            $this->assertStringContainsString('PALMETTO_1', $files['pdf']);
        } finally {
            foreach (glob($directory.'/*') ?: [] as $file) {
                unlink($file);
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }
}
