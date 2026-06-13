<?php

namespace Tests\Feature;

use App\Filament\Pages\DailyReviewCalendar;
use App\Filament\Pages\ImportAlerts;
use App\Filament\Resources\Campaigns\CampaignResource;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\Employees\Pages\EditEmployee;
use App\Filament\Resources\Employees\Pages\ListEmployees;
use App\Models\Campaign;
use App\Models\ContractType;
use App\Models\DailyTimeReview;
use App\Models\Department;
use App\Models\Employee;
use App\Models\HubstaffTimeEntry;
use App\Models\PayrollPeriod;
use App\Models\PayrollResult;
use App\Models\ScheduleType;
use App\Models\Team;
use App\Models\TierLevel;
use App\Models\User;
use App\Models\WorkRole;
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

    public function test_tier_one_employee_uses_trial_period_contract(): void
    {
        $trialContract = ContractType::query()->firstOrCreate(['code' => 'trial_period'], ['name' => 'Periodo de prueba']);
        $tierLevel = TierLevel::query()->create(['name' => 'Tier 1']);

        $data = EmployeeResource::normalizeCompensation([
            'tier_level_id' => $tierLevel->id,
            'contract_type_id' => null,
            'daily_hours' => 8,
            'hourly_rate' => 10,
            'overtime_hours' => 4,
        ]);

        $this->assertSame($trialContract->id, $data['contract_type_id']);
        $this->assertSame(2400.0, $data['monthly_salary']);
        $this->assertSame(12.5, $data['overtime_hourly_rate']);
        $this->assertArrayNotHasKey('monthly_overtime_amount', $data);
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
            ->assertDontSee('Calcular planilla');

        $this->get("/admin/payroll-periods/{$period->id}/edit")
            ->assertOk()
            ->assertSee('Reemplazar CSV de Hubstaff')
            ->assertSee('Mapear empleado Hubstaff')
            ->assertSee('Calcular planilla');
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
}
