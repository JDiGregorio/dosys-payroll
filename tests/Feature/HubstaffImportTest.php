<?php

namespace Tests\Feature;

use App\Imports\HubstaffTimeEntriesImport;
use App\Models\Campaign;
use App\Models\DailyTimeReview;
use App\Models\Employee;
use App\Models\EmployeeNameMapping;
use App\Models\HubstaffTimeEntry;
use App\Models\PayrollPeriod;
use App\Services\Trackabi\TrackabiDailyNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;
use Tests\TestCase;

class HubstaffImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_trackabi_normalizer_converts_hours_to_seconds(): void
    {
        config([
            'trackabi.min_activity_score_for_full_credit' => 70,
            'trackabi.min_activity_score_for_review' => 40,
        ]);

        $normalizer = app(TrackabiDailyNormalizer::class);

        $entry = $normalizer->normalizeEntry([
            'id' => 'abc-1',
            'member' => [
                'email' => 'agent@example.com',
                'firstName' => 'Marco',
                'lastName' => 'Lara',
            ],
            'dateLogged' => '2026-09-01',
            'loggedTime' => 8,
            'productiveTime' => '07:30:00',
            'activityScore' => 85,
            'project' => ['name' => 'Palmetto'],
        ]);

        $this->assertSame(28800, $entry['tracked_seconds']);
        $this->assertSame(27000, $entry['productive_seconds']);
        $this->assertFalse($entry['requires_manual_review']);
    }

    public function test_trackabi_import_dry_run_maps_by_name_when_email_differs(): void
    {
        config([
            'trackabi.enabled' => true,
            'trackabi.api_base_url' => 'https://trackabi.test',
            'trackabi.api_token' => 'secret-token',
            'trackabi.connection_id' => 'conn-1',
            'trackabi.import_limit' => 100,
            'trackabi.import_max_pages' => 100,
        ]);
        Http::fake([
            'https://trackabi.test/actions/list-time-entries*' => Http::response([
                'data' => [
                    [
                        'id' => 'trackabi-1',
                        'member' => [
                            'email' => 'different@example.com',
                            'name' => 'Lara, Marco',
                        ],
                        'dateLogged' => '2026-09-01',
                        'loggedTime' => '08:00:00',
                        'activityScore' => 80,
                        'project' => ['name' => 'Palmetto'],
                    ],
                ],
                'total' => 1,
            ]),
        ]);

        $campaign = Campaign::query()->create(['name' => 'Palmetto']);
        PayrollPeriod::query()->create([
            'name' => 'Agosto 26 a septiembre 10',
            'starts_at' => '2026-08-26',
            'ends_at' => '2026-09-10',
        ]);
        Employee::query()->create([
            'name' => 'Marco Antonio Lara',
            'email' => 'marco_alberty13@hotmail.com',
            'hubstaff_name' => 'Marco Antonio Lara Alberty',
            'campaign_id' => $campaign->id,
            'daily_hours' => 8,
            'hourly_rate' => 10,
            'active' => true,
        ]);

        $this->artisan('trackabi:import --campaign=Palmetto --from=2026-08-26 --to=2026-09-10 --dry-run')
            ->expectsOutputToContain('Registros mapeados: 1')
            ->expectsOutputToContain('Empleados encontrados: 1')
            ->assertSuccessful();
    }

    public function test_trackabi_dry_run_uses_mindcloud_pagination_and_filters_dates_locally(): void
    {
        config([
            'trackabi.enabled' => true,
            'trackabi.api_base_url' => 'https://trackabi.test',
            'trackabi.api_token' => 'mindcloud-secret',
            'trackabi.connection_id' => 'conn-123',
            'trackabi.palmetto_project_id' => 75415,
            'trackabi.filter_by_project_id' => false,
            'trackabi.estimate_real_time' => false,
            'trackabi.import_limit' => 2,
            'trackabi.import_max_pages' => 2,
            'trackabi.filter_dates_locally' => true,
        ]);

        Http::fake(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return Http::response([
                'success' => true,
                'data' => ((int) ($query['offset'] ?? 0)) === 0
                    ? [
                        [
                            'id' => 9770145,
                            'dateLogged' => '2026-08-26',
                            'loggedTime' => '08:54:00',
                            'member' => [
                                'email' => 'lanzarodriguez23@gmail.com',
                                'firstName' => 'Edwin',
                                'lastName' => 'Rodriguez',
                            ],
                            'project' => ['id' => 75415, 'name' => 'Palmetto'],
                        ],
                        [
                            'id' => 9770146,
                            'dateLogged' => '2026-08-20',
                            'loggedTime' => '08:00:00',
                            'member' => [
                                'email' => 'lanzarodriguez23@gmail.com',
                                'firstName' => 'Edwin',
                                'lastName' => 'Rodriguez',
                            ],
                            'project' => ['id' => 75415, 'name' => 'Palmetto'],
                        ],
                    ]
                    : [
                        [
                            'id' => 9770147,
                            'dateLogged' => '2026-08-27',
                            'loggedTime' => null,
                            'member' => [
                                'email' => 'lanzarodriguez23@gmail.com',
                                'firstName' => 'Edwin',
                                'lastName' => 'Rodriguez',
                            ],
                            'project' => ['id' => 75415, 'name' => 'Palmetto'],
                        ],
                    ],
            ]);
        });

        $campaign = Campaign::query()->create(['name' => 'Palmetto']);
        PayrollPeriod::query()->create([
            'name' => 'Agosto 26 a septiembre 10',
            'starts_at' => '2026-08-26',
            'ends_at' => '2026-09-10',
        ]);
        Employee::query()->create([
            'name' => 'Edwin Alejandro Rodriguez Lanza',
            'email' => 'lanzarodriguez23@gmail.com',
            'campaign_id' => $campaign->id,
            'daily_hours' => 8,
            'hourly_rate' => 10,
            'active' => true,
        ]);

        $this->artisan('trackabi:import --campaign=Palmetto --from=2026-08-26 --to=2026-09-10 --dry-run')
            ->expectsOutputToContain('Registros API: 3')
            ->expectsOutputToContain('Registros después de filtrar fechas localmente: 2')
            ->expectsOutputToContain('Registros con loggedTime null: 1')
            ->expectsOutputToContain('Días empleado encontrados: 2')
            ->expectsOutputToContain('Total Trackabi aplicado: 8:54')
            ->assertSuccessful();

        Http::assertSentCount(2);
        Http::assertSent(function (Request $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $request->hasHeader('Authorization', 'Bearer mindcloud-secret')
                && ($query['connectionId'] ?? null) === 'conn-123'
                && ($query['limit'] ?? null) === '2'
                && ($query['offset'] ?? null) === '0'
                && isset($query['fields'])
                && ! array_key_exists('projectId', $query)
                && ! array_key_exists('startDate', $query)
                && ! array_key_exists('endDate', $query);
        });
        Http::assertSent(function (Request $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return ($query['offset'] ?? null) === '2';
        });

        $this->assertDatabaseMissing('hubstaff_time_entries', [
            'source_provider' => 'trackabi',
            'external_id' => 9770145,
        ]);
    }

    public function test_trackabi_commit_is_limited_to_palmetto_and_does_not_touch_reviewed_days(): void
    {
        config([
            'trackabi.enabled' => true,
            'trackabi.api_base_url' => 'https://trackabi.test',
            'trackabi.api_token' => 'secret-token',
            'trackabi.connection_id' => 'conn-1',
            'trackabi.conflict_strategy' => 'manual_review_on_overlap',
            'trackabi.estimate_real_time' => false,
            'trackabi.import_limit' => 100,
            'trackabi.import_max_pages' => 100,
        ]);
        Http::fake([
            'https://trackabi.test/actions/list-time-entries*' => Http::response([
                'data' => [
                    [
                        'id' => 'trackabi-victor-1',
                        'member' => ['email' => 'vavn300@gmail.com', 'name' => 'Vasquez, Victor'],
                        'dateLogged' => '2026-09-01',
                        'loggedTime' => '10:00:00',
                        'activityScore' => 75,
                        'project' => ['name' => 'Palmetto'],
                    ],
                    [
                        'id' => 'trackabi-marco-protected',
                        'member' => ['email' => 'marco_alberty13@hotmail.com', 'name' => 'Lara, Marco'],
                        'dateLogged' => '2026-09-02',
                        'loggedTime' => '08:00:00',
                        'activityScore' => 75,
                        'project' => ['name' => 'Palmetto'],
                    ],
                    [
                        'id' => 'trackabi-not-allowed',
                        'member' => ['email' => 'someone_else@example.com', 'name' => 'Someone Else'],
                        'dateLogged' => '2026-09-01',
                        'loggedTime' => '08:00:00',
                        'project' => ['name' => 'Palmetto'],
                    ],
                    [
                        'id' => 'trackabi-kelly-1',
                        'member' => ['email' => 'kelly.urquia.7@gmail.com', 'name' => 'Urquia, Kelly'],
                        'dateLogged' => '2026-09-03',
                        'loggedTime' => '07:00:00',
                        'project' => ['name' => 'Palmetto'],
                    ],
                ],
                'total' => 4,
            ]),
        ]);

        $campaign = Campaign::query()->create(['name' => 'Palmetto']);
        $period = PayrollPeriod::query()->create([
            'name' => 'Agosto 26 a septiembre 10',
            'starts_at' => '2026-08-26',
            'ends_at' => '2026-09-10',
        ]);
        $victor = Employee::query()->create([
            'name' => 'Victor Ariel Vasquez Nolasco',
            'email' => 'vavn300@gmail.com',
            'campaign_id' => $campaign->id,
            'daily_hours' => 8,
            'hourly_rate' => 10,
            'active' => true,
        ]);
        $marco = Employee::query()->create([
            'name' => 'Marco Antonio Lara',
            'email' => 'marco_alberty13@hotmail.com',
            'campaign_id' => $campaign->id,
            'daily_hours' => 8,
            'hourly_rate' => 10,
            'active' => true,
        ]);
        $kelly = Employee::query()->create([
            'name' => 'Kelly Katherine Urquia Argueta',
            'email' => 'kelly.urquia.7@gmail.com',
            'campaign_id' => $campaign->id,
            'daily_hours' => 7,
            'hourly_rate' => 10,
            'active' => true,
        ]);

        $hubstaffEntry = HubstaffTimeEntry::query()->create([
            'payroll_period_id' => $period->id,
            'source_provider' => 'hubstaff_csv',
            'employee_id' => $victor->id,
            'hubstaff_member' => 'Victor',
            'date' => '2026-09-01',
            'project' => 'Palmetto',
            'regular_seconds' => 28800,
            'total_seconds' => 28800,
            'active' => true,
        ]);
        DailyTimeReview::query()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $marco->id,
            'date' => '2026-09-02',
            'status' => 'revisado_supervisor',
            'hubstaff_total_seconds' => 28800,
            'payable_seconds' => 28800,
        ]);

        $this->artisan("trackabi:import --period={$period->id} --campaign=Palmetto --from=2026-08-26 --to=2026-09-10 --commit")
            ->expectsOutputToContain('Registros Trackabi creados: 3')
            ->assertSuccessful();

        $this->assertDatabaseHas('hubstaff_time_entries', [
            'source_provider' => 'trackabi',
            'external_id' => 'trackabi-victor-1',
            'employee_id' => $victor->id,
            'total_seconds' => 36000,
            'active' => false,
        ]);
        $this->assertDatabaseHas('hubstaff_time_entries', [
            'id' => $hubstaffEntry->id,
            'active' => true,
        ]);
        $this->assertDatabaseHas('hubstaff_time_entries', [
            'source_provider' => 'trackabi',
            'external_id' => 'trackabi-kelly-1',
            'employee_id' => $kelly->id,
            'total_seconds' => 25200,
            'active' => true,
        ]);
        $this->assertDatabaseHas('hubstaff_time_entries', [
            'source_provider' => 'trackabi',
            'external_id' => 'trackabi-marco-protected',
            'active' => false,
        ]);
        $this->assertDatabaseMissing('hubstaff_time_entries', [
            'source_provider' => 'trackabi',
            'external_id' => 'trackabi-not-allowed',
        ]);
    }

    public function test_it_imports_hubstaff_csv_and_maps_employees(): void
    {
        $period = PayrollPeriod::query()->create([
            'name' => 'May 2026',
            'starts_at' => '2026-05-01',
            'ends_at' => '2026-05-15',
        ]);

        $employee = Employee::query()->create([
            'name' => 'Ana Gomez',
            'hubstaff_name' => 'Ana Hubstaff',
            'daily_hours' => 8,
            'hourly_rate' => 10,
        ]);

        $path = storage_path('app/testing-hubstaff.csv');
        file_put_contents($path, implode("\n", [
            'Date,Member,Client,Project,Team,Task ID,To-do,Regular hours,Total hours,Activity %,Idle (%),Idle (hr),Total spent,Regular spent,PTO,PTO spent,Holiday,Holiday spent,Currency',
            '2026-05-01,Ana Hubstaff,Dosys,Operations,Team A,1,Work,07:00:00,08:00:00,80,10,00:30:00,0,0,0,0,0,0,USD',
            '2026-05-01,Unknown Person,Dosys,Operations,Team A,2,Work,02:00,02:00,70,0,0,0,0,0,0,0,0,USD',
        ]));

        Excel::import(new HubstaffTimeEntriesImport($period), $path);

        $this->assertDatabaseHas('hubstaff_time_entries', [
            'hubstaff_member' => 'Ana Hubstaff',
            'employee_id' => $employee->id,
            'total_seconds' => 28800,
            'idle_seconds' => 1800,
            'activity_percentage' => 80,
            'idle_percentage' => 10,
            'client' => null,
            'task_id' => null,
            'todo' => null,
            'total_spent' => 0,
            'regular_spent' => 0,
            'pto_seconds' => 0,
            'pto_spent' => 0,
            'holiday_seconds' => 0,
            'holiday_spent' => 0,
            'currency' => null,
        ]);

        $this->assertDatabaseHas('hubstaff_time_entries', [
            'hubstaff_member' => 'Unknown Person',
            'employee_id' => null,
        ]);
    }

    public function test_it_rejects_files_with_dates_outside_the_selected_period(): void
    {
        $period = PayrollPeriod::query()->create([
            'name' => 'May 2026',
            'starts_at' => '2026-05-01',
            'ends_at' => '2026-05-15',
        ]);
        $path = storage_path('app/testing-hubstaff-wrong-period.csv');
        file_put_contents($path, implode("\n", [
            'Date,Member,Project,Team,Regular hours,Total hours,Activity %,Idle (%),Idle (hr)',
            '2026-06-01,Ana Hubstaff,Operations,Team A,08:00:00,08:00:00,80,10,00:30:00',
        ]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('fuera del período');

        try {
            Excel::import(new HubstaffTimeEntriesImport($period), $path);
        } finally {
            $this->assertDatabaseCount('hubstaff_time_entries', 0);
        }
    }

    public function test_it_uses_saved_employee_name_mappings_for_future_imports(): void
    {
        $period = PayrollPeriod::query()->create([
            'name' => 'June 2026',
            'starts_at' => '2026-06-01',
            'ends_at' => '2026-06-15',
        ]);
        $employee = Employee::query()->create([
            'name' => 'Empleado de planilla',
            'daily_hours' => 8,
            'hourly_rate' => 10,
        ]);
        EmployeeNameMapping::query()->create([
            'employee_id' => $employee->id,
            'hubstaff_member' => 'Nombre diferente en Hubstaff',
            'confidence' => 100,
            'is_active' => true,
        ]);

        $path = storage_path('app/testing-hubstaff-name-mapping.csv');
        file_put_contents($path, implode("\n", [
            'Date,Member,Project,Team,Regular hours,Total hours,Activity %,Idle (%),Idle (hr)',
            '2026-06-01,Nombre diferente en Hubstaff,Operations,Team A,08:00:00,08:00:00,80,10,00:05:00',
        ]));

        Excel::import(new HubstaffTimeEntriesImport($period), $path);

        $this->assertDatabaseHas('hubstaff_time_entries', [
            'hubstaff_member' => 'Nombre diferente en Hubstaff',
            'employee_id' => $employee->id,
        ]);
    }
}
