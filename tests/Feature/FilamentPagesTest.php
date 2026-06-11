<?php

namespace Tests\Feature;

use App\Filament\Pages\DailyReviewCalendar;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Models\Campaign;
use App\Models\ContractType;
use App\Models\DailyTimeReview;
use App\Models\Department;
use App\Models\Employee;
use App\Models\PayrollPeriod;
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
        $this->assertSame(214.29, $data['monthly_overtime_amount']);
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
}
