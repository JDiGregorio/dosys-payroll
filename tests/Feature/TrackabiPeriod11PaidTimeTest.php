<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\DailyTimeReview;
use App\Models\Employee;
use App\Models\HubstaffTimeEntry;
use App\Models\PayrollPeriod;
use App\Models\TrackabiActivityImportBatch;
use App\Models\TrackabiDailyActivity;
use App\Models\TrackingImportAlert;
use App\Services\PayrollCalculationService;
use App\Services\ScheduleExpectationService;
use App\Services\Trackabi\TrackabiPaidTimeComplianceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrackabiPeriod11PaidTimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['trackabi.provider' => 'direct', 'trackabi_direct.cutover_date' => '2026-09-26']);
    }

    public function test_paid_breaks_and_lunch_are_audited_without_changing_activity_or_payroll(): void
    {
        $campaign = Campaign::query()->create(['name' => 'RRD FINANCIAL']);
        $employee = Employee::query()->create([
            'name' => 'Test Agent', 'campaign_id' => $campaign->id, 'active' => true, 'daily_hours' => 8,
        ]);
        $period = PayrollPeriod::query()->create([
            'name' => 'Periodo 11', 'starts_at' => '2026-10-21', 'ends_at' => '2026-10-21', 'status' => 'borrador',
        ]);
        DailyTimeReview::query()->create([
            'payroll_period_id' => $period->id, 'employee_id' => $employee->id, 'date' => '2026-10-21',
            'scheduled_work_day' => true, 'expected_ordinary_seconds' => 28800,
            'expected_paid_seconds' => 28800, 'expected_hubstaff_seconds' => 24000,
            'status' => 'pendiente',
        ]);
        foreach ([[75417, 24000], [81120, 3600], [81118, 600], [81118, 600]] as [$projectId, $seconds]) {
            HubstaffTimeEntry::query()->create([
                'payroll_period_id' => $period->id, 'employee_id' => $employee->id,
                'hubstaff_member' => 'Test Agent', 'date' => '2026-10-21',
                'trackabi_project_id' => $projectId, 'source_provider' => 'trackabi_api',
                'active' => true, 'total_seconds' => $seconds,
            ]);
        }
        $service = app(TrackabiPaidTimeComplianceService::class);
        $row = $service->inspect($period)[0];
        $this->assertSame(3600, $row['lunch_entitlement_seconds']);
        $this->assertSame(1200, $row['break_entitlement_seconds']);
        $this->assertSame(2, $row['break_tracked_occurrences']);
        $this->assertSame('COMPLIANT', $row['status']);
        $this->assertDatabaseCount('tracking_import_alerts', 0);

        HubstaffTimeEntry::query()->where('trackabi_project_id', 81118)->firstOrFail()->update(['total_seconds' => 1200]);
        $row = $service->reconcile($period)[0];
        $this->assertContains('TRACKABI_PAID_BREAK_EXCESS', $row['alert_codes']);
        $this->assertDatabaseHas('tracking_import_alerts', ['code' => 'TRACKABI_PAID_BREAK_EXCESS', 'resolved' => false]);
        $this->assertSame(28800, (int) DailyTimeReview::query()->sole()->expected_paid_seconds);
    }

    public function test_weekly_flexible_ot_uses_weekend_pool_without_daily_ot_shortfall(): void
    {
        $campaign = Campaign::query()->create(['name' => 'PALMETTO']);
        $employee = Employee::query()->create([
            'name' => 'Flexible Agent', 'campaign_id' => $campaign->id, 'active' => true,
            'trackabi_member_id' => 63567,
            'daily_hours' => 8, 'ordinary_weekly_hours' => 40,
            'preassigned_overtime_weekly_hours' => 10, 'overtime_distribution_mode' => 'weekly_flexible',
        ]);
        $period = PayrollPeriod::query()->create([
            'name' => 'Semana flexible', 'starts_at' => '2026-10-12', 'ends_at' => '2026-10-18', 'status' => 'borrador',
        ]);
        $batch = TrackabiActivityImportBatch::query()->create([
            'payroll_period_id' => $period->id, 'activity_date' => '2026-10-12',
            'filename' => 'test.csv', 'file_hash' => str_repeat('c', 64),
            'rows' => 7, 'matched' => 7, 'unmatched' => 0, 'imported_at' => now(),
        ]);
        foreach ([12 => 7.5, 13 => 7.5, 14 => 7.5, 15 => 7.5, 16 => 6.5, 17 => 6.0] as $day => $hours) {
            TrackabiDailyActivity::query()->create([
                'employee_id' => $employee->id, 'payroll_period_id' => $period->id,
                'import_batch_id' => $batch->id, 'activity_date' => sprintf('2026-10-%02d', $day),
                'productive_activity_seconds' => (int) ($hours * 3600),
                'unproductive_activity_seconds' => 0, 'measured_activity_seconds' => (int) ($hours * 3600),
                'imported_at' => now(),
            ]);
        }
        $this->assertSame(0, app(ScheduleExpectationService::class)
            ->forDate($employee, Carbon::parse('2026-10-17'))['expected_ordinary_seconds']);
        app(PayrollCalculationService::class)->recalculateEmployeePreservingManual($period, $employee);
        $reviews = DailyTimeReview::query()->where('payroll_period_id', $period->id)
            ->where('employee_id', $employee->id)->get()->keyBy(fn (DailyTimeReview $review): int => $review->date->day);
        $this->assertSame(6 * 3600, (int) $reviews[17]->preassigned_overtime_seconds);
        $this->assertSame(4 * 3600, (int) $reviews->filter(fn (DailyTimeReview $review): bool => $review->date->day <= 16)
            ->sum('preassigned_overtime_seconds'));
        $this->assertSame(10 * 3600, (int) $reviews->sum('preassigned_overtime_seconds'));
        $this->assertSame(0, (int) $reviews[18]->preassigned_overtime_seconds);
    }

    public function test_weekly_flexible_ot_does_not_reset_on_october_11_period_boundary(): void
    {
        $campaign = Campaign::query()->create(['name' => 'PALMETTO']);
        $employee = Employee::query()->create([
            'name' => 'Flexible Agent', 'campaign_id' => $campaign->id, 'active' => true,
            'daily_hours' => 8, 'ordinary_weekly_hours' => 40,
            'preassigned_overtime_weekly_hours' => 10, 'overtime_distribution_mode' => 'weekly_flexible',
        ]);
        $previous = PayrollPeriod::query()->create([
            'name' => 'Período anterior', 'starts_at' => '2026-09-26', 'ends_at' => '2026-10-10', 'status' => 'cerrado',
        ]);
        DailyTimeReview::query()->create([
            'payroll_period_id' => $previous->id, 'employee_id' => $employee->id,
            'date' => '2026-10-10', 'status' => 'revisado_supervisor',
            'preassigned_overtime_seconds' => 36000, 'possible_overtime_seconds' => 36000,
        ]);
        $period = PayrollPeriod::query()->create([
            'name' => 'Período 11', 'starts_at' => '2026-10-11', 'ends_at' => '2026-10-11', 'status' => 'borrador',
        ]);
        $batch = TrackabiActivityImportBatch::query()->create([
            'payroll_period_id' => $period->id, 'activity_date' => '2026-10-11',
            'filename' => 'test.csv', 'file_hash' => str_repeat('d', 64),
            'rows' => 1, 'matched' => 1, 'unmatched' => 0, 'imported_at' => now(),
        ]);
        TrackabiDailyActivity::query()->create([
            'employee_id' => $employee->id, 'payroll_period_id' => $period->id,
            'import_batch_id' => $batch->id, 'activity_date' => '2026-10-11',
            'productive_activity_seconds' => 21600, 'unproductive_activity_seconds' => 0,
            'measured_activity_seconds' => 21600, 'imported_at' => now(),
        ]);
        app(PayrollCalculationService::class)->recalculateEmployeePreservingManual($period, $employee);
        $review = DailyTimeReview::query()->where('payroll_period_id', $period->id)->sole();
        $this->assertSame(0, (int) $review->expected_ordinary_seconds);
        $this->assertSame(0, (int) $review->preassigned_overtime_seconds);
    }

    public function test_fixed_weekly_off_day_starts_on_configured_date_and_never_creates_sunday_loss(): void
    {
        $campaign = Campaign::query()->create(['name' => 'RRD FINANCIAL']);
        $employee = Employee::query()->create([
            'name' => 'Weekend Worker', 'campaign_id' => $campaign->id, 'active' => true,
            'daily_hours' => 8, 'ordinary_weekly_hours' => 40,
            'fixed_weekly_off_day' => 7, 'fixed_weekly_off_from_date' => '2026-10-11',
        ]);
        $schedule = app(ScheduleExpectationService::class);
        $this->assertSame(28800, $schedule->forDate($employee, Carbon::parse('2026-10-04'))['expected_ordinary_seconds']);
        $this->assertSame(28800, $schedule->forDate($employee, Carbon::parse('2026-10-10'))['expected_ordinary_seconds']);
        $this->assertSame(0, $schedule->forDate($employee, Carbon::parse('2026-10-11'))['expected_ordinary_seconds']);
        $period = PayrollPeriod::query()->create([
            'name' => 'Domingo OFF', 'starts_at' => '2026-10-11', 'ends_at' => '2026-10-11', 'status' => 'borrador',
        ]);
        HubstaffTimeEntry::query()->create([
            'payroll_period_id' => $period->id, 'employee_id' => $employee->id,
            'hubstaff_member' => $employee->name, 'date' => '2026-10-11',
            'source_provider' => 'trackabi_api', 'active' => true, 'total_seconds' => 48120,
        ]);
        app(PayrollCalculationService::class)->recalculateEmployeePreservingManual($period, $employee);
        $review = DailyTimeReview::query()->where('payroll_period_id', $period->id)->sole();
        $this->assertFalse((bool) $review->scheduled_work_day);
        $this->assertSame(0, (int) $review->expected_hubstaff_seconds);
        $this->assertSame(0, $review->untrackedDayReviewSeconds());
        $this->assertSame(0, (int) $review->payable_seconds);
    }
}
