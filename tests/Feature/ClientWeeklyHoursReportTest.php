<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\DailyTimeReview;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\PayrollResult;
use App\Models\ScheduleType;
use App\Models\TrackabiActivityImportBatch;
use App\Models\TrackabiDailyActivity;
use App\Models\WorkScheduleTemplate;
use App\Models\WorkScheduleTemplateDay;
use App\Services\Reports\ClientWeeklyHoursExporter;
use App\Services\Reports\ClientWeeklyHoursReportService;
use App\Services\ScheduleExpectationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class ClientWeeklyHoursReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_threshold_uses_exact_seconds_and_does_not_auto_create_overtime(): void
    {
        $service = app(ClientWeeklyHoursReportService::class);
        foreach ([
            [29 * 60, 8 * 3600, 'LOSS_UNDER_30_MIN_TOLERATED'],
            [30 * 60, 8 * 3600 - 30 * 60, 'LOSS_30_MIN_OR_MORE_DEDUCTED'],
            [31 * 60, 8 * 3600 - 31 * 60, 'LOSS_30_MIN_OR_MORE_DEDUCTED'],
            [77 * 60, 8 * 3600 - 77 * 60, 'LOSS_30_MIN_OR_MORE_DEDUCTED'],
        ] as [$gap, $expected, $rule]) {
            $result = $service->calculateDay([
                'ordinary_seconds' => 8 * 3600,
                'expected_trackable_seconds' => 8 * 3600,
                'activity_seconds' => 8 * 3600 - $gap,
            ]);
            $this->assertSame($expected, $result['billable_seconds']);
            $this->assertSame($rule, $result['rule']);
        }
        $this->assertSame(8 * 3600, $service->calculateDay([
            'ordinary_seconds' => 8 * 3600, 'activity_seconds' => 9 * 3600,
        ])['billable_seconds']);
    }

    public function test_paid_lunch_justification_overtime_absence_and_weekend_overtime(): void
    {
        $service = app(ClientWeeklyHoursReportService::class);
        $facts = ['ordinary_seconds' => 8 * 3600, 'expected_trackable_seconds' => 7 * 3600,
            'activity_seconds' => 6 * 3600 + 35 * 60];
        $this->assertSame(8 * 3600, $service->calculateDay($facts)['billable_seconds']);
        $this->assertSame(8 * 3600, $service->calculateDay([
            ...$facts, 'activity_seconds' => 6 * 3600 + 10 * 60, 'justified_seconds' => 25 * 60,
        ])['billable_seconds']);
        $this->assertSame(8 * 3600 - 30 * 60, $service->calculateDay([
            ...$facts, 'activity_seconds' => 6 * 3600 + 10 * 60, 'justified_seconds' => 20 * 60,
        ])['billable_seconds']);
        $this->assertSame(10 * 3600, $service->calculateDay([
            'ordinary_seconds' => 8 * 3600, 'overtime_seconds' => 2 * 3600,
            'expected_trackable_seconds' => 10 * 3600, 'activity_seconds' => 10 * 3600,
        ])['billable_seconds']);
        $this->assertSame(3 * 3600, $service->calculateDay([
            'ordinary_seconds' => 0, 'overtime_seconds' => 3 * 3600,
            'expected_trackable_seconds' => 3 * 3600, 'activity_seconds' => 3 * 3600,
        ])['billable_seconds']);
        foreach (['JUSTIFIED_ABSENCE', 'UNJUSTIFIED_ABSENCE', 'VACATION', 'HOLIDAY', 'SCHEDULED_OFF'] as $reason) {
            $this->assertSame(0, $service->calculateDay([
                'ordinary_seconds' => 8 * 3600, 'non_billable' => true, 'non_billable_rule' => $reason,
            ])['billable_seconds']);
        }
    }

    public function test_historical_inactive_employee_is_included_and_last_sunday_is_blank_without_writes(): void
    {
        $period = PayrollPeriod::query()->create([
            'name' => 'Octubre', 'starts_at' => '2026-09-26', 'ends_at' => '2026-10-10', 'status' => 'cerrado',
        ]);
        $campaign = Campaign::query()->create(['name' => 'PALMETTO']);
        $employee = Employee::query()->create([
            'name' => 'Historical Agent', 'campaign_id' => $campaign->id,
            'active' => false, 'daily_hours' => 8, 'ordinary_weekly_hours' => 40,
        ]);
        PayrollResult::query()->create(['payroll_period_id' => $period->id, 'employee_id' => $employee->id]);
        $review = DailyTimeReview::query()->create([
            'payroll_period_id' => $period->id, 'employee_id' => $employee->id,
            'date' => '2026-10-10', 'status' => 'revisado_supervisor',
            'expected_ordinary_seconds' => 8 * 3600, 'expected_hubstaff_seconds' => 8 * 3600,
            'payable_seconds' => 8 * 3600,
        ]);
        $before = $review->fresh()->getAttributes();

        $report = app(ClientWeeklyHoursReportService::class)->build('2026-10-10', '2026-10-10');
        $this->assertSame(1, $report['employee_count']);
        $this->assertSame(8 * 3600, $report['grand_total_seconds']);
        $this->assertNull($report['weeks'][0]['members'][0]['daily']['2026-10-11']);
        $this->assertSame($before, $review->fresh()->getAttributes());
    }

    public function test_final_export_is_blocked_and_draft_writes_both_formats(): void
    {
        $report = [
            'from' => '2026-09-21', 'to' => '2026-10-10', 'grand_total_seconds' => 8 * 3600,
            'safe_to_send_client' => false, 'campaigns' => [['name' => 'PALMETTO', 'employees' => 1, 'total_seconds' => 8 * 3600]],
            'weeks' => [['start' => '2026-09-21', 'end' => '2026-09-27', 'total_seconds' => 8 * 3600,
                'members' => [['employee_id' => 1, 'employee' => 'Agent', 'campaign' => 'PALMETTO',
                    'daily' => ['2026-09-21' => 8 * 3600, '2026-09-22' => 0, '2026-09-23' => 0,
                        '2026-09-24' => 0, '2026-09-25' => 0, '2026-09-26' => 0, '2026-09-27' => 0],
                    'total_seconds' => 8 * 3600, 'expected_weekly_regular_seconds' => 40 * 3600,
                    'expected_weekly_ot_seconds' => 0]]]],
            'days' => [], 'warnings' => [['code' => 'UNRESOLVED_REVIEW', 'employee' => 'Agent',
                'date' => '2026-09-21', 'message' => 'Pending']],
        ];
        $exporter = app(ClientWeeklyHoursExporter::class);
        $directory = sys_get_temp_dir().'/client-report-test-'.uniqid();
        try {
            try {
                $exporter->export($report, $directory, false);
                $this->fail('Final export should be blocked.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('--draft', $exception->getMessage());
            }
            $files = $exporter->export($report, $directory, true);
            $this->assertSame('%PDF', substr(file_get_contents($files['pdf']), 0, 4));
            $this->assertSame('PK', substr(file_get_contents($files['xlsx']), 0, 2));
            $this->assertSame(['PALMETTO'], array_keys($files['campaign_pdfs']));
            $this->assertSame('%PDF', substr(file_get_contents($files['campaign_pdfs']['PALMETTO']), 0, 4));
            $html = view('reports.client-weekly-hours', ['report' => $report, 'logo' => null])->render();
            $this->assertStringNotContainsString('DRAFT - NOT FOR CLIENT', $html);
            $this->assertStringNotContainsString('N/A', $html);
            $book = IOFactory::load($files['xlsx']);
            $summary = $book->getSheetByName('ALL');
            $this->assertSame('', (string) $summary->getCell('B1')->getValue());
            $this->assertSame('-', $summary->getCell('B4')->getValue());
            $this->assertSame('-', $summary->getCell('B5')->getValue());
            $this->assertSame('NO', $summary->getCell('B6')->getValue());
            $this->assertSame('', (string) $book->getSheetByName('PALMETTO')->getCell('A3')->getValue());
        } finally {
            foreach (glob($directory.'/*') ?: [] as $file) {
                unlink($file);
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    public function test_separate_campaign_pdfs_use_only_their_members_and_totals(): void
    {
        $campaigns = [
            ['name' => 'PALMETTO', 'employees' => 1, 'total_seconds' => 8 * 3600],
            ['name' => 'RRD FINANCIAL', 'employees' => 1, 'total_seconds' => 7 * 3600],
        ];
        $members = [];
        foreach ($campaigns as $index => $campaign) {
            $members[] = [
                'employee_id' => $index + 1, 'employee' => $index === 0 ? 'Agent Palmetto' : 'Agent RRD',
                'campaign' => $campaign['name'],
                'daily' => ['2026-10-05' => $campaign['total_seconds']],
                'total_seconds' => $campaign['total_seconds'],
                'expected_weekly_regular_seconds' => $campaign['total_seconds'],
                'expected_weekly_ot_seconds' => 0,
            ];
        }
        $report = [
            'from' => '2026-10-05', 'to' => '2026-10-05', 'grand_total_seconds' => 15 * 3600,
            'safe_to_send_client' => false, 'campaigns' => $campaigns,
            'weeks' => [['start' => '2026-10-05', 'end' => '2026-10-11', 'members' => $members]],
            'days' => [], 'warnings' => [['code' => 'TEST', 'employee' => '', 'date' => '', 'message' => '']],
        ];
        $directory = sys_get_temp_dir().'/client-report-campaign-'.uniqid();
        try {
            $files = app(ClientWeeklyHoursExporter::class)->export($report, $directory, true);
            $this->assertSame(['PALMETTO', 'RRD FINANCIAL'], array_keys($files['campaign_pdfs']));
            foreach ($campaigns as $campaign) {
                $this->assertSame('%PDF', substr(file_get_contents($files['campaign_pdfs'][$campaign['name']]), 0, 4));
                $html = view('reports.client-weekly-hours', [
                    'report' => [...$report, 'campaigns' => [$campaign], 'grand_total_seconds' => $campaign['total_seconds']],
                    'logo' => null,
                ])->render();
                $this->assertStringContainsString($campaign['name'], $html);
                $this->assertStringContainsString(ClientWeeklyHoursExporter::hours($campaign['total_seconds']), $html);
                $otherName = $campaign['name'] === 'PALMETTO' ? 'Agent RRD' : 'Agent Palmetto';
                $this->assertStringNotContainsString($otherName, $html);
            }
        } finally {
            foreach (glob($directory.'/*') ?: [] as $file) {
                unlink($file);
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    public function test_palmetto_template_has_one_eight_hour_day_and_rotating_off_is_zero(): void
    {
        $template = WorkScheduleTemplate::query()->create(['name' => '36h Wednesday', 'schedule_type' => 'diurna']);
        foreach ([1 => 7, 2 => 7, 3 => 8, 4 => 7, 5 => 7, 6 => 0, 7 => 0] as $day => $hours) {
            WorkScheduleTemplateDay::query()->create([
                'work_schedule_template_id' => $template->id, 'day_number' => $day,
                'expected_seconds' => $hours * 3600, 'is_working_day' => $hours > 0,
            ]);
        }
        $employee = Employee::query()->create([
            'name' => 'Palmetto 36', 'work_schedule_template_id' => $template->id,
            'ordinary_weekly_hours' => 36,
        ]);
        $schedule = app(ScheduleExpectationService::class);
        $hours = [];
        for ($day = 0; $day < 7; $day++) {
            $hours[] = $schedule->forDate($employee, Carbon::parse('2026-10-05')->addDays($day))['expected_ordinary_seconds'];
        }
        $this->assertSame([25200, 25200, 28800, 25200, 25200, 0, 0], $hours);
        $this->assertSame(36 * 3600, array_sum($hours));

        $rotation = ScheduleType::query()->create(['name' => 'Rotating test', 'code' => 'rotativa']);
        $rotating = Employee::query()->create([
            'name' => 'Rotating', 'daily_hours' => 11, 'schedule_cycle_anchor_date' => '2026-10-05',
            'rotation_work_days' => 4, 'rotation_rest_days' => 4,
            'schedule_type_id' => $rotation->id,
        ]);
        $this->assertSame(11 * 3600, $schedule->forDate($rotating, Carbon::parse('2026-10-05'))['expected_ordinary_seconds']);
        $this->assertSame(0, $schedule->forDate($rotating, Carbon::parse('2026-10-09'))['expected_ordinary_seconds']);
    }

    public function test_weekly_total_is_the_sum_of_five_regular_days_and_saturday_ot(): void
    {
        $period = PayrollPeriod::query()->create([
            'name' => 'Octubre', 'starts_at' => '2026-09-26', 'ends_at' => '2026-10-10', 'status' => 'cerrado',
        ]);
        $campaign = Campaign::query()->create(['name' => 'RRD FINANCIAL']);
        $employee = Employee::query()->create([
            'name' => 'Weekend OT', 'campaign_id' => $campaign->id,
            'ordinary_weekly_hours' => 40, 'preassigned_overtime_weekly_hours' => 10,
            'daily_hours' => 8,
        ]);
        PayrollResult::query()->create(['payroll_period_id' => $period->id, 'employee_id' => $employee->id]);
        foreach (range(5, 10) as $day) {
            $regular = $day === 10 ? 0 : 8 * 3600;
            $ot = $day === 10 ? 10 * 3600 : 0;
            DailyTimeReview::query()->create([
                'payroll_period_id' => $period->id, 'employee_id' => $employee->id,
                'date' => sprintf('2026-10-%02d', $day), 'status' => 'revisado_supervisor',
                'expected_ordinary_seconds' => $regular, 'preassigned_overtime_seconds' => $ot,
                'expected_hubstaff_seconds' => $regular + $ot,
                'payable_seconds' => $regular + $ot,
            ]);
        }
        $report = app(ClientWeeklyHoursReportService::class)->build('2026-10-05', '2026-10-10');
        $member = $report['weeks'][0]['members'][0];
        $this->assertSame(50 * 3600, $member['total_seconds']);
        $this->assertSame(50 * 3600, array_sum(array_filter($member['daily'], fn ($value) => $value !== null)));
        $this->assertNull($member['daily']['2026-10-11']);
    }

    public function test_paid_off_before_first_work_review_does_not_create_a_client_week_for_new_hire(): void
    {
        $period = PayrollPeriod::query()->create([
            'name' => 'Octubre', 'starts_at' => '2026-09-26', 'ends_at' => '2026-10-10', 'status' => 'cerrado',
        ]);
        $campaign = Campaign::query()->create(['name' => 'PALMETTO']);
        $employee = Employee::query()->create(['name' => 'New Hire', 'campaign_id' => $campaign->id, 'daily_hours' => 8]);
        PayrollResult::query()->create(['payroll_period_id' => $period->id, 'employee_id' => $employee->id]);
        foreach ([26, 27, 28] as $day) {
            DailyTimeReview::query()->create([
                'payroll_period_id' => $period->id, 'employee_id' => $employee->id,
                'date' => sprintf('2026-09-%02d', $day), 'status' => 'revisado_supervisor',
                'expected_ordinary_seconds' => 8 * 3600, 'expected_hubstaff_seconds' => 8 * 3600,
                'payable_seconds' => 8 * 3600, 'paid_day_off' => $day < 28,
            ]);
        }
        $report = app(ClientWeeklyHoursReportService::class)->build('2026-09-21', '2026-09-28');
        $this->assertCount(0, $report['weeks'][0]['members']);
        $this->assertCount(1, $report['weeks'][1]['members']);
        $this->assertSame(2, collect($report['warnings'])->where('code', 'PRE_FIRST_WORKDAY_OFF')->count());
    }

    public function test_insights_activity_is_matched_per_employee_and_date_not_shared_by_eager_load(): void
    {
        $period = PayrollPeriod::query()->create([
            'name' => 'Octubre', 'starts_at' => '2026-09-26', 'ends_at' => '2026-10-10', 'status' => 'cerrado',
        ]);
        $campaign = Campaign::query()->create(['name' => 'PALMETTO']);
        $batch = TrackabiActivityImportBatch::query()->create([
            'payroll_period_id' => $period->id, 'activity_date' => '2026-10-05',
            'filename' => 'test.csv', 'file_hash' => str_repeat('a', 64),
            'rows' => 2, 'matched' => 2, 'unmatched' => 0, 'imported_at' => now(),
        ]);
        foreach (['Agent A' => 6 * 3600, 'Agent B' => 7 * 3600] as $name => $activity) {
            $employee = Employee::query()->create([
                'name' => $name, 'campaign_id' => $campaign->id,
                'daily_hours' => 8, 'ordinary_weekly_hours' => 40,
            ]);
            PayrollResult::query()->create(['payroll_period_id' => $period->id, 'employee_id' => $employee->id]);
            DailyTimeReview::query()->create([
                'payroll_period_id' => $period->id, 'employee_id' => $employee->id,
                'date' => '2026-10-05', 'status' => 'revisado_supervisor',
                'expected_ordinary_seconds' => 28800, 'expected_hubstaff_seconds' => 28800,
                'payable_seconds' => $activity,
            ]);
            TrackabiDailyActivity::query()->create([
                'employee_id' => $employee->id, 'payroll_period_id' => $period->id,
                'import_batch_id' => $batch->id, 'activity_date' => '2026-10-05',
                'productive_activity_seconds' => $activity, 'unproductive_activity_seconds' => 0,
                'measured_activity_seconds' => $activity, 'imported_at' => now(),
            ]);
        }
        $report = app(ClientWeeklyHoursReportService::class)->build('2026-10-05', '2026-10-05');
        $activities = collect($report['days'])->pluck('desktop_activity_seconds', 'employee')->all();
        $this->assertSame(['Agent A' => 6 * 3600, 'Agent B' => 7 * 3600], $activities);
        $this->assertSame(13 * 3600, $report['grand_total_seconds']);
    }

    public function test_off_with_measured_activity_stays_zero_and_requires_verification(): void
    {
        $period = PayrollPeriod::query()->create([
            'name' => 'Octubre', 'starts_at' => '2026-09-26', 'ends_at' => '2026-10-10', 'status' => 'cerrado',
        ]);
        $campaign = Campaign::query()->create(['name' => 'PALMETTO']);
        $employee = Employee::query()->create(['name' => 'OFF Worker', 'campaign_id' => $campaign->id, 'daily_hours' => 8]);
        PayrollResult::query()->create(['payroll_period_id' => $period->id, 'employee_id' => $employee->id]);
        $batch = TrackabiActivityImportBatch::query()->create([
            'payroll_period_id' => $period->id, 'activity_date' => '2026-10-05',
            'filename' => 'test.csv', 'file_hash' => str_repeat('b', 64),
            'rows' => 1, 'matched' => 1, 'unmatched' => 0, 'imported_at' => now(),
        ]);
        DailyTimeReview::query()->create([
            'payroll_period_id' => $period->id, 'employee_id' => $employee->id,
            'date' => '2026-10-05', 'paid_day_off' => true, 'status' => 'revisado_supervisor',
            'expected_ordinary_seconds' => 28800, 'expected_hubstaff_seconds' => 28800,
            'payable_seconds' => 28800,
        ]);
        TrackabiDailyActivity::query()->create([
            'employee_id' => $employee->id, 'payroll_period_id' => $period->id,
            'import_batch_id' => $batch->id, 'activity_date' => '2026-10-05',
            'productive_activity_seconds' => 3600, 'unproductive_activity_seconds' => 0,
            'measured_activity_seconds' => 3600, 'imported_at' => now(),
        ]);
        $report = app(ClientWeeklyHoursReportService::class)->build('2026-10-05', '2026-10-05');
        $this->assertSame(0, $report['grand_total_seconds']);
        $this->assertSame(1, collect($report['warnings'])->where('code', 'OFF_WITH_ACTIVITY_UNVERIFIED')->count());
    }

    public function test_dosys_bpo_and_jose_di_gregorio_are_excluded_from_client_report(): void
    {
        $period = PayrollPeriod::query()->create([
            'name' => 'Octubre', 'starts_at' => '2026-09-26', 'ends_at' => '2026-10-10', 'status' => 'cerrado',
        ]);
        $campaign = Campaign::query()->create(['name' => 'Dosys BPO']);
        $employee = Employee::query()->create([
            'name' => 'Administrative', 'campaign_id' => $campaign->id,
            'daily_hours' => 8, 'paid_without_tracking' => true,
        ]);
        PayrollResult::query()->create(['payroll_period_id' => $period->id, 'employee_id' => $employee->id]);
        DailyTimeReview::query()->create([
            'payroll_period_id' => $period->id, 'employee_id' => $employee->id,
            'date' => '2026-10-05', 'status' => 'revisado_supervisor',
            'expected_ordinary_seconds' => 28800, 'expected_hubstaff_seconds' => 28800,
            'payable_seconds' => 28800,
        ]);
        $billableCampaign = Campaign::query()->create(['name' => 'PALMETTO']);
        $owner = Employee::query()->create([
            'name' => 'Jose Di Gregorio', 'campaign_id' => $billableCampaign->id, 'daily_hours' => 8,
        ]);
        PayrollResult::query()->create(['payroll_period_id' => $period->id, 'employee_id' => $owner->id]);
        DailyTimeReview::query()->create([
            'payroll_period_id' => $period->id, 'employee_id' => $owner->id,
            'date' => '2026-10-05', 'status' => 'revisado_supervisor',
            'expected_ordinary_seconds' => 28800, 'expected_hubstaff_seconds' => 28800,
            'payable_seconds' => 28800,
        ]);
        $report = app(ClientWeeklyHoursReportService::class)->build('2026-10-05', '2026-10-05');
        $this->assertSame(0, $report['grand_total_seconds']);
        $this->assertSame(0, $report['employee_count']);
        $this->assertSame([], $report['campaigns']);
        $this->assertSame([], $report['days']);
        $this->assertSame([], $report['warnings']);
    }

    public function test_first_report_command_rejects_october_11_and_dry_run_creates_no_files(): void
    {
        $directory = sys_get_temp_dir().'/client-report-no-write-'.uniqid();
        $this->artisan('payroll:export-client-weekly-hours', [
            '--to' => '2026-10-11', '--output' => $directory, '--draft' => true,
        ])->assertExitCode(1);
        $this->artisan('payroll:export-client-weekly-hours', [
            '--dry-run' => true, '--output' => $directory,
        ])->assertExitCode(0);
        $this->assertDirectoryDoesNotExist($directory);
    }
}
