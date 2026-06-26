<?php

namespace Tests\Feature;

use App\Filament\Pages\DailyReviewCalendar;
use App\Filament\Pages\ImportAlerts;
use App\Filament\Resources\Campaigns\CampaignResource;
use App\Filament\Resources\DailyTimeReviews\DailyTimeReviewResource;
use App\Filament\Resources\EmployeeAdditionalDeductions\EmployeeAdditionalDeductionResource;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\Employees\Pages\EditEmployee;
use App\Filament\Resources\Employees\Pages\ListEmployees;
use App\Filament\Resources\PayrollBonuses\PayrollBonusResource;
use App\Filament\Resources\PayrollDeductions\PayrollDeductionResource;
use App\Filament\Resources\PayrollOvertimeAdjustments\PayrollOvertimeAdjustmentResource;
use App\Filament\Resources\PayrollPeriods\Pages\ListPayrollPeriods;
use App\Filament\Resources\PayrollPeriods\PayrollPeriodResource;
use App\Models\Campaign;
use App\Models\ContractType;
use App\Models\DailyTimeReview;
use App\Models\DeductionType;
use App\Models\Department;
use App\Models\EmployeeAdditionalDeduction;
use App\Models\Employee;
use App\Models\HubstaffTimeEntry;
use App\Models\PayrollBonus;
use App\Models\PayrollDeduction;
use App\Models\PayrollOvertimeAdjustment;
use App\Models\PayrollPeriod;
use App\Models\PayrollResult;
use App\Models\ScheduleType;
use App\Models\Team;
use App\Models\TierLevel;
use App\Models\User;
use App\Models\WorkRole;
use Database\Seeders\ScheduleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FilamentPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_pages_render_for_authenticated_user(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => 'password',
            'profile' => 'rrhh',
            'active' => true,
        ]);

        $this->actingAs($user);

        foreach ([
            '/admin/campaigns',
            '/admin/teams',
            '/admin/departments',
            '/admin/work-roles',
            '/admin/schedule-types',
            '/admin/work-schedule-templates',
            '/admin/contract-types',
            '/admin/hourly-rate-types',
            '/admin/tier-levels',
            '/admin/employees',
            '/admin/payroll-periods',
            '/admin/paid-time-projects',
            '/admin/hubstaff-time-entries',
            '/admin/payroll-bonuses',
            '/admin/payroll-overtime-adjustments',
            '/admin/deduction-types',
            '/admin/employee-additional-deductions',
            '/admin/payroll-deductions',
            '/admin/payroll-results',
            '/admin/daily-review-calendar',
            '/admin/import-alerts',
        ] as $uri) {
            $this->get($uri)->assertOk();
        }

        $this->get('/admin/daily-time-reviews')
            ->assertRedirect('/admin/daily-review-calendar');
    }

    public function test_employee_create_page_renders_relationship_selects(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'rrhh@example.com',
            'password' => 'password',
            'profile' => 'rrhh',
            'active' => true,
        ]);

        $this->actingAs($user);

        $this->get('/admin/employees/create')->assertOk();
    }

    public function test_employee_edit_page_renders_relationship_selects(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'edit-rrhh@example.com',
            'password' => 'password',
            'profile' => 'rrhh',
            'active' => true,
        ]);

        $campaign = Campaign::query()->create(['name' => 'PALMETTO']);
        $team = Team::query()->create(['name' => 'BDC', 'campaign_id' => $campaign->id]);
        $department = Department::query()->create(['name' => 'OPERATIONS']);
        $workRole = WorkRole::query()->create(['name' => 'DEBT COLLECTOR']);
        $scheduleType = ScheduleType::query()->create(['name' => 'Diurna', 'code' => 'diurna']);
        $contractType = ContractType::query()->create(['name' => 'Permanente', 'code' => 'permanent']);
        $tierLevel = TierLevel::query()->create(['name' => 'Tier 2', 'schedule_type_id' => $scheduleType->id]);

        $employee = Employee::query()->create([
            'name' => 'Empleado Test',
            'campaign_id' => $campaign->id,
            'team_id' => $team->id,
            'department_id' => $department->id,
            'work_role_id' => $workRole->id,
            'schedule_type_id' => $scheduleType->id,
            'contract_type_id' => $contractType->id,
            'tier_level_id' => $tierLevel->id,
            'location' => 'on_site',
            'active' => true,
        ]);

        $this->actingAs($user);

        $this->get("/admin/employees/{$employee->id}/edit")->assertOk();
    }

    public function test_employee_dni_and_bank_account_are_visible_in_edit_and_index(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'employee-identifiers@example.com',
            'password' => 'password',
            'profile' => 'rrhh',
            'active' => true,
        ]);
        $employee = Employee::query()->create([
            'name' => 'Empleado Identificado',
            'dni' => '0801-1990-12345',
            'bank_account_number' => '00123456789',
            'active' => true,
        ]);

        $this->actingAs($user);

        Livewire::test(EditEmployee::class, ['record' => $employee->getRouteKey()])
            ->assertFormSet([
                'dni' => '0801-1990-12345',
                'bank_account_number' => '00123456789',
            ]);

        Livewire::test(ListEmployees::class)
            ->assertCanSeeTableRecords([$employee])
            ->assertSee('0801-1990-12345')
            ->assertSee('00123456789');
    }

    public function test_tier_one_employee_uses_trial_period_contract_without_overwriting_salary(): void
    {
        $trialContract = ContractType::query()->firstOrCreate(['code' => 'trial_period'], ['name' => 'Periodo de prueba']);
        $tierLevel = TierLevel::query()->create(['name' => 'Tier 1']);

        $data = EmployeeResource::normalizeCompensation([
            'tier_level_id' => $tierLevel->id,
            'contract_type_id' => null,
            'daily_hours' => 8,
            'hourly_rate' => 10,
            'monthly_salary' => 14200,
            'semi_monthly_salary' => 7100,
            'daily_rate' => 473.3333,
            'overtime_hourly_rate' => 15,
            'overtime_hours' => 4,
        ]);

        $this->assertSame($trialContract->id, $data['contract_type_id']);
        $this->assertSame(14200, $data['monthly_salary']);
        $this->assertSame(7100, $data['semi_monthly_salary']);
        $this->assertSame(473.3333, $data['daily_rate']);
        $this->assertSame(15, $data['overtime_hourly_rate']);
        $this->assertArrayNotHasKey('monthly_overtime_amount', $data);
    }

    public function test_rotating_employee_keeps_all_manual_compensation_values(): void
    {
        $schedule = ScheduleType::query()->create([
            'name' => 'Rotativa',
            'code' => 'rotativa',
            'weekly_hours' => 40,
            'daily_hours' => 10,
        ]);

        $data = EmployeeResource::normalizeCompensation([
            'schedule_type_id' => $schedule->id,
            'weekly_hours' => 40,
            'ordinary_weekly_hours' => 44,
            'daily_hours' => 10,
            'hourly_rate' => 10,
            'monthly_salary' => 16500,
            'semi_monthly_salary' => 8250,
            'daily_rate' => 550,
            'overtime_hourly_rate' => 14.75,
            'preassigned_overtime_weekly_hours' => 4,
            'overtime_hours' => 4,
        ]);

        $this->assertSame(10.0, (float) $data['daily_hours']);
        $this->assertSame(44.0, $data['weekly_hours']);
        $this->assertSame(4.0, $data['overtime_hours']);
        $this->assertSame(16500, $data['monthly_salary']);
        $this->assertSame(8250, $data['semi_monthly_salary']);
        $this->assertSame(550, $data['daily_rate']);
        $this->assertSame(14.75, $data['overtime_hourly_rate']);
    }

    public function test_schedule_seeder_moves_legacy_4x4_references_to_rotating_schedule(): void
    {
        $rotating = ScheduleType::query()->create([
            'name' => 'Rotativa',
            'code' => 'rotativa',
        ]);
        $legacy = ScheduleType::query()->create([
            'name' => 'Modalidad 4x4',
            'code' => '4x4',
        ]);
        $employee = Employee::query()->create([
            'name' => 'Empleado 4x4',
            'schedule_type_id' => $legacy->id,
        ]);
        $tierLevel = TierLevel::query()->create([
            'name' => 'Tier rotativo',
            'schedule_type_id' => $legacy->id,
        ]);

        $this->seed(ScheduleTypeSeeder::class);

        $this->assertDatabaseMissing('schedule_types', ['code' => '4x4']);
        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'schedule_type_id' => $rotating->id,
        ]);
        $this->assertDatabaseHas('tier_levels', [
            'id' => $tierLevel->id,
            'schedule_type_id' => $rotating->id,
        ]);
    }

    public function test_daily_review_calendar_switches_selected_employee_reviews(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'calendar-rrhh@example.com',
            'password' => 'password',
            'profile' => 'rrhh',
            'active' => true,
        ]);

        $period = PayrollPeriod::query()->create([
            'name' => 'Junio 2026',
            'starts_at' => '2026-06-01',
            'ends_at' => '2026-06-15',
        ]);
        $firstEmployee = Employee::query()->create(['name' => 'Ana Calendar', 'active' => true]);
        $secondEmployee = Employee::query()->create(['name' => 'Brenda Calendar', 'active' => true]);

        DailyTimeReview::query()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $firstEmployee->id,
            'date' => '2026-06-01',
            'expected_seconds' => 28800,
            'hubstaff_total_seconds' => 28800,
            'payable_seconds' => 28800,
        ]);
        DailyTimeReview::query()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $secondEmployee->id,
            'date' => '2026-06-01',
            'expected_seconds' => 28800,
            'hubstaff_total_seconds' => 21600,
            'payable_seconds' => 21600,
        ]);

        $this->actingAs($user);

        $component = Livewire::test(DailyReviewCalendar::class)
            ->assertSet('periodId', $period->id)
            ->assertSet('employeeId', $firstEmployee->id)
            ->call('selectEmployee', $secondEmployee->id)
            ->assertSet('employeeId', $secondEmployee->id);

        $this->assertSame(
            21600,
            $component->instance()->reviewsByDate()->get('2026-06-01')->hubstaff_total_seconds,
        );
    }

    public function test_daily_review_calendar_can_select_closed_periods_for_reference(): void
    {
        $user = User::query()->create([
            'name' => 'RRHH Closed Calendar',
            'email' => 'calendar-closed-rrhh@example.com',
            'password' => 'password',
            'profile' => 'rrhh',
            'active' => true,
        ]);
        $period = PayrollPeriod::query()->create([
            'name' => 'Junio cerrado',
            'starts_at' => '2026-06-01',
            'ends_at' => '2026-06-15',
            'status' => 'cerrado',
        ]);
        $employee = Employee::query()->create(['name' => 'Empleado Cerrado Calendar', 'active' => true]);
        DailyTimeReview::query()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'date' => '2026-06-01',
            'expected_seconds' => 28800,
            'hubstaff_total_seconds' => 28800,
            'payable_seconds' => 28800,
        ]);

        $this->actingAs($user);

        Livewire::withQueryParams([
            'period_id' => $period->id,
            'employee_id' => $employee->id,
        ])
            ->test(DailyReviewCalendar::class)
            ->assertSet('periodId', $period->id)
            ->assertSet('employeeId', $employee->id)
            ->assertSee('Junio cerrado')
            ->assertSee('Empleado Cerrado Calendar');
    }

    public function test_daily_review_calendar_query_string_filters_visible_employee_reviews(): void
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'calendar-query-rrhh@example.com',
            'password' => 'password',
            'profile' => 'rrhh',
            'active' => true,
        ]);

        $period = PayrollPeriod::query()->create([
            'name' => 'Junio 2026',
            'starts_at' => '2026-06-01',
            'ends_at' => '2026-06-15',
        ]);
        $firstEmployee = Employee::query()->create(['name' => 'Ana Query Calendar', 'active' => true]);
        $secondEmployee = Employee::query()->create(['name' => 'Brenda Query Calendar', 'active' => true]);

        DailyTimeReview::query()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $firstEmployee->id,
            'date' => '2026-06-01',
            'expected_seconds' => 28800,
            'hubstaff_total_seconds' => 28800,
            'payable_seconds' => 28800,
        ]);
        DailyTimeReview::query()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $secondEmployee->id,
            'date' => '2026-06-01',
            'expected_seconds' => 28800,
            'hubstaff_total_seconds' => 21600,
            'payable_seconds' => 21600,
        ]);

        $this->actingAs($user);

        $this->get("/admin/daily-review-calendar?period_id={$period->id}&employee_id={$secondEmployee->id}")
            ->assertOk()
            ->assertSee('6:00 h')
            ->assertDontSee('8:00 h');
    }

    public function test_supervisor_cannot_access_rrhh_or_employee_management(): void
    {
        $supervisor = User::query()->create([
            'name' => 'Supervisor',
            'email' => 'supervisor-filter@example.com',
            'password' => 'password',
            'profile' => 'supervisor',
            'active' => true,
        ]);
        $otherSupervisor = User::query()->create([
            'name' => 'Other Supervisor',
            'email' => 'other-supervisor@example.com',
            'password' => 'password',
            'profile' => 'supervisor',
            'active' => true,
        ]);

        Employee::query()->create([
            'name' => 'Empleado asignado',
            'supervisor_user_id' => $supervisor->id,
            'active' => true,
        ]);
        Employee::query()->create([
            'name' => 'Empleado ajeno',
            'supervisor_user_id' => $otherSupervisor->id,
            'active' => true,
        ]);

        $this->actingAs($supervisor);

        $this->get('/admin/employees')->assertForbidden();
        $this->get('/admin/campaigns')->assertForbidden();
        $this->get('/admin')->assertOk()->assertDontSee('RRHH');
        $this->assertFalse(EmployeeResource::shouldRegisterNavigation());
        $this->assertFalse(CampaignResource::shouldRegisterNavigation());
    }

    public function test_supervisor_can_access_allowed_payroll_modules_and_view_assigned_payroll_detail(): void
    {
        $supervisor = User::query()->create([
            'name' => 'Supervisor Payroll',
            'email' => 'supervisor-payroll@example.com',
            'password' => 'password',
            'profile' => 'supervisor',
            'active' => true,
        ]);
        $otherSupervisor = User::query()->create([
            'name' => 'Other Payroll',
            'email' => 'other-payroll@example.com',
            'password' => 'password',
            'profile' => 'supervisor',
            'active' => true,
        ]);
        $period = PayrollPeriod::query()->create([
            'name' => 'Junio supervisión',
            'starts_at' => '2026-06-01',
            'ends_at' => '2026-06-15',
        ]);
        $assigned = Employee::query()->create([
            'name' => 'Asignado Payroll',
            'supervisor_user_id' => $supervisor->id,
            'active' => true,
        ]);
        $unassigned = Employee::query()->create([
            'name' => 'No asignado Payroll',
            'supervisor_user_id' => $otherSupervisor->id,
            'active' => true,
        ]);
        $assignedResult = PayrollResult::query()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $assigned->id,
            'monthly_salary' => 7824.33,
            'net_amount' => 7824.33,
        ]);
        $unassignedResult = PayrollResult::query()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $unassigned->id,
        ]);
        foreach ([$assigned, $unassigned] as $employee) {
            DailyTimeReview::query()->create([
                'payroll_period_id' => $period->id,
                'employee_id' => $employee->id,
                'date' => '2026-06-01',
                'expected_seconds' => 28800,
                'expected_ordinary_seconds' => 28800,
                'expected_hubstaff_seconds' => 28800,
                'expected_paid_seconds' => 28800,
                'hubstaff_total_seconds' => 21600,
                'payable_seconds' => 21600,
                'unjustified_absence_seconds' => 7200,
            ]);
        }

        $this->actingAs($supervisor);

        $this->get('/admin/import-alerts')
            ->assertOk()
            ->assertSee('Asignado Payroll')
            ->assertDontSee('No asignado Payroll');
        $this->get('/admin/daily-review-calendar')->assertOk();
        $this->get('/admin/payroll-bonuses')->assertOk();
        $this->get('/admin/payroll-overtime-adjustments')->assertOk();
        $this->get('/admin/payroll-results')
            ->assertOk()
            ->assertSee('Asignado Payroll')
            ->assertDontSee('No asignado Payroll')
            ->assertDontSee('Editar')
            ->assertSee('7,824.33');
        $this->get("/admin/payroll-results/{$assignedResult->id}")
            ->assertOk()
            ->assertSee('Detalle de planilla')
            ->assertDontSee('Guardar cambios');
        $this->get("/admin/payroll-results/{$assignedResult->id}/edit")->assertForbidden();
        $this->get("/admin/payroll-results/{$unassignedResult->id}")->assertNotFound();

        $this->assertTrue(ImportAlerts::shouldRegisterNavigation());
    }

    public function test_import_alerts_show_only_pending_reviews_for_supervisor(): void
    {
        $supervisor = User::query()->create([
            'name' => 'Supervisor Alerts',
            'email' => 'supervisor-alerts@example.com',
            'password' => 'password',
            'profile' => 'supervisor',
            'active' => true,
        ]);
        $otherSupervisor = User::query()->create([
            'name' => 'Other Alerts',
            'email' => 'other-alerts@example.com',
            'password' => 'password',
            'profile' => 'supervisor',
            'active' => true,
        ]);
        $period = PayrollPeriod::query()->create([
            'name' => 'Alertas junio',
            'starts_at' => '2026-06-01',
            'ends_at' => '2026-06-15',
        ]);
        $pendingHoursEmployee = Employee::query()->create([
            'name' => 'Horas Pendientes',
            'supervisor_user_id' => $supervisor->id,
            'active' => true,
        ]);
        $pendingIdleEmployee = Employee::query()->create([
            'name' => 'Idle Pendiente',
            'supervisor_user_id' => $supervisor->id,
            'active' => true,
        ]);
        $foreignEmployee = Employee::query()->create([
            'name' => 'Alerta Ajena',
            'supervisor_user_id' => $otherSupervisor->id,
            'active' => true,
        ]);

        $pendingHours = DailyTimeReview::query()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $pendingHoursEmployee->id,
            'date' => '2026-06-01',
            'expected_seconds' => 28800,
            'assigned_overtime_seconds' => 3600,
            'expected_ordinary_seconds' => 28800,
            'expected_paid_seconds' => 32400,
            'payable_seconds' => 28800,
            'unjustified_absence_seconds' => 3600,
        ]);
        $pendingIdle = DailyTimeReview::query()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $pendingIdleEmployee->id,
            'date' => '2026-06-02',
            'expected_seconds' => 28800,
            'payable_seconds' => 28800,
            'hubstaff_idle_seconds' => 240,
            'unjustified_idle_seconds' => 240,
        ]);
        DailyTimeReview::query()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $foreignEmployee->id,
            'date' => '2026-06-03',
            'expected_seconds' => 28800,
            'assigned_overtime_seconds' => 3600,
            'expected_ordinary_seconds' => 28800,
            'expected_paid_seconds' => 32400,
            'payable_seconds' => 28800,
            'unjustified_absence_seconds' => 3600,
            'hubstaff_idle_seconds' => 600,
            'unjustified_idle_seconds' => 600,
        ]);
        HubstaffTimeEntry::query()->create([
            'payroll_period_id' => $period->id,
            'hubstaff_member' => 'Persona sin mapeo',
            'date' => '2026-06-04',
            'project' => 'Operations',
        ]);

        $this->actingAs($supervisor);

        $component = Livewire::test(ImportAlerts::class)
            ->assertSee('Días con horas pagables menores a las esperadas')
            ->assertSee('Días con idle mayor a 3 minutos')
            ->assertDontSee('Días con horas pagables menores a las esperadas Justificados')
            ->assertDontSee('Días con idle mayor a 3 minutos Justificados')
            ->assertDontSee('Empleados Hubstaff sin mapeo')
            ->assertDontSee('Alerta Ajena');

        $page = $component->instance();

        $this->assertSame([$pendingHours->id], $page->shortPayableDays()->pluck('id')->all());
        $this->assertSame([$pendingIdle->id], $page->highIdleDays()->pluck('id')->all());
        $this->assertTrue($page->unmappedMembers()->isEmpty());
    }

    public function test_import_alerts_are_empty_when_there_is_no_open_period(): void
    {
        $user = User::query()->create([
            'name' => 'RRHH Closed Alerts',
            'email' => 'rrhh-closed-alerts@example.com',
            'password' => 'password',
            'profile' => 'rrhh',
            'active' => true,
        ]);
        $period = PayrollPeriod::query()->create([
            'name' => 'Alertas cerradas',
            'starts_at' => '2026-06-01',
            'ends_at' => '2026-06-15',
            'status' => 'cerrado',
        ]);
        $employee = Employee::query()->create(['name' => 'Empleado alerta cerrada', 'active' => true]);
        DailyTimeReview::query()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'date' => '2026-06-01',
            'expected_paid_seconds' => 32400,
            'payable_seconds' => 28800,
            'unjustified_absence_seconds' => 3600,
            'hubstaff_idle_seconds' => 600,
        ]);
        HubstaffTimeEntry::query()->create([
            'payroll_period_id' => $period->id,
            'hubstaff_member' => 'Sin mapeo cerrado',
            'date' => '2026-06-01',
        ]);

        $this->actingAs($user);

        $component = Livewire::test(ImportAlerts::class)
            ->assertSet('periodId', null);

        $page = $component->instance();

        $this->assertTrue($page->periods()->isEmpty());
        $this->assertTrue($page->shortPayableDays()->isEmpty());
        $this->assertTrue($page->highIdleDays()->isEmpty());
        $this->assertTrue($page->unmappedMembers()->isEmpty());
    }

    public function test_supervisor_daily_review_calendar_only_lists_assigned_employees(): void
    {
        $supervisor = User::query()->create([
            'name' => 'Supervisor Calendar',
            'email' => 'supervisor-calendar@example.com',
            'password' => 'password',
            'profile' => 'supervisor',
            'active' => true,
        ]);
        $otherSupervisor = User::query()->create([
            'name' => 'Other Calendar',
            'email' => 'other-calendar@example.com',
            'password' => 'password',
            'profile' => 'supervisor',
            'active' => true,
        ]);
        $period = PayrollPeriod::query()->create([
            'name' => 'Junio supervisión',
            'starts_at' => '2026-06-01',
            'ends_at' => '2026-06-15',
        ]);
        $assigned = Employee::query()->create([
            'name' => 'Asignado Calendar',
            'supervisor_user_id' => $supervisor->id,
            'active' => true,
        ]);
        $unassigned = Employee::query()->create([
            'name' => 'No asignado Calendar',
            'supervisor_user_id' => $otherSupervisor->id,
            'active' => true,
        ]);

        foreach ([$assigned, $unassigned] as $employee) {
            DailyTimeReview::query()->create([
                'payroll_period_id' => $period->id,
                'employee_id' => $employee->id,
                'date' => '2026-06-01',
                'expected_seconds' => 28800,
                'hubstaff_total_seconds' => 28800,
                'payable_seconds' => 28800,
            ]);
        }

        $this->actingAs($supervisor);

        $this->get('/admin/daily-review-calendar')
            ->assertOk()
            ->assertSee('Asignado Calendar')
            ->assertDontSee('No asignado Calendar');
    }

    public function test_payroll_period_manual_actions_are_shown_on_edit_not_index(): void
    {
        $user = User::query()->create([
            'name' => 'RRHH Actions',
            'email' => 'rrhh-actions@example.com',
            'password' => 'password',
            'profile' => 'rrhh',
            'active' => true,
        ]);
        $period = PayrollPeriod::query()->create([
            'name' => 'Período acciones',
            'starts_at' => '2026-06-01',
            'ends_at' => '2026-06-15',
        ]);
        HubstaffTimeEntry::query()->create([
            'payroll_period_id' => $period->id,
            'hubstaff_member' => 'Empleado pendiente de mapeo',
            'date' => '2026-06-01',
            'total_seconds' => 28800,
        ]);

        $this->actingAs($user);

        $this->get('/admin/payroll-periods')
            ->assertOk()
            ->assertDontSee('Importar CSV de Hubstaff')
            ->assertDontSee('Mapear empleado Hubstaff')
            ->assertDontSee('Actualizar cálculos del período');

        $this->get("/admin/payroll-periods/{$period->id}/edit")
            ->assertOk()
            ->assertSee('Reemplazar CSV de Hubstaff')
            ->assertSee('Mapear empleado Hubstaff')
            ->assertSee('Actualizar cálculos del período');
    }

    public function test_payroll_results_index_hides_internal_schedule_calculation_columns(): void
    {
        $user = User::query()->create([
            'name' => 'RRHH Results',
            'email' => 'rrhh-results@example.com',
            'password' => 'password',
            'profile' => 'rrhh',
            'active' => true,
        ]);
        $period = PayrollPeriod::query()->create([
            'name' => 'Resultados internos',
            'starts_at' => '2026-06-01',
            'ends_at' => '2026-06-15',
        ]);
        $employee = Employee::query()->create([
            'name' => 'Empleado resultados',
            'active' => true,
        ]);
        PayrollResult::query()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
        ]);

        $this->actingAs($user);

        $this->get('/admin/payroll-results')
            ->assertOk()
            ->assertDontSee('Días programados')
            ->assertDontSee('Horas esperadas Hubstaff')
            ->assertDontSee('Horas pagadas esperadas')
            ->assertDontSee('Horas Hubstaff')
            ->assertDontSee('Horas pagables');
    }

    public function test_payroll_results_index_can_show_closed_period_results(): void
    {
        $user = User::query()->create([
            'name' => 'RRHH Closed Results',
            'email' => 'rrhh-closed-results@example.com',
            'password' => 'password',
            'profile' => 'rrhh',
            'active' => true,
        ]);
        $period = PayrollPeriod::query()->create([
            'name' => 'Resultados cerrados',
            'starts_at' => '2026-06-01',
            'ends_at' => '2026-06-15',
            'status' => 'cerrado',
        ]);
        $employee = Employee::query()->create([
            'name' => 'Empleado resultado cerrado',
            'active' => true,
        ]);
        PayrollResult::query()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'net_amount' => 1234.56,
        ]);

        $this->actingAs($user);

        $this->get('/admin/payroll-results')
            ->assertOk()
            ->assertSee('Empleado resultado cerrado');
    }

    public function test_daily_review_edit_shows_hubstaff_detail_tab(): void
    {
        $user = User::query()->create([
            'name' => 'RRHH Detail',
            'email' => 'rrhh-detail@example.com',
            'password' => 'password',
            'profile' => 'rrhh',
            'active' => true,
        ]);
        $period = PayrollPeriod::query()->create([
            'name' => 'Detalle Hubstaff',
            'starts_at' => '2026-06-01',
            'ends_at' => '2026-06-15',
        ]);
        $employee = Employee::query()->create(['name' => 'Empleado Detail', 'active' => true]);
        $review = DailyTimeReview::query()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'date' => '2026-06-03',
            'expected_seconds' => 28800,
            'hubstaff_total_seconds' => 27000,
            'payable_seconds' => 27000,
        ]);
        HubstaffTimeEntry::query()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'hubstaff_member' => $employee->name,
            'date' => '2026-06-03',
            'project' => 'Operations Detail',
            'team' => 'Team Detail',
            'regular_seconds' => 27000,
            'total_seconds' => 27000,
            'idle_seconds' => 1800,
        ]);

        $this->actingAs($user);

        $this->get("/admin/daily-time-reviews/{$review->id}/edit")
            ->assertOk()
            ->assertSee('Registros de Hubstaff')
            ->assertSee('Operations Detail')
            ->assertSee('7:30:00');
    }

    public function test_pending_daily_review_with_zero_or_positive_difference_is_displayed_as_correct(): void
    {
        $period = PayrollPeriod::query()->create([
            'name' => 'Badge correcto',
            'starts_at' => '2026-06-10',
            'ends_at' => '2026-06-12',
        ]);
        $employee = Employee::query()->create([
            'name' => 'Empleado badge correcto',
            'active' => true,
        ]);
        $review = DailyTimeReview::query()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'date' => '2026-06-10',
            'status' => 'pendiente',
            'hubstaff_total_seconds' => 28800,
            'payable_seconds' => 28800,
            'hubstaff_idle_seconds' => 0,
            'difference_seconds' => 0,
        ]);
        $positiveReview = DailyTimeReview::query()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'date' => '2026-06-11',
            'status' => 'pendiente',
            'hubstaff_total_seconds' => 30600,
            'payable_seconds' => 30600,
            'hubstaff_idle_seconds' => 600,
            'difference_seconds' => 1800,
        ]);

        $pendingReview = DailyTimeReview::query()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'date' => '2026-06-12',
            'status' => 'pendiente',
            'hubstaff_total_seconds' => 27000,
            'payable_seconds' => 27000,
            'hubstaff_idle_seconds' => 0,
            'difference_seconds' => -1800,
        ]);

        $this->assertSame('Correcto', DailyTimeReviewResource::displayStatusLabel($review));
        $this->assertSame('Correcto', DailyTimeReviewResource::displayStatusLabel($positiveReview));
        $this->assertSame('pendiente', $review->fresh()->status);
        $this->assertSame('Pendiente', DailyTimeReviewResource::displayStatusLabel($pendingReview));
    }

    public function test_rrhh_can_close_period_with_pending_daily_reviews(): void
    {
        $user = User::query()->create([
            'name' => 'RRHH Close',
            'email' => 'rrhh-close@example.com',
            'password' => 'password',
            'profile' => 'rrhh',
            'active' => true,
        ]);
        $period = PayrollPeriod::query()->create([
            'name' => 'Cierre con pendientes',
            'starts_at' => '2026-06-01',
            'ends_at' => '2026-06-15',
            'status' => 'en_revision',
        ]);
        $employee = Employee::query()->create([
            'name' => 'Empleado pendiente cierre',
            'active' => true,
        ]);
        DailyTimeReview::query()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'date' => '2026-06-10',
            'status' => 'pendiente',
            'hubstaff_total_seconds' => 27000,
            'payable_seconds' => 27000,
            'difference_seconds' => -1800,
        ]);

        $this->actingAs($user);

        Livewire::test(ListPayrollPeriods::class)
            ->callTableAction('closePeriod', $period)
            ->assertNotified();

        $this->assertSame('cerrado', $period->fresh()->status);
    }

    public function test_period_creation_is_blocked_while_an_open_period_exists(): void
    {
        $user = User::query()->create([
            'name' => 'RRHH Create Period',
            'email' => 'rrhh-create-period@example.com',
            'password' => 'password',
            'profile' => 'rrhh',
            'active' => true,
        ]);

        $this->actingAs($user);

        $this->assertTrue(PayrollPeriodResource::canCreate());

        PayrollPeriod::query()->create([
            'name' => 'Período abierto',
            'starts_at' => '2026-06-01',
            'ends_at' => '2026-06-15',
            'status' => 'en_revision',
        ]);

        $this->assertFalse(PayrollPeriodResource::canCreate());
    }

    public function test_closed_period_operational_records_are_hidden_from_active_modules(): void
    {
        $user = User::query()->create([
            'name' => 'RRHH Operational Closed',
            'email' => 'rrhh-operational-closed@example.com',
            'password' => 'password',
            'profile' => 'rrhh',
            'active' => true,
        ]);
        $openPeriod = PayrollPeriod::query()->create([
            'name' => 'Operativo abierto',
            'starts_at' => '2026-06-16',
            'ends_at' => '2026-06-30',
            'status' => 'en_revision',
        ]);
        $closedPeriod = PayrollPeriod::query()->create([
            'name' => 'Operativo cerrado',
            'starts_at' => '2026-06-01',
            'ends_at' => '2026-06-15',
            'status' => 'cerrado',
        ]);
        $employee = Employee::query()->create(['name' => 'Empleado operativo', 'active' => true]);
        $deductionType = DeductionType::query()->create([
            'name' => 'Deducción operativa',
            'code' => 'operational_test',
            'calculation_type' => 'manual',
        ]);

        $openBonus = PayrollBonus::query()->create([
            'payroll_period_id' => $openPeriod->id,
            'employee_id' => $employee->id,
            'scope_type' => 'employee',
            'type' => 'manual',
            'amount' => 10,
        ]);
        $closedBonus = PayrollBonus::query()->create([
            'payroll_period_id' => $closedPeriod->id,
            'employee_id' => $employee->id,
            'scope_type' => 'employee',
            'type' => 'manual',
            'amount' => 20,
        ]);
        $openOvertime = PayrollOvertimeAdjustment::query()->create([
            'payroll_period_id' => $openPeriod->id,
            'employee_id' => $employee->id,
            'hours' => 1,
            'hourly_rate' => 100,
            'amount' => 100,
        ]);
        $closedOvertime = PayrollOvertimeAdjustment::query()->create([
            'payroll_period_id' => $closedPeriod->id,
            'employee_id' => $employee->id,
            'hours' => 2,
            'hourly_rate' => 100,
            'amount' => 200,
        ]);
        $openAdditionalDeduction = EmployeeAdditionalDeduction::query()->create([
            'payroll_period_id' => $openPeriod->id,
            'employee_id' => $employee->id,
            'amount' => 30,
            'description' => 'Abierta',
            'active' => true,
        ]);
        $closedAdditionalDeduction = EmployeeAdditionalDeduction::query()->create([
            'payroll_period_id' => $closedPeriod->id,
            'employee_id' => $employee->id,
            'amount' => 40,
            'description' => 'Cerrada',
            'active' => true,
        ]);
        $openDeduction = PayrollDeduction::query()->create([
            'payroll_period_id' => $openPeriod->id,
            'employee_id' => $employee->id,
            'deduction_type_id' => $deductionType->id,
            'amount' => 50,
            'status' => 'aprobado',
        ]);
        $closedDeduction = PayrollDeduction::query()->create([
            'payroll_period_id' => $closedPeriod->id,
            'employee_id' => $employee->id,
            'deduction_type_id' => $deductionType->id,
            'amount' => 60,
            'status' => 'aprobado',
        ]);

        $this->actingAs($user);

        $this->assertSame([$openBonus->id], PayrollBonusResource::getEloquentQuery()->whereKey([$openBonus->id, $closedBonus->id])->pluck('id')->all());
        $this->assertSame([$openOvertime->id], PayrollOvertimeAdjustmentResource::getEloquentQuery()->whereKey([$openOvertime->id, $closedOvertime->id])->pluck('id')->all());
        $this->assertSame([$openAdditionalDeduction->id], EmployeeAdditionalDeductionResource::getEloquentQuery()->whereKey([$openAdditionalDeduction->id, $closedAdditionalDeduction->id])->pluck('id')->all());
        $this->assertSame([$openDeduction->id], PayrollDeductionResource::getEloquentQuery()->whereKey([$openDeduction->id, $closedDeduction->id])->pluck('id')->all());
    }
}
