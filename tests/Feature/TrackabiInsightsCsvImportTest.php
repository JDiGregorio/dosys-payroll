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
use App\Models\TrackingImportAlert;
use App\Models\User;
use App\Services\PayrollCalculationService;
use Illuminate\Validation\ValidationException;
use App\Services\Trackabi\TrackabiActivityCoverageService;
use App\Services\Trackabi\TrackabiPaidActivityService;
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
            'trackabi_direct.insights_paid_break_minutes' => 0]);
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

    public function test_calendar_leaves_short_gap_visible_without_lunch_credit_or_idle(): void
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
            ->assertSee('7:22 h')
            ->assertSee('-0:38 h')
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

    public function test_shortfall_at_or_below_lunch_break_is_left_for_supervisor_review(): void
    {
        config(['trackabi_direct.insights_paid_lunch_minutes' => 60,
            'trackabi_direct.insights_paid_break_minutes' => 15]);
        $review = $this->review();
        $path = $this->csv("\"Aguilar, Dereck\",09:00,09:00,07:00,00:22\n");
        app(TrackabiInsightsCsvImportService::class)->import($this->period, Carbon::parse('2026-10-09'), $path);
        $this->assertSame(26520, (int) $review->fresh()->payable_seconds);
        $this->assertSame(2280, (int) $review->fresh()->estimated_lost_seconds);
        $this->assertSame(0, (int) $review->fresh()->paid_time_not_tracked_seconds);

        $exact = $this->csv("\"Aguilar, Dereck\",09:00,09:00,06:00,00:45\n", 'exact-boundary.csv');
        app(TrackabiInsightsCsvImportService::class)->import($this->period, Carbon::parse('2026-10-09'), $exact);
        $this->assertSame(24300, (int) $review->fresh()->payable_seconds);
        $this->assertSame(4500, (int) $review->fresh()->estimated_lost_seconds);
        $this->assertSame(0, (int) $review->fresh()->paid_time_not_tracked_seconds);

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

    public function test_profile_lunch_does_not_credit_shortfall_below_threshold_twice(): void
    {
        config(['trackabi_direct.insights_paid_lunch_minutes' => 60,
            'trackabi_direct.insights_paid_break_minutes' => 15]);
        $this->employee->update(['paid_lunch_minutes_per_workday' => 60,
            'paid_break_minutes_per_workday' => 15]);
        $review = $this->review();
        $path = $this->csv("\"Aguilar, Dereck\",09:00,09:00,07:00,00:00\n");
        app(TrackabiInsightsCsvImportService::class)->import($this->period, Carbon::parse('2026-10-09'), $path);

        $this->assertSame(0, (int) $review->fresh()->paid_time_not_tracked_seconds);
        $this->assertSame(28800, (int) $review->fresh()->expected_hubstaff_seconds);
        $this->assertSame(25200, (int) $review->fresh()->payable_seconds);
    }

    public function test_rotating_day_retains_one_hour_lunch_credit_below_general_threshold(): void
    {
        config(['trackabi_direct.insights_paid_lunch_minutes' => 60,
            'trackabi_direct.insights_paid_break_minutes' => 15]);
        $schedule = ScheduleType::query()->create(['name' => 'Rotativa', 'code' => 'rotativa', 'active' => true]);
        $this->employee->update(['schedule_type_id' => $schedule->id, 'daily_hours' => 12]);
        $review = $this->review(43200);
        $service = app(TrackabiPaidActivityService::class);

        $this->assertSame(3600, $service->allowanceSeconds($review, 39600));
        $this->assertSame(39600, $service->trackableTargetSeconds($review, 39600));
        $this->assertSame(4500, $service->allowanceSeconds($review, 37800));
    }

    public function test_off_worked_requires_confirmed_hours_and_pays_full_extra_day(): void
    {
        $review = $this->review();
        $review->update(['hubstaff_total_seconds' => 0, 'expected_ordinary_seconds' => 28800,
            'expected_hubstaff_seconds' => 28800, 'expected_paid_seconds' => 28800]);
        $this->expectException(ValidationException::class);
        DailyTimeReviewResource::secondsFromHourStates([
            'off_worked' => true, 'paid_day_off' => true, 'off_worked_verified_hours' => '0:00',
        ], $review->fresh());
    }

    public function test_off_worked_with_partial_confirmed_hours_still_pays_full_extra_day(): void
    {
        $review = $this->review();
        $review->update(['hubstaff_total_seconds' => 0, 'expected_ordinary_seconds' => 28800,
            'expected_hubstaff_seconds' => 28800, 'expected_paid_seconds' => 28800]);
        $data = DailyTimeReviewResource::secondsFromHourStates([
            'off_worked' => true, 'off_worked_verified_hours' => '4:00',
            'absence_justified' => false,
        ], $review->fresh());
        $this->assertTrue($data['paid_day_off']);
        $this->assertSame(14400, $data['off_worked_verified_seconds']);
        $review->update($data);
        app(PayrollCalculationService::class)->recalculateEmployeePreservingManual($this->period, $this->employee);

        $result = PayrollResult::query()->where('payroll_period_id', $this->period->id)->firstOrFail();
        $this->assertSame(28800, (int) $review->fresh()->payable_seconds);
        $this->assertEqualsWithDelta((float) $result->daily_rate, (float) $result->off_worked_extra_amount, 0.01);
        $this->assertSame(0, (int) $review->fresh()->hubstaff_total_seconds);
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

    public function test_direct_no_record_off_worked_form_requires_hours_then_pays_double_day(): void
    {
        $user = User::query()->create([
            'name' => 'RRHH', 'email' => 'rrhh-off-worked@example.com',
            'password' => 'password', 'profile' => 'rrhh', 'active' => true,
        ]);
        $review = $this->review();
        $review->update(['hubstaff_total_seconds' => 0]);
        $this->actingAs($user);

        Livewire::test(EditDailyTimeReview::class, ['record' => $review->id])
            ->set('data.off_worked', true)
            ->call('save')
            ->assertHasErrors(['off_worked_verified_hours']);

        Livewire::test(EditDailyTimeReview::class, ['record' => $review->id])
            ->set('data.off_worked', true)
            ->set('data.off_worked_verified_hours', '4:00')
            ->call('save')
            ->assertHasNoErrors();

        $result = PayrollResult::query()->where('payroll_period_id', $this->period->id)->firstOrFail();
        $this->assertTrue($review->fresh()->paid_day_off);
        $this->assertSame(14400, (int) $review->fresh()->off_worked_verified_seconds);
        $this->assertEqualsWithDelta((float) $result->daily_rate, (float) $result->off_worked_extra_amount, 0.01);
    }

    public function test_rotating_unscheduled_off_worked_never_pays_more_than_double_day(): void
    {
        $schedule = ScheduleType::query()->create(['name' => 'Rotativa', 'code' => 'rotativa', 'active' => true]);
        $this->employee->update(['schedule_type_id' => $schedule->id, 'daily_hours' => 12,
            'salary_calculation_method' => 'scheduled_shift_prorated']);
        $review = $this->review(0);
        $review->update(['hubstaff_total_seconds' => 0, 'scheduled_work_day' => false,
            'paid_day_off' => true, 'off_worked' => true, 'off_worked_verified_seconds' => 14400]);

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
