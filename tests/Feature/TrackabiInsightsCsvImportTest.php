<?php

namespace Tests\Feature;

use App\Filament\Resources\PayrollPeriods\Pages\EditPayrollPeriod;
use App\Filament\Resources\DailyTimeReviews\DailyTimeReviewResource;
use App\Filament\Resources\DailyTimeReviews\Pages\EditDailyTimeReview;
use App\Filament\Resources\PayrollPeriods\Pages\ListPayrollPeriods;
use App\Models\Campaign;
use App\Models\DailyTimeReview;
use App\Models\Employee;
use App\Models\HubstaffTimeEntry;
use App\Models\PayrollPeriod;
use App\Models\PayrollResult;
use App\Models\ScheduleType;
use App\Models\TrackabiDailyActivity;
use App\Models\TrackabiActivityImportBatch;
use App\Models\TrackingImportAlert;
use App\Models\User;
use App\Services\PayrollCalculationService;
use Illuminate\Validation\ValidationException;
use App\Services\Trackabi\TrackabiActivityCoverageService;
use App\Services\Trackabi\TrackabiPaidActivityService;
use App\Services\Trackabi\TrackabiPayrollActivityAuditService;
use App\Services\Trackabi\TrackabiInsightsCsvImportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class TrackabiInsightsCsvImportTest extends TestCase
{
    use RefreshDatabase;

    private PayrollPeriod $period;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        config(['trackabi.provider' => 'direct', 'trackabi_direct.cutover_date' => '2026-09-26',
            'trackabi_direct.insights_paid_lunch_minutes' => 0,
            'trackabi_direct.insights_paid_break_minutes' => 0,
            'trackabi_direct.insights_paid_time_policies' => [],
            'trackabi_direct.insights_special_paid_time_member_ids' => []]);
        Storage::fake('local');
        Http::preventStrayRequests();
        $campaign = Campaign::query()->create(['name' => 'RRD FINANCIAL']);
        $this->employee = Employee::query()->create([
            'name' => 'Dereck Aguilar', 'email' => 'dereck@example.com', 'campaign_id' => $campaign->id,
            'trackabi_name' => 'Aguilar, Dereck',
            'active' => true, 'daily_hours' => 8, 'hourly_rate' => 10,
        ]);
        $this->period = PayrollPeriod::query()->create([
            'name' => '26 septiembre a 10 octubre', 'starts_at' => '2026-09-26',
            'ends_at' => '2026-10-10', 'status' => 'borrador',
        ]);
    }

    private function review(int $target = 28800, string $status = 'pendiente'): DailyTimeReview
    {
        return DailyTimeReview::query()->create([
            'payroll_period_id' => $this->period->id, 'employee_id' => $this->employee->id,
            'date' => '2026-10-09', 'scheduled_work_day' => $target > 0,
            'expected_ordinary_seconds' => $target, 'expected_paid_seconds' => $target,
            'expected_hubstaff_seconds' => $target, 'hubstaff_total_seconds' => 36000,
            'status' => $status,
        ]);
    }

    private function csv(string $lines, string $path = 'trackabi-insights-2026-10-09.csv'): string
    {
        Storage::disk('local')->put($path, "\xEF\xBB\xBF\"Member Name\",\"Total logged\",\"Total billable\",\"Productive activity time\",\"Unproductive activity time\"\n".$lines);

        return Storage::disk('local')->path($path);
    }

    public function test_preview_parses_real_insights_headers_and_does_not_write_or_call_http(): void
    {
        $this->review();
        $path = $this->csv("\"Aguilar, Dereck\",08:51,08:51,05:33,01:32\n");
        $report = app(TrackabiInsightsCsvImportService::class)->preview($this->period, Carbon::parse('2026-10-09'), $path);

        $this->assertSame(1, $report['employees_matched']);
        $this->assertSame(25500, $report['measured_total_seconds']);
        $this->assertSame(3300, $report['rows'][0]['shortfall_seconds']);
        $this->assertDatabaseCount('trackabi_daily_activities', 0);
        $this->assertDatabaseCount('trackabi_activity_import_batches', 0);
        Http::assertNothingSent();
    }

    public function test_measured_gap_is_not_completed_by_logged_time_and_justification_returns_only_its_credit(): void
    {
        $review = $this->review();
        $path = $this->csv("\"Aguilar, Dereck\",10:23,10:23,06:30,01:15\n");
        app(TrackabiInsightsCsvImportService::class)->import($this->period, Carbon::parse('2026-10-09'), $path);

        $fresh = $review->fresh();
        $this->assertSame(27900, (int) $fresh->trackabiActivity->measured_activity_seconds);
        $this->assertSame(28800, (int) $fresh->expected_hubstaff_seconds);
        $this->assertSame(900, (int) $fresh->estimated_lost_seconds);
        $this->assertSame(27900, (int) $fresh->payable_seconds);

        $fresh->update(['justified_absence_seconds' => 600, 'status' => 'revisado_supervisor']);
        app(PayrollCalculationService::class)->recalculateEmployeePreservingManual($this->period, $this->employee);
        $audit = app(TrackabiPayrollActivityAuditService::class)->report($this->period);
        $row = collect($audit['rows'])->firstWhere('date', '2026-10-09');
        $this->assertSame(900, $row['raw_gap_seconds']);
        $this->assertSame(600, $row['justified_seconds']);
        $this->assertSame(300, $row['final_lost_seconds']);
        $this->assertSame(1, $audit['gap_distribution']['11-15 min']);
        $this->assertSame(0, $audit['unexplained_full_credit']);
    }

    public function test_audit_distinguishes_missing_activity_from_explicit_zero(): void
    {
        $this->review();
        $audit = app(TrackabiPayrollActivityAuditService::class)->report($this->period);
        $row = collect($audit['rows'])->firstWhere('date', '2026-10-09');
        $this->assertNull($row['desktop_activity_seconds']);
        $this->assertNull($row['raw_gap_seconds']);
        $this->assertSame(1, $audit['activity_missing']);
    }

    public function test_activity_gap_alert_is_idempotent_and_resolves_after_review(): void
    {
        $review = $this->review();
        $path = $this->csv("\"Aguilar, Dereck\",09:30,09:30,06:20,01:00\n");
        app(TrackabiInsightsCsvImportService::class)->import($this->period, Carbon::parse('2026-10-09'), $path);
        $audit = app(TrackabiPayrollActivityAuditService::class);
        $this->assertSame(1, $audit->report($this->period)['gap_above_observed_range']);
        $this->assertDatabaseMissing('tracking_import_alerts', ['code' => 'TRACKABI_ACTIVITY_GAP_ABOVE_OBSERVED_RANGE']);

        $audit->reconcileAlerts($this->period);
        $audit->reconcileAlerts($this->period);
        $this->assertSame(1, TrackingImportAlert::query()
            ->where('code', 'TRACKABI_ACTIVITY_GAP_ABOVE_OBSERVED_RANGE')->count());
        $review->update(['status' => 'revisado_supervisor', 'supervisor_comment' => 'Revisado']);
        $audit->reconcileAlerts($this->period);
        $this->assertDatabaseHas('tracking_import_alerts', [
            'code' => 'TRACKABI_ACTIVITY_GAP_ABOVE_OBSERVED_RANGE', 'resolved' => true,
        ]);
    }

    public function test_paid_lunch_and_break_credit_measured_day_without_erasing_remaining_loss(): void
    {
        config(['trackabi_direct.insights_paid_lunch_minutes' => 60,
            'trackabi_direct.insights_paid_break_minutes' => 15]);
        $review = $this->review();
        $path = $this->csv("\"Aguilar, Dereck\",09:00,09:00,04:30,00:59\n");
        app(TrackabiInsightsCsvImportService::class)->import($this->period, Carbon::parse('2026-10-09'), $path);

        $fresh = $review->fresh();
        $this->assertSame(19740, (int) $fresh->trackabiActivity->measured_activity_seconds);
        $this->assertSame(4500, (int) $fresh->paid_time_not_tracked_seconds);
        $this->assertSame(24300, (int) $fresh->expected_hubstaff_seconds);
        $this->assertSame(4560, (int) $fresh->estimated_lost_seconds);
        $this->assertSame(24240, (int) $fresh->payable_seconds);
        $this->assertSame('pendiente', $fresh->status);

        $fresh->update(['justified_absence_seconds' => 1800, 'status' => 'revisado_supervisor']);
        app(PayrollCalculationService::class)->recalculateEmployeePreservingManual($this->period, $this->employee);
        $this->assertSame(26040, (int) $review->fresh()->payable_seconds);
        $this->assertSame(19740, (int) $review->fresh()->trackabiActivity->measured_activity_seconds);
    }

    public function test_calendar_displays_credited_trackabi_time_and_shortfall_from_insights(): void
    {
        config(['trackabi_direct.insights_paid_lunch_minutes' => 60,
            'trackabi_direct.insights_paid_break_minutes' => 15]);
        $user = User::query()->create([
            'name' => 'RRHH', 'email' => 'rrhh-calendar-insights@example.com',
            'password' => 'password', 'profile' => 'rrhh', 'active' => true,
        ]);
        $review = $this->review();
        $path = $this->csv("\"Aguilar, Dereck\",06:58,06:58,04:30,00:59\n");
        app(TrackabiInsightsCsvImportService::class)->import($this->period, Carbon::parse('2026-10-09'), $path);

        $this->actingAs($user)
            ->get("/admin/daily-review-calendar?period_id={$this->period->id}&employee_id={$this->employee->id}")
            ->assertOk()
            ->assertSee('Trackabi')
            ->assertSee('6:44 h')
            ->assertSee('-1:16 h');

        $this->get("/admin/daily-time-reviews/{$review->id}/edit")
            ->assertOk()
            ->assertSee('Actividad medida sin pausas')
            ->assertSee('Timer original Trackabi');
        $this->assertSame(19740, (int) $review->fresh()->trackabiActivity->measured_activity_seconds);
    }

    public function test_calendar_credits_short_gap_within_paid_break_policy(): void
    {
        config(['trackabi_direct.insights_paid_lunch_minutes' => 60,
            'trackabi_direct.insights_paid_break_minutes' => 15]);
        $user = User::query()->create([
            'name' => 'RRHH', 'email' => 'rrhh-short-gap@example.com',
            'password' => 'password', 'profile' => 'rrhh', 'active' => true,
        ]);
        $this->review();
        $path = $this->csv("\"Aguilar, Dereck\",09:00,09:00,07:00,00:22\n");
        app(TrackabiInsightsCsvImportService::class)->import($this->period, Carbon::parse('2026-10-09'), $path);

        $this->actingAs($user)
            ->get("/admin/daily-review-calendar?period_id={$this->period->id}&employee_id={$this->employee->id}")
            ->assertOk()
            ->assertSee('8:00 h')
            ->assertSee('0:00 h')
            ->assertDontSee('Idle');
    }

    public function test_zero_activity_day_remains_sin_registro_and_links_to_review(): void
    {
        $user = User::query()->create([
            'name' => 'RRHH', 'email' => 'rrhh-zero-activity@example.com',
            'password' => 'password', 'profile' => 'rrhh', 'active' => true,
        ]);
        $review = $this->review();
        $review->update(['hubstaff_total_seconds' => 0]);
        $path = $this->csv("\"Aguilar, Dereck\",00:00,00:00,00:00,00:00\n");
        app(TrackabiInsightsCsvImportService::class)->import($this->period, Carbon::parse('2026-10-09'), $path);

        $this->actingAs($user)
            ->get("/admin/daily-review-calendar?period_id={$this->period->id}&employee_id={$this->employee->id}")
            ->assertOk()
            ->assertSee('Sin registro')
            ->assertSee("/admin/daily-time-reviews/{$review->id}/edit");
    }

    public function test_shortfall_up_to_paid_break_policy_is_credited_without_overpaying(): void
    {
        config(['trackabi_direct.insights_paid_lunch_minutes' => 60,
            'trackabi_direct.insights_paid_break_minutes' => 15]);
        $review = $this->review();
        $path = $this->csv("\"Aguilar, Dereck\",09:00,09:00,07:00,00:22\n");
        app(TrackabiInsightsCsvImportService::class)->import($this->period, Carbon::parse('2026-10-09'), $path);
        $this->assertSame(28800, (int) $review->fresh()->payable_seconds);
        $this->assertSame(0, (int) $review->fresh()->estimated_lost_seconds);
        $this->assertSame(4500, (int) $review->fresh()->paid_time_not_tracked_seconds);
        $this->assertSame(24300, (int) $review->fresh()->expected_hubstaff_seconds);

        $exact = $this->csv("\"Aguilar, Dereck\",09:00,09:00,06:00,00:45\n", 'exact-boundary.csv');
        app(TrackabiInsightsCsvImportService::class)->import($this->period, Carbon::parse('2026-10-09'), $exact);
        $this->assertSame(28800, (int) $review->fresh()->payable_seconds);
        $this->assertSame(0, (int) $review->fresh()->estimated_lost_seconds);
        $this->assertSame(4500, (int) $review->fresh()->paid_time_not_tracked_seconds);

        $above = $this->csv("\"Aguilar, Dereck\",09:00,09:00,06:00,00:44\n", 'above-boundary.csv');
        app(TrackabiInsightsCsvImportService::class)->import($this->period, Carbon::parse('2026-10-09'), $above);
        $this->assertSame(28740, (int) $review->fresh()->payable_seconds);
        $this->assertSame(60, (int) $review->fresh()->estimated_lost_seconds);
        $this->assertSame(4500, (int) $review->fresh()->paid_time_not_tracked_seconds);

        $zero = $this->csv("\"Aguilar, Dereck\",09:00,09:00,00:00,00:00\n", 'zero-activity.csv');
        app(TrackabiInsightsCsvImportService::class)->import($this->period, Carbon::parse('2026-10-09'), $zero);
        $this->assertSame(0, (int) $review->fresh()->payable_seconds);
        $this->assertSame(0, (int) $review->fresh()->paid_time_not_tracked_seconds);
    }

    public function test_profile_lunch_keeps_a_fixed_trackable_target_without_double_payment(): void
    {
        config(['trackabi_direct.insights_paid_lunch_minutes' => 60,
            'trackabi_direct.insights_paid_break_minutes' => 15]);
        $this->employee->update(['paid_lunch_minutes_per_workday' => 60,
            'paid_break_minutes_per_workday' => 15]);
        $review = $this->review();
        $path = $this->csv("\"Aguilar, Dereck\",09:00,09:00,07:00,00:00\n");
        app(TrackabiInsightsCsvImportService::class)->import($this->period, Carbon::parse('2026-10-09'), $path);

        $this->assertSame(4500, (int) $review->fresh()->paid_time_not_tracked_seconds);
        $this->assertSame(24300, (int) $review->fresh()->expected_hubstaff_seconds);
        $this->assertSame(28800, (int) $review->fresh()->payable_seconds);
    }

    public function test_rotating_day_retains_one_hour_lunch_credit_below_general_threshold(): void
    {
        config(['trackabi_direct.insights_paid_lunch_minutes' => 60,
            'trackabi_direct.insights_paid_break_minutes' => 15]);
        $schedule = ScheduleType::query()->create(['name' => 'Rotativa', 'code' => 'rotativa', 'active' => true]);
        $this->employee->update(['schedule_type_id' => $schedule->id, 'daily_hours' => 12]);
        $review = $this->review(43200);
        $service = app(TrackabiPaidActivityService::class);

        $this->assertSame(4500, $service->allowanceSeconds($review, 39600));
        $this->assertSame(38700, $service->trackableTargetSeconds($review, 39600));
        $this->assertSame(4500, $service->allowanceSeconds($review, 37800));
    }

    public function test_paid_break_policy_follows_campaign_and_palmetto_member_exceptions(): void
    {
        $configured = require base_path('config/trackabi_direct.php');
        config(['trackabi_direct.insights_paid_time_policies' => $configured['insights_paid_time_policies'],
            'trackabi_direct.insights_special_paid_time_member_ids' => $configured['insights_special_paid_time_member_ids']]);
        $review = $this->review();
        $service = app(TrackabiPaidActivityService::class);

        $this->assertSame(4800, $service->allowanceSeconds($review, 21600));
        $palmetto = Campaign::query()->create(['name' => 'PALMETTO']);
        $this->employee->update(['campaign_id' => $palmetto->id]);
        $review->unsetRelation('employee');
        $this->assertSame(4200, $service->allowanceSeconds($review, 21600));

        foreach ([63566, 63567, 63565] as $memberId) {
            $this->employee->update(['trackabi_member_id' => $memberId]);
            $review->unsetRelation('employee');
            $this->assertSame(5400, $service->allowanceSeconds($review, 21600));
        }

        $autoFinance = Campaign::query()->create(['name' => 'AUTO FINANCE CENTER']);
        $this->employee->update(['campaign_id' => $autoFinance->id, 'trackabi_member_id' => null]);
        $review->unsetRelation('employee');
        $this->assertSame(5400, $service->allowanceSeconds($review, 21600));
        $this->assertSame(5400, $service->allowanceSeconds($review, 27000));
        $this->assertSame(5400, $service->allowanceSeconds($review, 32400));
        $this->assertSame(0, $service->allowanceSeconds($review, 0));
    }

    public function test_policy_change_updates_pending_day_but_preserves_applied_review_basis(): void
    {
        config(['trackabi_direct.insights_paid_lunch_minutes' => 60,
            'trackabi_direct.insights_paid_break_minutes' => 15]);
        $appliedReview = $this->review();
        $service = app(TrackabiInsightsCsvImportService::class);
        $original = $this->csv("\"Aguilar, Dereck\",08:00,08:00,05:30,00:30\n");
        $service->import($this->period, Carbon::parse('2026-10-09'), $original);
        $pendingReview = DailyTimeReview::query()->where('payroll_period_id', $this->period->id)
            ->where('employee_id', $this->employee->id)->whereDate('date', '2026-10-08')->firstOrFail();
        $pendingCsv = $this->csv("\"Aguilar, Dereck\",08:01,08:01,05:30,00:30\n", 'pending-policy-day.csv');
        $service->import($this->period, Carbon::parse('2026-10-08'), $pendingCsv);
        $appliedReview->update(['status' => 'revisado_supervisor', 'justified_absence_seconds' => 1200,
            'supervisor_comment' => 'Justificación revisada']);
        app(PayrollCalculationService::class)->recalculateEmployeePreservingManual($this->period, $this->employee);
        $appliedBefore = $appliedReview->fresh();
        $pendingBefore = $pendingReview->fresh();

        config(['trackabi_direct.insights_paid_time_policies' => [
            'RRD FINANCIAL' => ['lunch_minutes' => 60, 'break_minutes' => 20],
        ]]);
        app(PayrollCalculationService::class)->recalculateEmployeePreservingManual($this->period, $this->employee);

        $appliedAfter = $appliedReview->fresh();
        $pendingAfter = $pendingReview->fresh();
        $this->assertSame((int) $appliedBefore->paid_time_not_tracked_seconds,
            (int) $appliedAfter->paid_time_not_tracked_seconds);
        $this->assertSame((int) $appliedBefore->payable_seconds, (int) $appliedAfter->payable_seconds);
        $this->assertSame((int) $appliedBefore->justified_absence_seconds,
            (int) $appliedAfter->justified_absence_seconds);
        $this->assertSame($appliedBefore->supervisor_comment, $appliedAfter->supervisor_comment);
        $this->assertSame(4800, (int) $pendingAfter->paid_time_not_tracked_seconds);
        $this->assertSame((int) $pendingBefore->payable_seconds + 300,
            (int) $pendingAfter->payable_seconds);
    }

    public function test_paid_time_policy_preview_reports_changes_without_writing_reviews(): void
    {
        config(['trackabi_direct.insights_paid_lunch_minutes' => 60,
            'trackabi_direct.insights_paid_break_minutes' => 15]);
        $review = $this->review();
        $path = $this->csv("\"Aguilar, Dereck\",08:00,08:00,05:30,00:30\n");
        app(TrackabiInsightsCsvImportService::class)->import($this->period, Carbon::parse('2026-10-09'), $path);
        $before = $review->fresh()->getAttributes();

        config(['trackabi_direct.insights_paid_time_policies' => [
            'RRD FINANCIAL' => ['lunch_minutes' => 60, 'break_minutes' => 20],
        ]]);
        $this->artisan('trackabi:preview-paid-time', ['--period' => $this->period->id])
            ->expectsOutputToContain('Días pendientes que cambiarían: 1')
            ->assertSuccessful();

        $this->assertSame($before, $review->fresh()->getAttributes());
    }

    public function test_paid_time_policy_apply_prorates_existing_justification_and_is_idempotent(): void
    {
        config(['trackabi_direct.insights_paid_lunch_minutes' => 60,
            'trackabi_direct.insights_paid_break_minutes' => 15]);
        $review = $this->review();
        $path = $this->csv("\"Aguilar, Dereck\",08:00,08:00,05:30,00:30\n");
        app(TrackabiInsightsCsvImportService::class)->import($this->period, Carbon::parse('2026-10-09'), $path);
        $review->update(['status' => 'revisado_supervisor', 'justified_absence_seconds' => 1200,
            'unjustified_absence_seconds' => 1500, 'supervisor_comment' => 'Justificado por supervisor']);
        app(PayrollCalculationService::class)->recalculateEmployeePreservingManual($this->period, $this->employee);
        $before = $review->fresh();

        config(['trackabi_direct.insights_paid_time_policies' => [
            'RRD FINANCIAL' => ['lunch_minutes' => 60, 'break_minutes' => 20],
        ]]);
        $this->artisan('trackabi:preview-paid-time', ['--period' => $this->period->id, '--apply' => true])
            ->expectsOutputToContain('Revisiones aplicadas ajustadas proporcionalmente: 1')
            ->assertSuccessful();

        $after = $review->fresh();
        $this->assertSame(4800, (int) $after->paid_time_not_tracked_seconds);
        $this->assertSame(2400, (int) $after->estimated_lost_seconds);
        $this->assertSame(1067, (int) $after->justified_absence_seconds);
        $this->assertSame(1333, (int) $after->unjustified_absence_seconds);
        $this->assertSame((int) $before->payable_seconds + 167, (int) $after->payable_seconds);
        $this->assertSame($before->status, $after->status);
        $this->assertSame($before->supervisor_comment, $after->supervisor_comment);
        $this->assertSame((int) $before->trackabiActivity->measured_activity_seconds,
            (int) $after->trackabiActivity->measured_activity_seconds);
        $this->assertSame(4500, (int) $after->lost_time_estimate_metadata['paid_time_policy_adjustment']['previous_allowance_seconds']);

        $attributes = $after->getAttributes();
        $this->artisan('trackabi:preview-paid-time', ['--period' => $this->period->id, '--apply' => true])
            ->expectsOutputToContain('Revisiones aplicadas ajustadas proporcionalmente: 0')
            ->assertSuccessful();
        $this->assertSame($attributes, $review->fresh()->getAttributes());
    }

    public function test_paid_time_policy_apply_keeps_fully_justified_day_fully_paid(): void
    {
        config(['trackabi_direct.insights_paid_lunch_minutes' => 60,
            'trackabi_direct.insights_paid_break_minutes' => 15]);
        $review = $this->review();
        $path = $this->csv("\"Aguilar, Dereck\",08:00,08:00,05:30,00:30\n");
        app(TrackabiInsightsCsvImportService::class)->import($this->period, Carbon::parse('2026-10-09'), $path);
        $review->update(['status' => 'revisado_supervisor', 'justified_absence_seconds' => 2700,
            'unjustified_absence_seconds' => 0]);
        config(['trackabi_direct.insights_paid_time_policies' => [
            'RRD FINANCIAL' => ['lunch_minutes' => 60, 'break_minutes' => 20],
        ]]);

        $this->artisan('trackabi:preview-paid-time', ['--period' => $this->period->id, '--apply' => true])
            ->assertSuccessful();
        $this->assertSame(2400, (int) $review->fresh()->justified_absence_seconds);
        $this->assertSame(0, (int) $review->fresh()->unjustified_absence_seconds);
        $this->assertSame(28800, (int) $review->fresh()->payable_seconds);
    }

    public function test_paid_time_policy_apply_keeps_unjustified_day_unjustified(): void
    {
        config(['trackabi_direct.insights_paid_lunch_minutes' => 60,
            'trackabi_direct.insights_paid_break_minutes' => 15]);
        $review = $this->review();
        $path = $this->csv("\"Aguilar, Dereck\",08:00,08:00,05:30,00:30\n");
        app(TrackabiInsightsCsvImportService::class)->import($this->period, Carbon::parse('2026-10-09'), $path);
        $review->update(['status' => 'revisado_supervisor']);
        config(['trackabi_direct.insights_paid_time_policies' => [
            'RRD FINANCIAL' => ['lunch_minutes' => 60, 'break_minutes' => 20],
        ]]);

        $this->artisan('trackabi:preview-paid-time', ['--period' => $this->period->id, '--apply' => true])
            ->assertSuccessful();
        $this->assertSame(0, (int) $review->fresh()->justified_absence_seconds);
        $this->assertSame(2400, (int) $review->fresh()->unjustified_absence_seconds);
        $this->assertSame(26400, (int) $review->fresh()->payable_seconds);
    }

    public function test_pending_review_with_manual_justification_keeps_its_basis_during_policy_recalculation(): void
    {
        config(['trackabi_direct.insights_paid_lunch_minutes' => 60,
            'trackabi_direct.insights_paid_break_minutes' => 15]);
        $review = $this->review();
        $path = $this->csv("\"Aguilar, Dereck\",08:00,08:00,05:30,00:30\n");
        app(TrackabiInsightsCsvImportService::class)->import($this->period, Carbon::parse('2026-10-09'), $path);
        $review->update(['justified_absence_seconds' => 600, 'unjustified_absence_seconds' => 2100]);
        $before = $review->fresh();

        config(['trackabi_direct.insights_paid_time_policies' => [
            'RRD FINANCIAL' => ['lunch_minutes' => 60, 'break_minutes' => 20],
        ]]);
        app(PayrollCalculationService::class)->recalculateEmployeePreservingManual($this->period, $this->employee);

        $after = $review->fresh();
        $this->assertSame((int) $before->paid_time_not_tracked_seconds, (int) $after->paid_time_not_tracked_seconds);
        $this->assertSame((int) $before->expected_hubstaff_seconds, (int) $after->expected_hubstaff_seconds);
        $this->assertSame((int) $before->justified_absence_seconds, (int) $after->justified_absence_seconds);
        $this->assertSame((int) $before->unjustified_absence_seconds, (int) $after->unjustified_absence_seconds);
    }

    public function test_paid_time_policy_apply_refuses_changed_activity_after_review_without_writing(): void
    {
        config(['trackabi_direct.insights_paid_lunch_minutes' => 60,
            'trackabi_direct.insights_paid_break_minutes' => 15]);
        $review = $this->review();
        $path = $this->csv("\"Aguilar, Dereck\",08:00,08:00,05:30,00:30\n");
        app(TrackabiInsightsCsvImportService::class)->import($this->period, Carbon::parse('2026-10-09'), $path);
        $review->update(['status' => 'revisado_supervisor', 'justified_absence_seconds' => 1200,
            'unjustified_absence_seconds' => 1500]);
        $review->trackabiActivity->update(['measured_activity_seconds' => 21000]);
        $before = $review->fresh()->getAttributes();

        config(['trackabi_direct.insights_paid_time_policies' => [
            'RRD FINANCIAL' => ['lunch_minutes' => 60, 'break_minutes' => 20],
        ]]);
        $this->artisan('trackabi:preview-paid-time', ['--period' => $this->period->id, '--apply' => true])
            ->assertFailed();
        $this->assertSame($before, $review->fresh()->getAttributes());
    }

    public function test_off_worked_requires_confirmed_hours_and_pays_full_extra_day(): void
    {
        $review = $this->review();
        $path = $this->csv("\"Aguilar, Dereck\",04:00,04:00,03:00,01:00\n");
        app(TrackabiInsightsCsvImportService::class)->import($this->period, Carbon::parse('2026-10-09'), $path);
        $this->expectException(ValidationException::class);
        DailyTimeReviewResource::secondsFromHourStates([
            'off_worked' => true, 'off_worked_verified_hours' => '0:00',
        ], $review->fresh());
    }

    public function test_off_worked_rejects_confirmation_above_measured_activity(): void
    {
        $review = $this->review();
        $path = $this->csv("\"Aguilar, Dereck\",04:00,04:00,03:00,01:00\n");
        app(TrackabiInsightsCsvImportService::class)->import($this->period, Carbon::parse('2026-10-09'), $path);
        $this->expectException(ValidationException::class);
        DailyTimeReviewResource::secondsFromHourStates([
            'off_worked' => true, 'off_worked_verified_hours' => '5:00',
        ], $review->fresh());
    }

    public function test_off_worked_with_partial_confirmed_hours_still_pays_full_extra_day(): void
    {
        $review = $this->review();
        $path = $this->csv("\"Aguilar, Dereck\",04:00,04:00,03:00,01:00\n");
        app(TrackabiInsightsCsvImportService::class)->import($this->period, Carbon::parse('2026-10-09'), $path);
        $data = DailyTimeReviewResource::secondsFromHourStates([
            'off_worked' => true, 'off_worked_verified_hours' => '4:00',
        ], $review->fresh());
        $this->assertTrue($data['paid_day_off']);
        $this->assertSame(14400, $data['off_worked_verified_seconds']);
        $review->update($data);
        app(PayrollCalculationService::class)->recalculateEmployeePreservingManual($this->period, $this->employee);

        $result = PayrollResult::query()->where('payroll_period_id', $this->period->id)->firstOrFail();
        $this->assertSame(28800, (int) $review->fresh()->payable_seconds);
        $this->assertEqualsWithDelta((float) $result->daily_rate, (float) $result->off_worked_extra_amount, 0.01);
        $this->assertSame(14400, (int) $review->fresh()->trackabiActivity->measured_activity_seconds);
    }

    public function test_direct_review_form_shows_compact_fields_and_calculation_tab(): void
    {
        $user = User::query()->create([
            'name' => 'RRHH', 'email' => 'rrhh-direct-review@example.com',
            'password' => 'password', 'profile' => 'rrhh', 'active' => true,
        ]);
        $review = $this->review();
        $this->actingAs($user)->get("/admin/daily-time-reviews/{$review->id}/edit")
            ->assertOk()
            ->assertSee('Total Hora Trackabi')
            ->assertSee('Detalle de cálculo')
            ->assertSee('Tiempo justificado');
    }

    public function test_direct_review_form_saves_justification_and_restores_payable_time(): void
    {
        $user = User::query()->create([
            'name' => 'RRHH', 'email' => 'rrhh-direct-save@example.com',
            'password' => 'password', 'profile' => 'rrhh', 'active' => true,
        ]);
        $review = $this->review();
        $path = $this->csv("\"Aguilar, Dereck\",08:51,08:51,05:33,01:32\n");
        app(TrackabiInsightsCsvImportService::class)->import($this->period, Carbon::parse('2026-10-09'), $path);
        $before = $review->fresh();
        $this->actingAs($user);

        Livewire::test(EditDailyTimeReview::class, ['record' => $review->id])
            ->set('data.direct_justified_hours', '0:30')
            ->call('save')
            ->assertHasNoErrors();

        $after = $review->fresh();
        $this->assertSame('revisado_supervisor', $after->status);
        $this->assertSame(1800, (int) $after->justified_absence_seconds);
        $this->assertSame((int) $before->payable_seconds + 1800, (int) $after->payable_seconds);
        $this->assertSame(25500, (int) $after->trackabiActivity->measured_activity_seconds);
        $this->assertSame((int) $before->hubstaff_total_seconds, (int) $after->hubstaff_total_seconds);
    }

    public function test_applied_justification_survives_other_review_recalculation_and_csv_reimport(): void
    {
        config(['trackabi_direct.insights_paid_lunch_minutes' => 60,
            'trackabi_direct.insights_paid_break_minutes' => 15]);
        $user = User::query()->create([
            'name' => 'RRHH', 'email' => 'rrhh-persistent-review@example.com',
            'password' => 'password', 'profile' => 'rrhh', 'active' => true,
        ]);
        $review = $this->review();
        $original = $this->csv("\"Aguilar, Dereck\",09:00,09:00,05:30,00:30\n");
        $importer = app(TrackabiInsightsCsvImportService::class);
        $importer->import($this->period, Carbon::parse('2026-10-09'), $original);
        $this->actingAs($user);

        Livewire::test(EditDailyTimeReview::class, ['record' => $review->id])
            ->set('data.direct_justified_hours', '0:20')
            ->set('data.supervisor_comment', 'Justificación verificada')
            ->call('save')
            ->assertHasNoErrors();

        $applied = $review->fresh();
        $this->assertSame('revisado_supervisor', $applied->status);
        $this->assertSame(1200, (int) $applied->justified_absence_seconds);
        $this->assertSame(27300, (int) $applied->payable_seconds);

        $other = DailyTimeReview::query()->where('payroll_period_id', $this->period->id)
            ->where('employee_id', $this->employee->id)->whereDate('date', '2026-10-08')->firstOrFail();
        $other->update([
            'paid_day_off' => true,
            'status' => 'revisado_supervisor', 'supervisor_comment' => 'Día OFF',
        ]);
        app(PayrollCalculationService::class)->recalculateEmployeePreservingManual($this->period, $this->employee);
        $importer->import($this->period, Carbon::parse('2026-10-09'), $original);
        $payrollBeforeCorrection = PayrollResult::query()->where('payroll_period_id', $this->period->id)
            ->where('employee_id', $this->employee->id)->firstOrFail();
        $approvedSalary = (float) $payrollBeforeCorrection->worked_salary_amount;
        $approvedLostAmount = (float) $payrollBeforeCorrection->lost_time_amount;

        $corrected = $this->csv("\"Aguilar, Dereck\",09:00,09:00,04:30,00:30\n", 'corrected-after-review.csv');
        $importer->import($this->period, Carbon::parse('2026-10-09'), $corrected);
        app(PayrollCalculationService::class)->recalculateEmployeePreservingManual($this->period, $this->employee);

        $after = $review->fresh();
        $this->assertSame($applied->status, $after->status);
        $this->assertSame($applied->reviewed_by, $after->reviewed_by);
        $this->assertSame($applied->supervisor_comment, $after->supervisor_comment);
        $this->assertSame((int) $applied->justified_absence_seconds, (int) $after->justified_absence_seconds);
        $this->assertSame((int) $applied->payable_seconds, (int) $after->payable_seconds);
        $this->assertTrue($other->fresh()->paid_day_off);
        $payrollAfterCorrection = $payrollBeforeCorrection->fresh();
        $this->assertEqualsWithDelta($approvedSalary, (float) $payrollAfterCorrection->worked_salary_amount, 0.01);
        $this->assertEqualsWithDelta($approvedLostAmount, (float) $payrollAfterCorrection->lost_time_amount, 0.01);
        $this->assertDatabaseHas('tracking_import_alerts', [
            'code' => 'TRACKABI_ACTIVITY_DATA_AFTER_REVIEW', 'resolved' => false,
        ]);
    }

    public function test_direct_off_worked_option_only_appears_with_activity_and_pays_double_day(): void
    {
        $user = User::query()->create([
            'name' => 'RRHH', 'email' => 'rrhh-off-worked@example.com',
            'password' => 'password', 'profile' => 'rrhh', 'active' => true,
        ]);
        $review = $this->review();
        $review->update(['hubstaff_total_seconds' => 0]);
        $this->actingAs($user);

        Livewire::test(EditDailyTimeReview::class, ['record' => $review->id])
            ->assertDontSee('Día OFF trabajado');

        Livewire::test(EditDailyTimeReview::class, ['record' => $review->id])
            ->set('data.off_worked', true)
            ->set('data.off_worked_verified_hours', '4:00')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse((bool) $review->fresh()->off_worked);
        $path = $this->csv("\"Aguilar, Dereck\",04:00,04:00,03:00,01:00\n");
        app(TrackabiInsightsCsvImportService::class)->import($this->period, Carbon::parse('2026-10-09'), $path);

        Livewire::test(EditDailyTimeReview::class, ['record' => $review->id])
            ->assertSee('Día OFF trabajado')
            ->set('data.off_worked', true)
            ->set('data.off_worked_verified_hours', '4:00')
            ->call('save')
            ->assertHasNoErrors();

        $result = PayrollResult::query()->where('payroll_period_id', $this->period->id)->firstOrFail();
        $this->assertTrue($review->fresh()->paid_day_off);
        $this->assertSame(14400, (int) $review->fresh()->off_worked_verified_seconds);
        $this->assertEqualsWithDelta((float) $result->daily_rate, (float) $result->off_worked_extra_amount, 0.01);
    }

    public function test_off_worked_cannot_be_set_without_insights_activity_even_when_timer_exists(): void
    {
        $review = $this->review();
        $this->expectException(ValidationException::class);
        DailyTimeReviewResource::secondsFromHourStates([
            'off_worked' => true, 'off_worked_verified_hours' => '4:00',
        ], $review);
    }

    public function test_rotating_unscheduled_off_worked_never_pays_more_than_double_day(): void
    {
        $schedule = ScheduleType::query()->create(['name' => 'Rotativa', 'code' => 'rotativa', 'active' => true]);
        $this->employee->update(['schedule_type_id' => $schedule->id, 'daily_hours' => 12,
            'salary_calculation_method' => 'scheduled_shift_prorated']);
        $review = $this->review(0);
        $review->update(['hubstaff_total_seconds' => 0, 'scheduled_work_day' => false,
            'paid_day_off' => true, 'off_worked' => true, 'off_worked_verified_seconds' => 14400]);
        $batch = TrackabiActivityImportBatch::query()->create([
            'payroll_period_id' => $this->period->id, 'activity_date' => '2026-10-09',
            'filename' => 'rotating.csv', 'file_hash' => str_repeat('a', 64),
            'rows' => 1, 'matched' => 1, 'unmatched' => 0, 'imported_at' => now(),
        ]);
        TrackabiDailyActivity::query()->create([
            'employee_id' => $this->employee->id, 'payroll_period_id' => $this->period->id,
            'import_batch_id' => $batch->id, 'activity_date' => '2026-10-09',
            'productive_activity_seconds' => 14400, 'unproductive_activity_seconds' => 0,
            'measured_activity_seconds' => 14400, 'imported_at' => now(),
        ]);

        app(PayrollCalculationService::class)->recalculateEmployeePayrollResult($this->period, $this->employee);
        $scheduled = PayrollResult::query()->where('payroll_period_id', $this->period->id)->firstOrFail();
        $this->assertEqualsWithDelta(2 * (float) $scheduled->daily_rate,
            (float) $scheduled->off_worked_extra_amount, 0.01);

        $this->employee->update(['salary_calculation_method' => 'monthly_calendar_prorated']);
        app(PayrollCalculationService::class)->recalculateEmployeePayrollResult($this->period, $this->employee->fresh());
        $monthly = $scheduled->fresh();
        $this->assertEqualsWithDelta((float) $monthly->daily_rate,
            (float) $monthly->off_worked_extra_amount, 0.01);
        $this->assertEqualsWithDelta(2 * (float) $monthly->daily_rate,
            (float) $monthly->gross_amount, 0.01);
    }

    public function test_preview_uses_trackabi_name_not_hubstaff_name_and_email_or_member_id_when_provided(): void
    {
        $this->employee->update(['trackabi_name' => null, 'hubstaff_name' => 'Aguilar, Dereck', 'trackabi_member_id' => 123]);
        $path = $this->csv("\"Aguilar, Dereck\",08:51,08:51,05:33,01:32\n");
        $service = app(TrackabiInsightsCsvImportService::class);
        $plan = $service->preview($this->period, Carbon::parse('2026-10-09'), $path);
        $this->assertSame(0, $plan['employees_matched']);
        $this->assertCount(1, $plan['employees_unmatched']);

        Storage::disk('local')->put('with-email.csv', "Member Name,Email,Productive activity time,Unproductive activity time\n\"Aguilar, Dereck\",dereck@example.com,05:33,01:32\n");
        $emailPlan = $service->preview($this->period, Carbon::parse('2026-10-09'), Storage::disk('local')->path('with-email.csv'));
        $this->assertSame(1, $emailPlan['employees_matched']);

        Storage::disk('local')->put('with-id.csv', "Member Name,Member ID,Email,Productive activity time,Unproductive activity time\n\"Other, Name\",123,wrong@example.com,05:33,01:32\n");
        $idPlan = $service->preview($this->period, Carbon::parse('2026-10-09'), Storage::disk('local')->path('with-id.csv'));
        $this->assertSame(1, $idPlan['employees_matched']);
        $this->assertSame($this->employee->id, $idPlan['rows'][0]['employee_id']);
        $this->assertSame('Aguilar, Dereck', $this->employee->fresh()->hubstaff_name);
    }

    public function test_artisan_dry_run_has_no_database_writes(): void
    {
        $this->review();
        $path = $this->csv("\"Aguilar, Dereck\",08:51,08:51,05:33,01:32\n");
        $this->artisan('trackabi:import-insights', [
            'file' => $path, '--date' => '2026-10-09', '--period' => $this->period->id, '--dry-run' => true,
        ])->expectsOutputToContain('encontrados: 1')->assertSuccessful();
        $this->assertDatabaseCount('trackabi_daily_activities', 0);
        $this->assertDatabaseCount('tracking_import_alerts', 0);
        Http::assertNothingSent();
    }

    public function test_rrhh_can_see_insights_preview_action_on_open_direct_period(): void
    {
        $user = User::query()->create([
            'name' => 'RRHH', 'email' => 'rrhh-insights@example.com',
            'password' => 'password', 'profile' => 'rrhh', 'active' => true,
        ]);
        $this->actingAs($user);

        Livewire::test(EditPayrollPeriod::class, ['record' => $this->period->id])
            ->assertSee('Importar actividad Trackabi');
    }

    public function test_rrhh_can_preview_and_confirm_insights_csv_in_filament(): void
    {
        $user = User::query()->create([
            'name' => 'RRHH', 'email' => 'rrhh-csv@example.com',
            'password' => 'password', 'profile' => 'rrhh', 'active' => true,
        ]);
        $this->actingAs($user);
        $this->review();
        $csv = "Member Name,Productive activity time,Unproductive activity time\n\"Aguilar, Dereck\",07:20,00:10\n";
        Storage::disk('local')->put('trackabi-insights/test.csv', $csv);

        Livewire::test(EditPayrollPeriod::class, ['record' => $this->period->id])
            ->mountAction('previewTrackabiInsights')
            ->set('mountedActions.0.data.activity_date', '2026-10-09')
            ->set('mountedActions.0.data.file', ['test-file' => 'trackabi-insights/test.csv'])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified();

        $this->assertDatabaseCount('trackabi_daily_activities', 0);
        $this->assertNotNull(session('trackabi_insights_preview_'.$this->period->id));

        Livewire::test(EditPayrollPeriod::class, ['record' => $this->period->id])
            ->callAction('confirmTrackabiInsights')
            ->assertNotified();
        $this->assertDatabaseCount('trackabi_daily_activities', 1);
    }

    public function test_valid_activity_creates_missing_review_without_accepting_suspicious_timer(): void
    {
        $path = $this->csv("\"Aguilar, Dereck\",120:10,120:10,07:20,00:10\n");
        $service = app(TrackabiInsightsCsvImportService::class);
        $preview = $service->preview($this->period, Carbon::parse('2026-10-09'), $path);
        $this->assertSame(1, $preview['would_create_reviews']);
        $this->assertDatabaseCount('daily_time_reviews', 0);

        $service->import($this->period, Carbon::parse('2026-10-09'), $path);

        $review = DailyTimeReview::query()->where('employee_id', $this->employee->id)
            ->whereDate('date', '2026-10-09')->sole();
        $this->assertSame('trackabi_insights_activity', $review->lost_time_source);
        $this->assertSame(1800, (int) $review->estimated_lost_seconds);
        $this->assertDatabaseCount('hubstaff_time_entries', 0);
        $this->assertDatabaseCount('trackabi_daily_activities', 1);
    }

    public function test_commit_is_idempotent_and_uses_measured_activity_not_logged_time(): void
    {
        $review = $this->review();
        $path = $this->csv("\"Aguilar, Dereck\",10:22,10:22,05:33,01:32\n");
        $service = app(TrackabiInsightsCsvImportService::class);
        $result = $service->import($this->period, Carbon::parse('2026-10-09'), $path);

        $this->assertSame(1, $result['written']);
        $activity = TrackabiDailyActivity::query()->sole();
        $this->assertSame(19980, (int) $activity->productive_activity_seconds);
        $this->assertSame(5520, (int) $activity->unproductive_activity_seconds);
        $this->assertSame(25500, (int) $activity->measured_activity_seconds);
        $this->assertSame(25500, (int) $review->fresh()->trackabiActivity?->measured_activity_seconds);
        $this->assertSame('10:22', $activity->raw_metadata['logged']);
        $this->assertSame('trackabi_insights_activity', $review->fresh()->lost_time_source);
        $this->assertSame(3300, (int) $review->fresh()->estimated_lost_seconds);
        $this->assertSame(25500, $review->fresh()->payrollTrackedSeconds());

        $second = $service->import($this->period, Carbon::parse('2026-10-09'), $path);
        $this->assertSame(0, $second['written']);
        $this->assertDatabaseCount('trackabi_daily_activities', 1);
        $this->assertDatabaseCount('trackabi_activity_import_batches', 1);
        Http::assertNothingSent();
    }

    public function test_explicit_zero_is_present_and_reduces_payable_time_while_pending(): void
    {
        $review = $this->review();
        HubstaffTimeEntry::query()->create([
            'payroll_period_id' => $this->period->id, 'employee_id' => $this->employee->id,
            'date' => '2026-10-09', 'hubstaff_member' => $this->employee->name,
            'source_provider' => 'trackabi_api', 'direct_external_id' => 'zero-activity-timer',
            'total_seconds' => 36000, 'regular_seconds' => 36000, 'active' => true,
        ]);
        $path = $this->csv("\"Aguilar, Dereck\",11:14,11:14,00:00,00:00\n");
        app(TrackabiInsightsCsvImportService::class)->import($this->period, Carbon::parse('2026-10-09'), $path);

        $this->assertSame(0, (int) TrackabiDailyActivity::query()->sole()->measured_activity_seconds);
        $this->assertSame('pendiente', $review->fresh()->status);
        $this->assertFalse($review->fresh()->paid_day_off);
        $this->assertSame(28800, (int) $review->fresh()->estimated_lost_seconds);
        $this->assertSame(0, $review->fresh()->payrollTrackedSeconds());
        $this->assertSame(28800, $review->fresh()->finalEstimatedLostSeconds());
        $this->assertSame(1, app(TrackabiActivityCoverageService::class)->summary($this->period)['zero']);

        $review->fresh()->update(['status' => 'revisado_supervisor', 'justified_absence_seconds' => 1800]);
        app(PayrollCalculationService::class)->recalculateDailyReview($review->fresh());
        $this->assertSame(0, $review->fresh()->payrollTrackedSeconds());
        $this->assertSame(27000, $review->fresh()->finalEstimatedLostSeconds());
    }

    public function test_missing_insights_creates_alert_and_blocks_period_close(): void
    {
        $review = $this->review();
        app(TrackabiActivityCoverageService::class)->reconcileEmployee($this->period, $this->employee);

        $this->assertSame('missing_activity', $review->fresh()->lost_time_source);
        $this->assertNull($review->fresh()->trackabiActivity);
        $this->assertSame('pendiente', $review->fresh()->status);
        $this->assertFalse($review->fresh()->paid_day_off);
        $this->assertSame(28800, $review->fresh()->payrollTrackedSeconds());
        $this->assertSame(0, (int) $review->fresh()->estimated_lost_seconds);
        $this->assertSame(1, app(TrackabiActivityCoverageService::class)->summary($this->period)['missing']);
        $this->assertDatabaseHas('tracking_import_alerts', ['code' => 'TRACKABI_ACTIVITY_MISSING',
            'external_id' => 'activity:'.$this->employee->id.':2026-10-09', 'resolved' => false]);

        $user = User::query()->create(['name' => 'RRHH', 'email' => 'rrhh-close@example.com',
            'password' => 'password', 'profile' => 'rrhh', 'active' => true]);
        $this->actingAs($user);
        Livewire::test(ListPayrollPeriods::class)->callTableAction('closePeriod', $this->period)->assertNotified();
        $this->assertNotSame('cerrado', $this->period->fresh()->status);
    }

    public function test_missing_timer_deducts_full_ordinary_day_even_when_lunch_is_paid(): void
    {
        $this->employee->update(['paid_lunch_minutes_per_workday' => 60,
            'lunch_included_in_hubstaff_total' => false]);
        $review = $this->review(25200);
        $review->update(['hubstaff_total_seconds' => 0]);
        app(PayrollCalculationService::class)->recalculateEmployeePreservingManual($this->period, $this->employee);

        $fresh = $review->fresh();
        $this->assertSame('missing_timer', $fresh->lost_time_source);
        $this->assertSame(25200, (int) $fresh->expected_hubstaff_seconds);
        $this->assertSame(3600, (int) $fresh->paid_time_not_tracked_seconds);
        $this->assertSame(0, (int) $fresh->payable_seconds);
        $this->assertSame(28800, (int) $fresh->unjustified_absence_seconds);
    }

    public function test_explicit_zero_activity_without_timer_keeps_full_day_loss_after_recalculation(): void
    {
        $this->employee->update(['paid_lunch_minutes_per_workday' => 60,
            'lunch_included_in_hubstaff_total' => false]);
        $review = $this->review(25200);
        $review->update(['hubstaff_total_seconds' => 0]);
        $path = $this->csv("\"Aguilar, Dereck\",00:00,00:00,00:00,00:00\n");

        app(TrackabiInsightsCsvImportService::class)->import($this->period, Carbon::parse('2026-10-09'), $path);
        app(PayrollCalculationService::class)->recalculateEmployeePreservingManual($this->period, $this->employee);

        $this->assertSame('pendiente', $review->fresh()->status);
        $this->assertFalse($review->fresh()->paid_day_off);
        $this->assertSame(28800, (int) $review->fresh()->estimated_lost_seconds);
        $this->assertSame(28800, $review->fresh()->finalEstimatedLostSeconds());
        $this->assertSame(0, (int) $review->fresh()->payable_seconds);
        $this->assertSame(15 * 28800, (int) PayrollResult::query()->sole()->lost_time_seconds);
    }

    public function test_high_logged_time_does_not_erase_measured_shortfall_or_add_overtime(): void
    {
        $review = $this->review();
        $service = app(TrackabiInsightsCsvImportService::class);
        $path = $this->csv("\"Aguilar, Dereck\",11:14,11:14,06:20,01:15\n");
        $service->import($this->period, Carbon::parse('2026-10-09'), $path);

        $this->assertSame(22800 + 4500, (int) TrackabiDailyActivity::query()->sole()->measured_activity_seconds);
        $this->assertSame(1500, (int) $review->fresh()->estimated_lost_seconds);
        $this->assertSame(27300, $review->fresh()->payrollTrackedSeconds());
        $this->assertSame('11:14', TrackabiDailyActivity::query()->sole()->raw_metadata['logged']);

        $overTarget = $this->csv("\"Aguilar, Dereck\",11:14,11:14,07:10,01:05\n", 'over-target.csv');
        $service->import($this->period, Carbon::parse('2026-10-09'), $overTarget);

        $this->assertSame(0, (int) $review->fresh()->estimated_lost_seconds);
        $this->assertSame(0, (int) $review->fresh()->additional_overtime_seconds);
        $this->assertSame(900, (int) TrackabiDailyActivity::query()->sole()->activity_over_target_seconds);
    }

    public function test_absurd_raw_timer_cannot_change_desktop_activity_or_reviewed_loss(): void
    {
        $review = $this->review();
        $review->update(['hubstaff_total_seconds' => 120 * 3600]);
        $path = $this->csv("\"Aguilar, Dereck\",120:10,120:10,06:20,01:15\n");
        app(TrackabiInsightsCsvImportService::class)->import($this->period, Carbon::parse('2026-10-09'), $path);

        $this->assertSame(27300, (int) TrackabiDailyActivity::query()->sole()->measured_activity_seconds);
        $this->assertSame(1500, (int) $review->fresh()->estimated_lost_seconds);
        $review->fresh()->update(['status' => 'revisado_supervisor']);
        $this->assertSame(27300, $review->fresh()->payrollTrackedSeconds());
    }

    public function test_insights_replaces_historical_loss_instead_of_adding_to_it(): void
    {
        $review = $this->review();
        $review->update(['lost_time_source' => 'historical_estimate', 'estimated_lost_seconds' => 600,
            'unjustified_absence_seconds' => 600]);
        $path = $this->csv("\"Aguilar, Dereck\",11:14,11:14,06:20,01:15\n");

        app(TrackabiInsightsCsvImportService::class)->import($this->period, Carbon::parse('2026-10-09'), $path);

        $this->assertSame('trackabi_insights_activity', $review->fresh()->lost_time_source);
        $this->assertSame(1500, (int) $review->fresh()->estimated_lost_seconds);
        $this->assertSame(1500, $review->fresh()->finalEstimatedLostSeconds());
    }

    public function test_two_trackabi_projects_share_one_employee_day_activity_summary(): void
    {
        $review = $this->review();
        foreach ([75415, 80297] as $index => $projectId) {
            HubstaffTimeEntry::query()->create([
                'payroll_period_id' => $this->period->id, 'employee_id' => $this->employee->id,
                'hubstaff_member' => $this->employee->name, 'date' => '2026-10-09',
                'source_provider' => 'trackabi_api', 'trackabi_project_id' => $projectId,
                'direct_external_id' => (string) (100 + $index), 'total_seconds' => 18000,
            ]);
        }
        $path = $this->csv("\"Aguilar, Dereck\",10:00,10:00,06:20,01:15\n");

        app(TrackabiInsightsCsvImportService::class)->import($this->period, Carbon::parse('2026-10-09'), $path);

        $this->assertDatabaseCount('trackabi_daily_activities', 1);
        $this->assertSame(27300, (int) TrackabiDailyActivity::query()->sole()->measured_activity_seconds);
        $this->assertSame(1500, (int) $review->fresh()->estimated_lost_seconds);
    }

    public function test_paid_untracked_lunch_is_credited_once_against_measured_activity(): void
    {
        $this->employee->update(['paid_lunch_minutes_per_workday' => 60,
            'lunch_included_in_hubstaff_total' => false]);
        $review = $this->review(25200);
        $path = $this->csv("\"Aguilar, Dereck\",09:00,09:00,06:20,00:15\n");

        app(TrackabiInsightsCsvImportService::class)->import($this->period, Carbon::parse('2026-10-09'), $path);

        $fresh = $review->fresh();
        $this->assertSame(28800, (int) $fresh->expected_paid_seconds);
        $this->assertSame(25200, (int) $fresh->expected_hubstaff_seconds);
        $this->assertSame(3600, (int) $fresh->paid_time_not_tracked_seconds);
        $this->assertSame(1500, (int) $fresh->estimated_lost_seconds);
        $this->assertSame(27300, (int) $fresh->payable_seconds);

        $fresh->update(['status' => 'revisado_supervisor']);
        app(PayrollCalculationService::class)->recalculateDailyReview($fresh);
        $this->assertSame(27300, (int) $fresh->fresh()->payable_seconds);
    }

    public function test_daily_targets_use_paid_hours_without_unearned_lunch_credit(): void
    {
        $review = $this->review();
        $service = app(TrackabiInsightsCsvImportService::class);
        $cases = [
            [28800, 0, '07:30', 1800],
            [25200, 0, '06:40', 1200],
            [28800, 0, '07:50', 600],
            [32400, 0, '08:30', 1800],
            [39600, 3600, '10:35', 5100],
            [0, 0, '02:00', 0],
        ];
        foreach ($cases as [$target, $paidLunch, $measured, $shortfall]) {
            $review->update([
                'expected_hubstaff_seconds' => $target,
                'expected_paid_seconds' => $target + $paidLunch,
                'expected_ordinary_seconds' => max($target - 3600, 0),
                'paid_time_not_tracked_seconds' => $paidLunch,
                'scheduled_work_day' => $target > 0,
            ]);
            $path = $this->csv("\"Aguilar, Dereck\",12:00,12:00,{$measured},00:00\n");
            $plan = $service->preview($this->period, Carbon::parse('2026-10-09'), $path);
            $this->assertSame($shortfall, $plan['rows'][0]['shortfall_seconds']);
            $this->assertSame($target + $paidLunch, $plan['rows'][0]['target_seconds']);
        }
    }

    public function test_supervisor_justification_returns_credit_without_changing_activity_raw(): void
    {
        $review = $this->review();
        $path = $this->csv("\"Aguilar, Dereck\",10:22,10:22,05:33,01:32\n");
        app(TrackabiInsightsCsvImportService::class)->import($this->period, Carbon::parse('2026-10-09'), $path);

        $review->fresh()->update(['justified_absence_seconds' => 1500, 'status' => 'revisado_supervisor']);
        app(PayrollCalculationService::class)->recalculateEmployeePreservingManual($this->period, $this->employee);

        $this->assertSame(1800, $review->fresh()->finalEstimatedLostSeconds());
        $this->assertSame(27000, (int) $review->fresh()->payable_seconds);
        $this->assertSame(25500, (int) TrackabiDailyActivity::query()->sole()->measured_activity_seconds);
    }

    public function test_verified_manual_time_takes_precedence_over_activity_proposal(): void
    {
        $review = $this->review();
        $path = $this->csv("\"Aguilar, Dereck\",10:22,10:22,05:33,01:32\n");
        app(TrackabiInsightsCsvImportService::class)->import($this->period, Carbon::parse('2026-10-09'), $path);

        $review->fresh()->update(['verified_tracked_seconds' => 28800]);
        $this->assertSame(28800, $review->fresh()->payrollTrackedSeconds());
        $this->assertSame(25500, (int) TrackabiDailyActivity::query()->sole()->measured_activity_seconds);
    }

    public function test_activity_basis_survives_full_period_recalculation(): void
    {
        $review = $this->review();
        $path = $this->csv("\"Aguilar, Dereck\",10:22,10:22,05:33,01:32\n");
        app(TrackabiInsightsCsvImportService::class)->import($this->period, Carbon::parse('2026-10-09'), $path);

        app(PayrollCalculationService::class)->recalculatePeriodPreservingManual($this->period);

        $this->assertSame('trackabi_insights_activity', $review->fresh()->lost_time_source);
        $this->assertSame(3300, (int) $review->fresh()->estimated_lost_seconds);
        $this->assertSame(25500, $review->fresh()->payrollTrackedSeconds());
        $this->assertDatabaseCount('trackabi_daily_activities', 1);
    }

    public function test_pending_activity_shortfall_reconciles_after_schedule_change(): void
    {
        $review = $this->review();
        $path = $this->csv("\"Aguilar, Dereck\",10:22,10:22,05:33,01:32\n");
        app(TrackabiInsightsCsvImportService::class)->import($this->period, Carbon::parse('2026-10-09'), $path);
        $this->assertSame(3300, (int) $review->fresh()->estimated_lost_seconds);

        $this->employee->update(['daily_hours' => 7]);
        app(PayrollCalculationService::class)->recalculateEmployeePreservingManual($this->period, $this->employee);

        $this->assertSame(25200, (int) $review->fresh()->expected_hubstaff_seconds);
        $this->assertSame(0, (int) $review->fresh()->estimated_lost_seconds);
        $this->assertSame('trackabi_insights_activity', $review->fresh()->lost_time_source);
        $this->assertSame(25500, (int) TrackabiDailyActivity::query()->sole()->measured_activity_seconds);
    }

    public function test_employee_paid_without_tracking_is_not_discounted_by_zero_activity(): void
    {
        $this->employee->update(['paid_without_tracking' => true]);
        $review = $this->review();
        $path = $this->csv("\"Aguilar, Dereck\",00:00,00:00,00:00,00:00\n");
        app(TrackabiInsightsCsvImportService::class)->import($this->period, Carbon::parse('2026-10-09'), $path);

        $this->assertNull($review->fresh()->lost_time_source);
        $this->assertSame(0, (int) TrackabiDailyActivity::query()->sole()->measured_activity_seconds);
    }

    public function test_reviewed_day_keeps_supervisor_decision_and_creates_alert(): void
    {
        $review = $this->review(28800, 'revisado_supervisor');
        $review->update(['supervisor_comment' => 'Tiempo verificado', 'justified_absence_seconds' => 600]);
        $path = $this->csv("\"Aguilar, Dereck\",08:51,08:51,05:33,01:32\n");

        app(TrackabiInsightsCsvImportService::class)->import($this->period, Carbon::parse('2026-10-09'), $path);

        $this->assertSame('Tiempo verificado', $review->fresh()->supervisor_comment);
        $this->assertNull($review->fresh()->lost_time_source);
        $this->assertSame(600, (int) $review->fresh()->justified_absence_seconds);
        $this->assertDatabaseHas('tracking_import_alerts', ['code' => 'TRACKABI_ACTIVITY_DATA_AFTER_REVIEW']);
    }

    public function test_corrected_csv_updates_pending_day_but_not_reviewed_day(): void
    {
        $review = $this->review();
        $service = app(TrackabiInsightsCsvImportService::class);
        $first = $this->csv("\"Aguilar, Dereck\",10:22,10:22,05:33,01:32\n");
        $service->import($this->period, Carbon::parse('2026-10-09'), $first);
        $corrected = $this->csv("\"Aguilar, Dereck\",10:22,10:22,06:40,01:00\n", 'corrected.csv');
        $service->import($this->period, Carbon::parse('2026-10-09'), $corrected);

        $this->assertSame(1200, (int) $review->fresh()->estimated_lost_seconds);
        $this->assertSame(27600, (int) TrackabiDailyActivity::query()->sole()->measured_activity_seconds);
        $review->fresh()->update(['status' => 'revisado_supervisor', 'supervisor_comment' => 'Validado']);

        $later = $this->csv("\"Aguilar, Dereck\",10:22,10:22,05:00,01:00\n", 'later.csv');
        $service->import($this->period, Carbon::parse('2026-10-09'), $later);

        $this->assertSame(1200, (int) $review->fresh()->estimated_lost_seconds);
        $this->assertSame('Validado', $review->fresh()->supervisor_comment);
        $this->assertSame(21600, (int) TrackabiDailyActivity::query()->sole()->measured_activity_seconds);
        $this->assertDatabaseHas('tracking_import_alerts', ['code' => 'TRACKABI_ACTIVITY_DATA_AFTER_REVIEW']);
        $this->assertDatabaseCount('trackabi_activity_import_batches', 3);
    }

    public function test_supervisor_explicitly_reviews_late_activity_and_resolves_alert(): void
    {
        $user = User::query()->create([
            'name' => 'Supervisor', 'email' => 'supervisor-csv@example.com',
            'password' => 'password', 'profile' => 'supervisor', 'active' => true,
        ]);
        $this->actingAs($user);
        $review = $this->review(28800, 'revisado_supervisor');
        $first = $this->csv("\"Aguilar, Dereck\",10:22,10:22,05:33,01:32\n");
        $service = app(TrackabiInsightsCsvImportService::class);
        $service->import($this->period, Carbon::parse('2026-10-09'), $first);
        $this->assertFalse((bool) TrackingImportAlert::query()->sole()->resolved);

        $service->reviewNewActivity($review, 3600, 1800, 'Justificó 30 minutos');

        $fresh = $review->fresh();
        $this->assertSame('supervisor_adjustment', $fresh->lost_time_source);
        $this->assertSame(3300, (int) $fresh->estimated_lost_seconds);
        $this->assertSame(1800, $fresh->finalEstimatedLostSeconds());
        $this->assertSame(27000, (int) $fresh->payable_seconds);
        $this->assertSame($user->id, $fresh->reviewed_by);
        $this->assertTrue((bool) TrackingImportAlert::query()->where('code', 'TRACKABI_ACTIVITY_DATA_AFTER_REVIEW')->sole()->resolved);
    }

    public function test_closed_period_and_bad_headers_do_not_write(): void
    {
        $this->review();
        $path = $this->csv("\"Aguilar, Dereck\",08:51,08:51,05:33,01:32\n");
        $this->period->update(['status' => 'cerrado']);
        try {
            app(TrackabiInsightsCsvImportService::class)->import($this->period, Carbon::parse('2026-10-09'), $path);
            $this->fail('Expected closed period rejection');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Cannot import Trackabi activity into closed payroll period.', $exception->getMessage());
        }
        $this->assertDatabaseCount('trackabi_daily_activities', 0);
        $this->period->update(['status' => 'borrador']);
        Storage::disk('local')->put('bad.csv', "Member Name,Productive activity time\nDereck Aguilar,07:00\n");
        $this->expectExceptionMessage('Falta encabezado de actividad unproductive');
        app(TrackabiInsightsCsvImportService::class)->preview($this->period, Carbon::parse('2026-10-09'), Storage::disk('local')->path('bad.csv'));
    }

    public function test_closed_period_hides_both_filament_import_actions(): void
    {
        $user = User::query()->create([
            'name' => 'RRHH closed', 'email' => 'rrhh-closed@example.com',
            'password' => 'password', 'profile' => 'rrhh', 'active' => true,
        ]);
        $this->period->update(['status' => 'cerrado']);
        $this->actingAs($user);

        Livewire::test(EditPayrollPeriod::class, ['record' => $this->period->id])
            ->assertActionHidden('importTrackabi')
            ->assertActionHidden('previewTrackabiInsights')
            ->assertActionHidden('confirmTrackabiInsights');
    }

    public function test_unmatched_employee_is_alerted_and_not_assigned_by_fuzzy_name(): void
    {
        $this->review();
        $path = $this->csv("\"Aguilar, Derek\",08:51,08:51,05:33,01:32\n");
        $plan = app(TrackabiInsightsCsvImportService::class)->preview($this->period, Carbon::parse('2026-10-09'), $path);
        $this->assertCount(1, $plan['employees_unmatched']);
        try {
            app(TrackabiInsightsCsvImportService::class)->import($this->period, Carbon::parse('2026-10-09'), $path);
            $this->fail('La confirmación debió bloquearse.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('empleados sin mapear', $exception->getMessage());
        }
        $this->assertDatabaseCount('trackabi_daily_activities', 0);
        $this->assertDatabaseCount('tracking_import_alerts', 0);

        $this->artisan('trackabi:map-insights-member', [
            'name' => 'Aguilar, Derek', '--employee' => $this->employee->id,
        ])->assertSuccessful();
        app(TrackabiInsightsCsvImportService::class)->import($this->period, Carbon::parse('2026-10-09'), $path);

        $this->assertDatabaseCount('trackabi_daily_activities', 1);
        $this->assertDatabaseCount('trackabi_activity_import_batches', 1);
        $this->assertSame('Aguilar, Derek', $this->employee->fresh()->trackabi_name);
    }

    public function test_malformed_activity_and_wrong_report_date_are_rejected(): void
    {
        $this->review();
        $path = $this->csv("\"Aguilar, Dereck\",08:51,08:51,120:10,01:32\n");
        $plan = app(TrackabiInsightsCsvImportService::class)->preview($this->period, Carbon::parse('2026-10-09'), $path);
        $this->assertCount(1, $plan['errors']);
        $this->assertSame(0, $plan['employees_matched']);

        Storage::disk('local')->put('with-date.csv', "Member Name,Date,Productive activity time,Unproductive activity time\n\"Aguilar, Dereck\",2026-10-08,07:00,00:15\n");
        $wrongDate = app(TrackabiInsightsCsvImportService::class)->preview($this->period, Carbon::parse('2026-10-09'), Storage::disk('local')->path('with-date.csv'));
        $this->assertCount(1, $wrongDate['errors']);
        $this->assertDatabaseCount('trackabi_daily_activities', 0);
    }
}
