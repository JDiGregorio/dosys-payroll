<?php

namespace Tests\Feature;

use App\Filament\Resources\DailyTimeReviews\DailyTimeReviewResource;
use App\Models\Campaign;
use App\Models\DailyTimeReview;
use App\Models\Employee;
use App\Models\HubstaffTimeEntry;
use App\Models\PayrollPeriod;
use App\Models\PayrollResult;
use App\Models\ScheduleType;
use App\Models\TrackabiProjectMapping;
use App\Models\TrackabiDailyActivity;
use App\Models\TrackabiDayExclusion;
use App\Models\TrackingImportAlert;
use App\Models\User;
use App\Models\WorkScheduleTemplate;
use App\Services\Trackabi\TrackabiDirectImportService;
use App\Services\Trackabi\TrackabiCoverageReportService;
use App\Services\PayrollCalculationService;
use App\Services\ScheduleExpectationService;
use App\Services\Trackabi\TrackabiDirectTimeEntryValidator;
use App\Services\Trackabi\TrackabiDayExclusionService;
use App\Services\Trackabi\TrackabiInsightsCsvImportService;
use App\Support\TimeTrackingSource;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TrackabiDirectImportTest extends TestCase
{
    use RefreshDatabase;

    private PayrollPeriod $period;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'trackabi.provider' => 'direct',
            'trackabi.mindcloud_enabled' => false,
            'trackabi_direct.enabled' => true,
            'trackabi_direct.api_key' => 'direct-test-key',
            'trackabi_direct.base_url' => 'https://api.trackabi.test',
            'trackabi_direct.cutover_date' => '2026-09-26',
            'trackabi_direct.import_page_size' => 2,
            'trackabi_direct.import_max_pages' => 5,
        ]);
        Http::preventStrayRequests();
        $campaign = Campaign::query()->create(['name' => 'PALMETTO']);
        $this->employee = Employee::query()->create([
            'name' => 'Rene Refsman', 'email' => 'rene@example.com', 'campaign_id' => $campaign->id,
            'active' => true, 'daily_hours' => 8, 'hourly_rate' => 10,
        ]);
        TrackabiProjectMapping::query()->create([
            'trackabi_project_id' => 75415, 'trackabi_project_name' => 'Palmetto A',
            'campaign_id' => $campaign->id, 'active' => true,
        ]);
        $this->period = PayrollPeriod::query()->create([
            'name' => '26 septiembre a 10 octubre', 'starts_at' => '2026-09-26',
            'ends_at' => '2026-10-10', 'status' => 'borrador',
        ]);
    }

    private function entry(int $id, string $date = '2026-09-26', string $time = '07:59:30'): array
    {
        return [
            'id' => $id,
            'date_logged' => $date,
            'logged_time' => $time,
            'billable' => $time,
            'member' => ['id' => 70, 'email' => 'rene@example.com', 'first_name' => 'Rene', 'last_name' => 'Refsman'],
            'project' => ['id' => 75415, 'name' => 'Palmetto A'],
            'time_type' => 'Normal',
        ];
    }

    public function test_excluded_day_stays_without_active_time_after_both_reimports(): void
    {
        $this->period->update(['starts_at' => '2026-10-22', 'ends_at' => '2026-10-22']);
        $this->employee->update(['trackabi_name' => 'Refsman, Rene']);
        config(['trackabi_direct.import_page_size' => 20]);
        Http::fake(fn (Request $request) => Http::response([$this->entry(101, '2026-10-22', '08:00:00')]));
        $direct = app(TrackabiDirectImportService::class);
        $direct->import($this->period);
        Storage::fake('local');
        Storage::disk('local')->put('day.csv', "Member Name,Total logged,Total billable,Productive activity time,Unproductive activity time\n\"Refsman, Rene\",08:00,08:00,06:45,00:15\n");
        $path = Storage::disk('local')->path('day.csv');
        $insights = app(TrackabiInsightsCsvImportService::class);
        $insights->import($this->period, Carbon::parse('2026-10-22'), $path);
        $this->assertDatabaseHas('hubstaff_time_entries', ['direct_external_id' => '101', 'active' => true]);
        $this->assertDatabaseCount('trackabi_daily_activities', 1);

        app(TrackabiDayExclusionService::class)->apply(
            $this->period, $this->employee, Carbon::parse('2026-10-22'), 'Descanso confirmado',
        );
        $this->assertDatabaseHas('hubstaff_time_entries', ['direct_external_id' => '101', 'active' => false]);
        $this->assertDatabaseCount('trackabi_daily_activities', 0);
        $this->assertCount(1, TrackabiDayExclusion::query()->sole()->archived_insights);
        $review = DailyTimeReview::query()->where('payroll_period_id', $this->period->id)->sole();
        $this->assertSame(0, (int) $review->hubstaff_total_seconds);
        $this->assertSame(0, (int) $review->payable_seconds);
        $user = User::query()->create([
            'name' => 'RRHH', 'email' => 'rrhh-exclusion@example.com',
            'password' => 'password', 'profile' => 'rrhh', 'active' => true,
        ]);
        $this->actingAs($user)->get("/admin/daily-review-calendar?period_id={$this->period->id}&employee_id={$this->employee->id}")
            ->assertOk()->assertSee('Sin registro');

        $this->assertSame(1, $direct->preview($this->period)['excluded_day_entries']);
        $direct->import($this->period);
        $this->assertCount(1, $insights->preview($this->period, Carbon::parse('2026-10-22'), $path)['ignored_rows']);
        $insights->import($this->period, Carbon::parse('2026-10-22'), $path);
        $this->assertDatabaseHas('hubstaff_time_entries', ['direct_external_id' => '101', 'active' => false]);
        $this->assertDatabaseCount('trackabi_daily_activities', 0);
    }

    public function test_paid_time_projects_are_imported_without_campaign_mapping_or_work_time_credit(): void
    {
        $this->period->update(['starts_at' => '2026-10-21', 'ends_at' => '2026-10-21']);
        config(['trackabi_direct.import_page_size' => 20]);
        Http::fake(fn (Request $request) => Http::response([
            $this->entry(101, '2026-10-21', '08:00:00'),
            [...$this->entry(102, '2026-10-21', '00:10:00'),
                'project' => ['id' => 81118, 'name' => 'Break 10']],
            [...$this->entry(103, '2026-10-21', '01:00:00'),
                'project' => ['id' => 81120, 'name' => 'Lunch']],
        ]));

        $plan = app(TrackabiDirectImportService::class)->preview($this->period);
        $this->assertSame([], $plan['unmapped_projects']);
        $this->assertSame(['paid_break_10' => 1, 'paid_lunch' => 1], $plan['special_project_coverage']);
        $this->artisan("trackabi:import --period={$this->period->id} --commit")->assertSuccessful();
        $this->assertDatabaseCount('hubstaff_time_entries', 3);
        $review = DailyTimeReview::query()->where('employee_id', $this->employee->id)
            ->whereDate('date', '2026-10-21')->sole();
        $this->assertSame(8 * 3600, (int) $review->hubstaff_total_seconds);
        $this->assertDatabaseMissing('tracking_import_alerts', ['code' => 'unmapped_project']);
        $this->artisan("trackabi:import --period={$this->period->id} --commit")->assertSuccessful();
        $this->assertDatabaseCount('hubstaff_time_entries', 3);
    }

    public function test_logged_time_validation_enforces_24_hour_boundary_without_inference(): void
    {
        $validator = app(TrackabiDirectTimeEntryValidator::class);
        $this->assertSame(['seconds' => 86400, 'issue' => null], $validator->loggedTime('24:00:00'));
        $this->assertSame('exceeds_daily_limit', $validator->loggedTime('24:00:01')['issue']);
        $this->assertSame('exceeds_daily_limit', $validator->loggedTime('999999999999:00:00')['issue']);
        $this->assertSame('malformed_duration', $validator->loggedTime('8:4:00')['issue']);
        $this->assertSame('malformed_duration', $validator->loggedTime(null)['issue']);
        $this->assertSame('MISSING_LOGGED_TIME', $validator->reasonCode(null, 'malformed_duration'));
        $this->assertSame('INVALID_LOGGED_TIME', $validator->reasonCode('oops', 'malformed_duration'));
        $this->assertSame('EXCESSIVE_LOGGED_TIME', $validator->reasonCode('36:32:00', 'exceeds_daily_limit'));
    }

    public function test_dry_run_uses_direct_api_without_mindcloud_or_database_writes(): void
    {
        Http::fake(fn (Request $request) => Http::response([$this->entry(101)]));

        $this->artisan("trackabi:import --period={$this->period->id} --dry-run")
            ->expectsOutputToContain('Trackabi API directa: vista previa')
            ->assertSuccessful();

        $this->assertDatabaseCount('hubstaff_time_entries', 0);
        $this->assertDatabaseCount('hubstaff_imports', 0);
        $this->assertDatabaseCount('tracking_import_alerts', 0);
        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://api.trackabi.test/api/v1/time-entries')
            && $request->hasHeader('Authorization', 'Bearer direct-test-key')
            && $request['start_date'] === '2026-09-26'
            && $request['end_date'] === '2026-10-10'
            && $request['page'] === 1
            && $request['page_size'] === 2);
        Http::assertSentCount(1);
    }

    public function test_suspicious_logged_time_is_alerted_but_never_imported_as_payroll_time(): void
    {
        config(['trackabi_direct.import_page_size' => 20]);
        Http::fake(fn (Request $request) => Http::response([
            $this->entry(101, '2026-09-26', '120:10:00'),
            $this->entry(102, '2026-09-27', '07:59:30'),
        ]));

        $this->artisan("trackabi:import --period={$this->period->id} --dry-run")
            ->expectsOutputToContain('Logged time sospechoso (omitido): 1')->assertSuccessful();
        $this->assertDatabaseCount('hubstaff_time_entries', 0);
        $this->assertDatabaseCount('tracking_import_alerts', 0);

        $this->artisan("trackabi:import --period={$this->period->id} --commit")->assertSuccessful();
        $this->assertDatabaseCount('hubstaff_time_entries', 1);
        $this->assertDatabaseMissing('hubstaff_time_entries', ['direct_external_id' => '101']);
        $this->assertDatabaseHas('hubstaff_time_entries', [
            'direct_external_id' => '102', 'raw_logged_time' => '07:59:30', 'total_seconds' => 28770,
        ]);
        $alert = TrackingImportAlert::query()->where('code', 'TRACKABI_SUSPICIOUS_LOGGED_TIME')->sole();
        $this->assertSame('120:10:00', $alert->context['raw_logged_time']);
        $this->assertSame('101', $alert->external_id);
        $this->assertSame('exceeds_daily_limit', $alert->context['reason']);
        $this->assertSame('EXCESSIVE_LOGGED_TIME', $alert->context['reason_code']);

        $this->artisan("trackabi:import --period={$this->period->id} --commit")->assertSuccessful();
        $this->assertSame(1, TrackingImportAlert::query()->where('code', 'TRACKABI_SUSPICIOUS_LOGGED_TIME')->count());
    }

    public function test_malformed_duration_is_quarantined_and_corrected_entry_resolves_alert(): void
    {
        $logged = 'oops';
        Http::fake(function (Request $request) use (&$logged) {
            return Http::response([[
                ...$this->entry(101, '2026-09-26', $logged), 'billable' => '08:00:00',
            ]]);
        });
        $this->artisan("trackabi:import --period={$this->period->id} --commit")->assertSuccessful();
        $this->assertDatabaseCount('hubstaff_time_entries', 0);
        $this->assertSame('malformed_duration', TrackingImportAlert::query()->sole()->context['reason']);

        $logged = '08:00:00';
        $this->artisan("trackabi:import --period={$this->period->id} --commit")->assertSuccessful();
        $this->assertDatabaseHas('hubstaff_time_entries', ['direct_external_id' => '101', 'raw_logged_time' => '08:00:00']);
        $this->assertDatabaseHas('tracking_import_alerts', ['external_id' => '101', 'resolved' => true]);
    }

    public function test_audit_reports_anomalies_and_possible_duplicates_without_writes(): void
    {
        config(['trackabi_direct.import_page_size' => 20]);
        $missingEmployee = [...$this->entry(103), 'member' => null];
        $missingProject = [...$this->entry(104), 'project' => null];
        $inconsistent = [...$this->entry(105), 'logged_time' => '08:00:00', 'start_time' => '08:00:00', 'end_time' => '09:00:00'];
        $duplicate = [...$inconsistent, 'id' => 106];
        Http::fake(fn (Request $request) => Http::response([
            $this->entry(101, '2026-09-26', '120:10:00'),
            $this->entry(102, '2026-09-26', 'bad'),
            $missingEmployee, $missingProject, $inconsistent, $duplicate,
        ]));

        $report = app(TrackabiDirectImportService::class)->audit(
            Carbon::parse('2026-09-26'), Carbon::parse('2026-09-26'),
        );
        $this->assertSame(6, $report['entries_scanned']);
        $issues = collect($report['findings'])->flatMap(fn ($row) => $row['issues'])->all();
        foreach (['exceeds_daily_limit', 'malformed_duration', 'missing_employee', 'missing_project',
            'logged_exceeds_start_end', 'possible_duplicate_content'] as $expected) {
            $this->assertContains($expected, $issues);
        }
        $this->assertDatabaseCount('hubstaff_time_entries', 0);
        $this->assertDatabaseCount('tracking_import_alerts', 0);
    }

    public function test_inspect_time_entry_uses_official_detail_endpoint_and_sanitizes_response(): void
    {
        Http::fake(fn (Request $request) => Http::response([
            ...$this->entry(101), 'description' => 'Private note',
        ]));

        $this->artisan('trackabi:inspect-time-entry 101')
            ->expectsOutputToContain('"logged_time": "07:59:30"')->assertSuccessful();
        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://api.trackabi.test/api/v1/time-entry/101')
            && $request->hasHeader('Authorization', 'Bearer direct-test-key'));
        $this->assertDatabaseCount('hubstaff_time_entries', 0);
    }

    public function test_commit_is_idempotent_and_preserves_reviewed_days(): void
    {
        $logged = '07:59:30';
        Http::fake(function (Request $request) use (&$logged) {
            return Http::response([$this->entry(101, '2026-09-26', $logged)]);
        });

        $this->artisan("trackabi:import --period={$this->period->id} --commit")->assertSuccessful();
        $this->assertDatabaseCount('hubstaff_time_entries', 1);
        $this->assertDatabaseCount('hubstaff_imports', 1);
        $this->assertSame(0, app(TrackabiDirectImportService::class)->preview($this->period)['would_update']);
        $entry = HubstaffTimeEntry::query()->sole();
        $this->assertSame('trackabi_api', $entry->source_provider);
        $this->assertSame('101', $entry->external_id);
        $this->assertSame(28770, (int) $entry->total_seconds);
        $this->assertNull($entry->productive_seconds);
        $this->assertNull($entry->activity_score);
        $this->assertSame('Trackabi API', TimeTrackingSource::labelForEmployee($this->employee, $this->period->id, '2026-09-26'));

        $this->artisan("trackabi:import --period={$this->period->id} --commit")->assertSuccessful();
        $this->assertDatabaseCount('hubstaff_time_entries', 1);

        $review = DailyTimeReview::query()->where('employee_id', $this->employee->id)
            ->whereDate('date', '2026-09-26')->sole();
        $review->update(['status' => 'revisado_supervisor', 'supervisor_comment' => 'Justificado por supervisor',
            'justified_absence_seconds' => 600, 'supervisor_adjustment_seconds' => 120]);

        $logged = '08:10:00';
        $this->artisan("trackabi:import --period={$this->period->id} --commit")->assertSuccessful();
        $this->assertSame(28770, (int) $entry->fresh()->total_seconds);
        $this->assertSame('Justificado por supervisor', $review->fresh()->supervisor_comment);
        $this->assertSame(600, (int) $review->fresh()->justified_absence_seconds);
        $this->assertSame(120, (int) $review->fresh()->supervisor_adjustment_seconds);
        $this->assertSame('revisado_supervisor', $review->fresh()->status);
        $this->assertDatabaseCount('hubstaff_time_entries', 1);
        $this->assertDatabaseCount('hubstaff_imports', 1);
        $this->assertDatabaseHas('tracking_import_alerts', ['code' => 'TRACKABI_DIRECT_DATA_AFTER_REVIEW', 'external_id' => '101']);
        $alert = TrackingImportAlert::query()->where('code', 'TRACKABI_DIRECT_DATA_AFTER_REVIEW')->sole();
        $this->assertSame('07:59:30', $alert->context['previous_raw_logged_time']);
        $this->assertSame('08:10:00', $alert->context['incoming_raw_logged_time']);
        Http::assertSent(fn (Request $request): bool => ! str_contains($request->url(), 'mindcloud'));
    }

    public function test_changed_direct_entry_updates_pending_review_without_losing_manual_fields(): void
    {
        $logged = '07:59:30';
        Http::fake(function (Request $request) use (&$logged) {
            return Http::response([$this->entry(101, '2026-09-26', $logged)]);
        });

        $first = app(TrackabiDirectImportService::class)->import($this->period);
        $this->assertSame(1, $first['written']);
        $review = DailyTimeReview::query()->where('employee_id', $this->employee->id)
            ->whereDate('date', '2026-09-26')->sole();
        $review->update(['supervisor_comment' => 'Nota manual']);
        $originalImportId = HubstaffTimeEntry::query()->sole()->hubstaff_import_id;

        $second = app(TrackabiDirectImportService::class)->import($this->period);
        $this->assertSame(0, $second['written']);
        $this->assertSame(0, $second['would_insert']);
        $this->assertSame(0, $second['would_update']);
        $this->assertDatabaseCount('hubstaff_imports', 1);
        $this->assertSame($originalImportId, HubstaffTimeEntry::query()->sole()->hubstaff_import_id);

        $logged = '07:45:00';
        $third = app(TrackabiDirectImportService::class)->import($this->period);
        $this->assertSame(1, $third['written']);
        $this->assertSame(1, $third['would_update']);
        $this->assertDatabaseCount('hubstaff_time_entries', 1);
        $this->assertSame(27900, (int) HubstaffTimeEntry::query()->sole()->total_seconds);
        $this->assertSame('Nota manual', $review->fresh()->supervisor_comment);
        $this->assertSame('pendiente', $review->fresh()->status);
    }

    public function test_closed_and_pre_cutover_periods_fail_before_http_or_writes(): void
    {
        Http::fake();
        $this->period->update(['status' => 'cerrado']);
        $this->artisan("trackabi:import --period={$this->period->id} --commit")->assertFailed();
        $this->period->update(['status' => 'borrador', 'starts_at' => '2026-09-25']);
        $this->artisan("trackabi:import --period={$this->period->id} --commit")->assertFailed();

        Http::assertNothingSent();
        $this->assertDatabaseCount('hubstaff_time_entries', 0);
    }

    public function test_unmapped_project_never_becomes_payroll_time(): void
    {
        Http::fake(fn (Request $request) => Http::response([
            [...$this->entry(101), 'project' => ['id' => 99999, 'name' => 'Unknown']],
        ]));

        $this->artisan("trackabi:import --period={$this->period->id} --dry-run")
            ->expectsOutputToContain('Proyectos sin mapeo: 1')->assertSuccessful();
        $this->assertDatabaseCount('hubstaff_time_entries', 0);
    }

    public function test_missing_project_uses_unique_employee_campaign_and_remains_auditable(): void
    {
        $entry = [...$this->entry(101), 'project' => null];
        Http::fake(fn (Request $request) => Http::response([$entry]));

        $this->artisan("trackabi:import --period={$this->period->id} --commit")->assertSuccessful();
        $this->artisan("trackabi:import --period={$this->period->id} --commit")->assertSuccessful();

        $alert = TrackingImportAlert::query()->where('code', 'TRACKABI_PROJECT_MISSING_ASSIGNED_BY_EMPLOYEE')->sole();
        $this->assertSame('101', $alert->external_id);
        $this->assertSame($this->employee->id, $alert->context['employee_id']);
        $this->assertNull($alert->context['project_id']);
        $this->assertDatabaseHas('hubstaff_time_entries', [
            'employee_id' => $this->employee->id, 'direct_external_id' => '101',
            'source_provider' => 'trackabi_api', 'trackabi_project_id' => null,
        ]);
        $this->assertDatabaseCount('hubstaff_time_entries', 1);
        $this->assertDatabaseCount('hubstaff_imports', 1);
    }

    public function test_projectless_and_project_entries_for_same_employee_day_keep_both_ids_without_double_import(): void
    {
        config(['trackabi_direct.import_page_size' => 20]);
        Http::fake(fn (Request $request) => Http::response([
            $this->entry(101, '2026-09-26', '06:00:00'),
            [...$this->entry(102, '2026-09-26', '02:00:00'), 'project' => null],
        ]));

        $service = app(TrackabiDirectImportService::class);
        $first = $service->import($this->period);
        $second = $service->import($this->period);

        $this->assertSame(2, $first['written']);
        $this->assertSame(0, $second['written']);
        $this->assertDatabaseCount('hubstaff_time_entries', 2);
        $this->assertSame(28800, (int) HubstaffTimeEntry::query()->sum('total_seconds'));
        $this->assertDatabaseCount('hubstaff_imports', 1);
    }

    public function test_mapped_trackabi_project_does_not_override_unique_employee_campaign(): void
    {
        config(['trackabi_direct.import_page_size' => 20]);
        $rrd = Campaign::query()->create(['name' => 'RRD FINANCIAL']);
        TrackabiProjectMapping::query()->create([
            'trackabi_project_id' => 75417, 'trackabi_project_name' => 'RDD FINANCIAL',
            'campaign_id' => $rrd->id, 'active' => true,
        ]);
        Http::fake(fn (Request $request) => Http::response([
            [...$this->entry(101), 'project' => ['id' => 75417, 'name' => 'RDD FINANCIAL']],
        ]));

        $plan = app(TrackabiDirectImportService::class)->preview($this->period);

        $this->assertSame($this->employee->id, $plan['entries'][0]['employee_id']);
        $this->assertSame($this->employee->campaign_id, $plan['entries'][0]['campaign_id']);
        $this->assertSame(75417, $plan['entries'][0]['project_id']);
    }

    public function test_projectless_entry_can_match_unique_name_when_email_differs(): void
    {
        $this->employee->update(['trackabi_name' => 'Rene Refsman']);
        Http::fake(fn (Request $request) => Http::response([
            [...$this->entry(101), 'member' => [
                'id' => 70, 'email' => 'different@example.com',
                'first_name' => 'Rene', 'last_name' => 'Refsman',
            ], 'project' => null],
        ]));

        $plan = app(TrackabiDirectImportService::class)->preview($this->period);

        $this->assertSame(1, $plan['employees_matched']);
        $this->assertSame($this->employee->id, $plan['entries'][0]['employee_id']);
        $this->assertSame('different@example.com', $plan['entries'][0]['member_email']);
    }

    public function test_projectless_entry_with_ambiguous_name_is_not_imported(): void
    {
        $this->employee->update(['trackabi_name' => 'Rene Refsman']);
        Employee::query()->create([
            'name' => 'Rene Refsman', 'email' => 'other@example.com',
            'campaign_id' => $this->employee->campaign_id, 'active' => true,
            'trackabi_name' => 'Rene Refsman',
        ]);
        Http::fake(fn (Request $request) => Http::response([
            [...$this->entry(101), 'member' => [
                'id' => 70, 'email' => 'unknown@example.com',
                'first_name' => 'Rene', 'last_name' => 'Refsman',
            ], 'project' => null],
        ]));

        $plan = app(TrackabiDirectImportService::class)->preview($this->period);

        $this->assertSame([], $plan['entries']);
        $this->assertCount(1, $plan['unmapped_projects']);
        $this->assertDatabaseCount('hubstaff_time_entries', 0);
    }

    public function test_direct_import_rejects_member_id_that_conflicts_with_email_mapping(): void
    {
        $this->employee->update(['trackabi_member_id' => 70, 'trackabi_email' => 'rene@example.com']);
        Http::fake(fn (Request $request) => Http::response([
            [...$this->entry(101), 'member' => [
                'id' => 99, 'email' => 'rene@example.com',
                'first_name' => 'Rene', 'last_name' => 'Refsman',
            ]],
        ]));

        $plan = app(TrackabiDirectImportService::class)->preview($this->period);

        $this->assertSame([], $plan['entries']);
        $this->assertCount(1, $plan['unmapped_employees']);
    }

    public function test_coverage_is_read_only_and_separates_required_employees_from_exempt_employees(): void
    {
        config(['trackabi_direct.import_page_size' => 20]);
        Employee::query()->create(['name' => 'Admin', 'campaign_id' => $this->employee->campaign_id,
            'active' => true, 'paid_without_tracking' => true]);
        $unmapped = [...$this->entry(102), 'project' => null];
        Http::fake(fn (Request $request) => Http::response([$this->entry(101), $unmapped]));

        $report = app(TrackabiCoverageReportService::class)->report($this->period);
        $this->assertSame(1, $report['tracking_required']);
        $this->assertSame(1, $report['tracking_exempt']);
        $this->assertSame(15, $report['days']['scheduled_work']);
        $this->assertSame(0, $report['days']['scheduled_off']);
        $this->assertSame(15, $report['days']['activity_missing']);

        $this->artisan("trackabi:coverage --period={$this->period->id}")
            ->expectsOutputToContain('requieren Trackabi: 1; exentos: 1')
            ->expectsOutputToContain('actividad faltante: 15')
            ->expectsOutputToContain('Empleados con entradas utilizables: 1; sin entradas utilizables: 0')
            ->assertSuccessful();

        $this->assertDatabaseCount('hubstaff_time_entries', 0);
        $this->assertDatabaseCount('daily_time_reviews', 0);
        $this->assertDatabaseCount('tracking_import_alerts', 0);
    }

    public function test_direct_fixed_salary_does_not_display_unreviewed_days_as_paid(): void
    {
        $result = PayrollResult::query()->create([
            'employee_id' => $this->employee->id, 'payroll_period_id' => $this->period->id,
            'salary_calculation_method' => 'semi_monthly_fixed_with_deductions',
            'worked_days' => 0, 'scheduled_days' => 10,
            'daily_rate' => 450, 'biweekly_salary_amount' => 6750,
            'lost_time_amount' => 6750, 'worked_salary_amount' => 0,
        ]);

        $this->assertSame(0.0, $result->displayWorkedDays());
    }

    public function test_scheduled_days_without_direct_timer_reduce_fixed_salary_until_off_or_justified(): void
    {
        $this->employee->update([
            'monthly_salary' => 2400, 'semi_monthly_salary' => 1200,
            'daily_rate' => 80, 'hourly_rate' => 10,
            'preassigned_overtime_weekly_hours' => 5,
        ]);
        Http::fake(fn (Request $request) => Http::response([$this->entry(101, '2026-10-02', '09:00:00')]));
        app(TrackabiDirectImportService::class)->import($this->period);

        $missing = DailyTimeReview::query()->where('payroll_period_id', $this->period->id)
            ->where('employee_id', $this->employee->id)->whereDate('date', '2026-09-26')->sole();
        $this->assertSame('missing_timer', $missing->lost_time_source);
        $this->assertSame(28800, (int) $missing->estimated_lost_seconds);
        $this->assertSame(0, (int) $missing->payable_seconds);
        $this->assertSame(0, $missing->payrollTrackedSeconds());

        $result = PayrollResult::query()->where('payroll_period_id', $this->period->id)
            ->where('employee_id', $this->employee->id)->sole();
        $this->assertSame(1.0, $result->displayWorkedDays());
        $this->assertSame(403200, (int) $result->lost_time_seconds);
        $this->assertSame(1120.0, (float) $result->lost_time_amount);
        $this->assertSame(80.0, (float) $result->worked_salary_amount);

        $missing->update(['paid_day_off' => true, 'status' => 'revisado_supervisor']);
        $justified = DailyTimeReview::query()->where('payroll_period_id', $this->period->id)
            ->where('employee_id', $this->employee->id)->whereDate('date', '2026-09-27')->sole();
        $justified->update(['justified_absence_seconds' => 28800, 'status' => 'revisado_supervisor']);
        app(PayrollCalculationService::class)->recalculateEmployeePreservingManual($this->period, $this->employee);

        $result->refresh();
        $this->assertSame(3.0, $result->displayWorkedDays());
        $this->assertSame(960.0, (float) $result->lost_time_amount);
        $this->assertSame(240.0, (float) $result->worked_salary_amount);
        $this->assertTrue($missing->fresh()->paid_day_off);
        $this->assertSame(28800, (int) $justified->fresh()->justified_absence_seconds);
    }

    public function test_template_days_without_timer_are_unpaid_until_off_or_justified(): void
    {
        config(['trackabi_direct.import_page_size' => 20]);
        $template = WorkScheduleTemplate::query()->create([
            'name' => 'Palmetto 36h test', 'schedule_type' => 'diurna', 'active' => true,
        ]);
        foreach ([7, 7, 7, 7, 8] as $index => $hours) {
            $template->days()->create([
                'day_number' => $index + 1, 'expected_seconds' => $hours * 3600,
                'is_working_day' => true,
            ]);
        }
        $this->employee->update([
            'work_schedule_template_id' => $template->id,
            'daily_hours' => 7, 'hourly_rate' => 62.5, 'daily_rate' => 450,
            'monthly_salary' => 13500, 'semi_monthly_salary' => 6750,
        ]);
        $dates = [
            '2026-09-28', '2026-09-29', '2026-09-30', '2026-10-01', '2026-10-02',
            '2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08',
        ];
        Http::fake(fn (Request $request) => Http::response(array_map(
            fn (string $date, int $index): array => $this->entry(200 + $index, $date, in_array($date, ['2026-10-02'], true) ? '08:00:00' : '07:00:00'),
            $dates, array_keys($dates),
        )));

        app(TrackabiDirectImportService::class)->import($this->period);

        $result = PayrollResult::query()->where('payroll_period_id', $this->period->id)
            ->where('employee_id', $this->employee->id)->sole();
        $this->assertSame(10.0, (float) $result->scheduled_days);
        $this->assertSame(9.0, (float) $result->worked_days);
        $this->assertSame(28800, (int) $result->lost_time_seconds);
        $this->assertSame(450.0, (float) $result->lost_time_amount);
        $this->assertSame(6300.0, (float) $result->worked_salary_amount);
        $this->assertSame(9.0, $result->displayWorkedDays());

        foreach (['2026-09-27', '2026-10-03', '2026-10-04', '2026-10-10'] as $date) {
            DailyTimeReview::query()->where('payroll_period_id', $this->period->id)
                ->where('employee_id', $this->employee->id)->whereDate('date', $date)
                ->sole()->update(['paid_day_off' => true, 'status' => 'revisado_supervisor']);
        }
        $lastOff = DailyTimeReview::query()->where('payroll_period_id', $this->period->id)
            ->where('employee_id', $this->employee->id)->whereDate('date', '2026-09-26')->sole();
        $lastOff->update(['paid_day_off' => true, 'status' => 'revisado_supervisor']);
        $this->assertSame(0, (int) $lastOff->fresh()->justified_absence_seconds);
        app(PayrollCalculationService::class)->recalculateEmployeePreservingManual($this->period, $this->employee);

        $result->refresh();
        $this->assertSame(28800, (int) $result->lost_time_seconds);
        $this->assertSame(450.0, (float) $result->lost_time_amount);
        $this->assertSame(6300.0, (float) $result->worked_salary_amount);
        $this->assertSame(14.0, $result->displayWorkedDays());

        $missing = DailyTimeReview::query()->where('payroll_period_id', $this->period->id)
            ->where('employee_id', $this->employee->id)->whereDate('date', '2026-10-09')->sole();
        $missing->update(['justified_absence_seconds' => 14400, 'status' => 'revisado_supervisor']);
        app(PayrollCalculationService::class)->recalculateEmployeePreservingManual($this->period, $this->employee);

        $result->refresh();
        $this->assertSame(14400, (int) $result->lost_time_seconds);
        $this->assertSame(225.0, (float) $result->lost_time_amount);
        $this->assertSame(6525.0, (float) $result->worked_salary_amount);
        $this->assertSame(14.5, $result->displayWorkedDays());
        $this->assertSame('revisado_supervisor', $missing->fresh()->status);
    }

    public function test_rotating_off_only_increases_day_count_without_adding_salary_or_hours(): void
    {
        $schedule = ScheduleType::query()->create([
            'name' => 'Rotativa', 'code' => 'rotativa', 'active' => true,
        ]);
        $template = WorkScheduleTemplate::query()->create([
            'name' => 'Rotativa 4x4 test', 'schedule_type' => 'rotativa', 'active' => true,
        ]);
        foreach (range(1, 4) as $day) {
            $template->days()->create([
                'day_number' => $day, 'expected_seconds' => 39600, 'is_working_day' => true,
            ]);
        }
        $this->employee->update([
            'schedule_type_id' => $schedule->id,
            'work_schedule_template_id' => $template->id,
            'schedule_cycle_anchor_date' => '2026-09-26',
            'daily_hours' => 11, 'hourly_rate' => 45.4545, 'daily_rate' => 500,
            'monthly_salary' => 15000, 'semi_monthly_salary' => 7500,
            'hubstaff_expected_hours_per_workday' => 11,
            'paid_hours_per_workday' => 12,
        ]);
        app(PayrollCalculationService::class)->recalculateEmployeePreservingManual($this->period, $this->employee);
        $result = PayrollResult::query()->where('payroll_period_id', $this->period->id)
            ->where('employee_id', $this->employee->id)->sole();
        $beforeSalary = (float) $result->worked_salary_amount;
        $beforeHours = (int) $result->payable_seconds;
        $beforeLoss = (float) $result->lost_time_amount;
        $this->assertSame(0.0, $result->displayWorkedDays());
        $this->assertSame(4000.0, $beforeLoss);
        $this->assertSame(3500.0, $beforeSalary);

        $off = DailyTimeReview::query()->where('payroll_period_id', $this->period->id)
            ->where('employee_id', $this->employee->id)->whereDate('date', '2026-09-30')->sole();
        $this->assertFalse($off->scheduled_work_day);
        $off->update(['paid_day_off' => true, 'status' => 'revisado_supervisor']);
        app(PayrollCalculationService::class)->recalculateEmployeePreservingManual($this->period, $this->employee);

        $result->refresh();
        $this->assertSame(1.0, $result->displayWorkedDays());
        $this->assertSame($beforeSalary, (float) $result->worked_salary_amount);
        $this->assertSame($beforeHours, (int) $result->payable_seconds);
        $this->assertSame($beforeLoss, (float) $result->lost_time_amount);

        $workday = DailyTimeReview::query()->where('payroll_period_id', $this->period->id)
            ->where('employee_id', $this->employee->id)->whereDate('date', '2026-09-29')->sole();
        $this->assertTrue($workday->scheduled_work_day);
        $workday->update(['paid_day_off' => true, 'status' => 'revisado_supervisor']);
        app(PayrollCalculationService::class)->recalculateEmployeePreservingManual($this->period, $this->employee);

        $result->refresh();
        $this->assertSame(2.0, $result->displayWorkedDays());
        $this->assertSame($beforeSalary + 500.0, (float) $result->worked_salary_amount);
        $this->assertSame($beforeLoss - 500.0, (float) $result->lost_time_amount);
        $this->assertSame($beforeHours + 39600, (int) $result->payable_seconds);
    }

    public function test_rotating_cycle_continues_across_payroll_period_boundary(): void
    {
        $schedule = ScheduleType::query()->create([
            'name' => 'Rotativa', 'code' => 'rotativa', 'active' => true,
        ]);
        $this->employee->update([
            'schedule_type_id' => $schedule->id,
            'schedule_cycle_anchor_date' => '2026-09-23',
            'rotation_work_days' => 4,
            'rotation_rest_days' => 4,
            'daily_hours' => 11,
        ]);

        $expectation = app(ScheduleExpectationService::class);
        $this->assertTrue($expectation->forDate($this->employee, Carbon::parse('2026-09-25'))['scheduled_work_day']);
        $this->assertTrue($expectation->forDate($this->employee, Carbon::parse('2026-09-26'))['scheduled_work_day']);
        $this->assertSame(39600, $expectation->forDate($this->employee, Carbon::parse('2026-09-26'))['expected_ordinary_seconds']);
        $this->assertFalse($expectation->forDate($this->employee, Carbon::parse('2026-09-27'))['scheduled_work_day']);
    }

    public function test_non_rotating_timer_outside_template_does_not_count_as_work_without_insights(): void
    {
        $template = WorkScheduleTemplate::query()->create([
            'name' => 'Monday to Friday', 'schedule_type' => 'diurna', 'active' => true,
        ]);
        foreach (range(1, 5) as $day) {
            $template->days()->create([
                'day_number' => $day, 'expected_seconds' => 28800,
                'is_working_day' => true,
            ]);
        }
        $this->employee->update([
            'work_schedule_template_id' => $template->id,
            'monthly_salary' => 2400, 'semi_monthly_salary' => 1200,
            'daily_rate' => 80, 'hourly_rate' => 10,
        ]);
        Http::fake(fn (Request $request) => Http::response([$this->entry(101, '2026-09-26', '08:00:00')]));

        app(TrackabiDirectImportService::class)->import($this->period);

        $saturday = DailyTimeReview::query()->where('payroll_period_id', $this->period->id)
            ->where('employee_id', $this->employee->id)->whereDate('date', '2026-09-26')->sole();
        $result = PayrollResult::query()->where('payroll_period_id', $this->period->id)
            ->where('employee_id', $this->employee->id)->sole();
        $this->assertFalse($saturday->scheduled_work_day);
        $this->assertSame(0, (int) $saturday->payable_seconds);
        $this->assertSame(0.0, $result->displayWorkedDays());
    }

    public function test_pagination_fetches_all_pages_and_preserves_exact_seconds(): void
    {
        Http::fake(fn (Request $request) => Http::response(match ((int) $request['page']) {
            1 => [$this->entry(101), $this->entry(102, '2026-09-27', '01:02:03')],
            2 => [$this->entry(103, '2026-09-28', '00:00:17')],
            default => [],
        }));

        $plan = app(TrackabiDirectImportService::class)->preview($this->period, 'PALMETTO');

        $this->assertSame(3, $plan['api_entries']);
        $this->assertSame(3, $plan['would_insert']);
        $this->assertSame(28770 + 3723 + 17, $plan['total_logged_seconds']);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request['project_id'] === 75415);
    }

    public function test_direct_api_failure_keeps_existing_data_and_records_an_alert(): void
    {
        HubstaffTimeEntry::query()->create([
            'payroll_period_id' => $this->period->id,
            'employee_id' => $this->employee->id,
            'hubstaff_member' => $this->employee->name,
            'date' => '2026-09-26',
            'source_provider' => 'trackabi_api',
            'direct_external_id' => '101',
            'external_id' => '101',
            'total_seconds' => 28770,
        ]);
        Http::fake(['https://api.trackabi.test/*' => Http::response(['error' => 'Unavailable'], 503)]);

        $this->artisan("trackabi:import --period={$this->period->id} --commit")->assertFailed();

        $this->assertDatabaseCount('hubstaff_time_entries', 1);
        $this->assertSame(28770, (int) HubstaffTimeEntry::query()->sole()->total_seconds);
        $this->assertDatabaseHas('tracking_import_alerts', [
            'payroll_period_id' => $this->period->id, 'code' => 'import_failed',
        ]);
        Http::assertSent(fn (Request $request): bool => ! str_contains($request->url(), 'mindcloud'));
    }

    public function test_legacy_entry_blocks_direct_import_without_deactivation(): void
    {
        $legacy = HubstaffTimeEntry::query()->create([
            'payroll_period_id' => $this->period->id,
            'employee_id' => $this->employee->id,
            'hubstaff_member' => $this->employee->name,
            'date' => '2026-09-26',
            'source_provider' => 'trackabi',
            'total_seconds' => 28800,
            'active' => true,
        ]);
        Http::fake();

        $this->artisan("trackabi:import --period={$this->period->id} --dry-run")->assertFailed();

        $this->assertTrue($legacy->fresh()->active);
        Http::assertNothingSent();
    }

    public function test_unknown_employee_is_not_created_or_counted(): void
    {
        Http::fake(fn (Request $request) => Http::response([
            [...$this->entry(101), 'member' => ['id' => 99, 'email' => 'unknown@example.com',
                'first_name' => 'Unknown', 'last_name' => 'Worker']],
        ]));

        $plan = app(TrackabiDirectImportService::class)->preview($this->period);

        $this->assertSame(0, $plan['employees_matched']);
        $this->assertCount(1, $plan['unmapped_employees']);
        $this->assertDatabaseCount('employees', 1);
        $this->assertDatabaseCount('hubstaff_time_entries', 0);
    }

    public function test_palmetto_projects_share_campaign_without_importing_dosys_bpo(): void
    {
        config(['trackabi_direct.import_page_size' => 10]);

        $palmetto = $this->employee->campaign;
        $marco = Employee::query()->create([
            'name' => 'Marco Lara', 'email' => 'marco@example.com',
            'campaign_id' => $palmetto->id, 'active' => true, 'daily_hours' => 8, 'hourly_rate' => 10,
        ]);
        $dosys = Campaign::query()->create(['name' => 'Dosys BPO']);
        $orely = Employee::query()->create([
            'name' => 'Orely Ramirez', 'email' => 'orely@example.com',
            'campaign_id' => $dosys->id, 'active' => true, 'daily_hours' => 8,
            'hourly_rate' => 10, 'paid_without_tracking' => true,
        ]);
        TrackabiProjectMapping::query()->create([
            'trackabi_project_id' => 80297, 'trackabi_project_name' => 'Palmetto B',
            'campaign_id' => $palmetto->id, 'active' => true,
        ]);
        $marcoEntry = $this->entry(102);
        $marcoEntry['member'] = ['id' => 71, 'email' => 'marco@example.com', 'first_name' => 'Marco', 'last_name' => 'Lara'];
        $marcoEntry['project'] = ['id' => 80297, 'name' => 'Palmetto B'];
        $dosysEntry = $this->entry(103);
        $dosysEntry['member'] = ['id' => 72, 'email' => 'orely@example.com', 'first_name' => 'Orely', 'last_name' => 'Ramirez'];
        $dosysEntry['project'] = ['id' => 99999, 'name' => 'Dosys BPO'];
        Http::fake(fn (Request $request) => Http::response([$this->entry(101), $marcoEntry, $dosysEntry]));

        $result = app(TrackabiDirectImportService::class)->import($this->period);

        $this->assertSame(2, $result['written']);
        $this->assertCount(1, $result['unmapped_projects']);
        $this->assertDatabaseHas('hubstaff_time_entries', [
            'employee_id' => $this->employee->id, 'trackabi_project_id' => 75415,
        ]);
        $this->assertDatabaseHas('hubstaff_time_entries', [
            'employee_id' => $marco->id, 'trackabi_project_id' => 80297,
        ]);
        $this->assertDatabaseMissing('hubstaff_time_entries', ['employee_id' => $orely->id]);
        $this->assertDatabaseHas('tracking_import_alerts', ['code' => 'unmapped_project']);
    }

    public function test_project_a_time_is_imported_for_an_employee_supervised_by_project_b_supervisor(): void
    {
        $victor = User::query()->create([
            'name' => 'Victor Vasquez', 'email' => 'victor@example.com',
            'password' => 'password', 'profile' => 'supervisor', 'active' => true,
        ]);
        $marco = Employee::query()->create([
            'name' => 'Marco Lara', 'email' => 'marco@example.com',
            'campaign_id' => $this->employee->campaign_id,
            'supervisor_user_id' => $victor->id, 'active' => true,
            'daily_hours' => 8, 'hourly_rate' => 10,
        ]);
        $entry = $this->entry(104);
        $entry['member'] = ['id' => 71, 'email' => 'marco@example.com', 'first_name' => 'Marco', 'last_name' => 'Lara'];
        Http::fake(fn (Request $request) => Http::response([$entry]));

        $result = app(TrackabiDirectImportService::class)->import($this->period);

        $this->assertSame(1, $result['written']);
        $this->assertDatabaseHas('hubstaff_time_entries', [
            'employee_id' => $marco->id, 'trackabi_project_id' => 75415,
            'source_provider' => 'trackabi_api',
        ]);
        $this->assertSame([$marco->id], Employee::query()->visibleTo($victor)->pluck('id')->all());
        $this->assertSame(0, TrackingImportAlert::query()->where('code', 'project_team_mismatch')->count());
    }

    public function test_direct_import_never_applies_historical_proposal_after_cutover(): void
    {
        $history = PayrollPeriod::query()->create([
            'name' => 'Histórico Hubstaff', 'starts_at' => '2026-08-10',
            'ends_at' => '2026-08-21', 'status' => 'cerrado',
        ]);
        config(['trackabi.historical_loss_period_ids' => [$history->id]]);
        foreach (['2026-08-10', '2026-08-11', '2026-08-12', '2026-08-13', '2026-08-14',
            '2026-08-17', '2026-08-18', '2026-08-19', '2026-08-20', '2026-08-21'] as $index => $date) {
            $seconds = $index < 5 ? 27000 : 28800;
            DailyTimeReview::query()->create([
                'employee_id' => $this->employee->id, 'payroll_period_id' => $history->id,
                'date' => $date, 'scheduled_work_day' => true,
                'expected_hubstaff_seconds' => 28800, 'hubstaff_total_seconds' => $seconds,
            ]);
            HubstaffTimeEntry::query()->create([
                'employee_id' => $this->employee->id, 'payroll_period_id' => $history->id,
                'date' => $date, 'hubstaff_member' => $this->employee->name,
                'source_provider' => 'hubstaff_csv', 'active' => true, 'total_seconds' => $seconds,
            ]);
        }

        Http::fake(fn (Request $request) => Http::response([$this->entry(101, '2026-09-26', '09:00:00')]));
        $result = app(TrackabiDirectImportService::class)->import($this->period);

        $this->assertSame(0, $result['estimated_days']);
        $review = DailyTimeReview::query()->where('payroll_period_id', $this->period->id)
            ->where('employee_id', $this->employee->id)->whereDate('date', '2026-09-26')->sole();
        $this->assertSame('missing_activity', $review->lost_time_source);
        $this->assertSame(0, (int) $review->estimated_lost_seconds);
        $this->assertSame(28800, $review->payrollTrackedSeconds());
        $this->assertDatabaseHas('tracking_import_alerts', ['code' => 'TRACKABI_ACTIVITY_MISSING',
            'external_id' => 'activity:'.$this->employee->id.':2026-09-26', 'resolved' => false]);
        $this->assertSame(32400, $review->hubstaff_total_seconds);
        $this->assertSame(32400, HubstaffTimeEntry::query()->where('payroll_period_id', $this->period->id)->sole()->total_seconds);

        $review->update(['status' => 'revisado_supervisor', 'justified_absence_seconds' => 0]);
        $this->assertSame(0, $review->fresh()->finalEstimatedLostSeconds());
        $this->assertSame(0, app(TrackabiDirectImportService::class)->import($this->period)['estimated_days']);
        $this->assertSame('revisado_supervisor', $review->fresh()->status);
    }
}
