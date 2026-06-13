<?php

namespace Tests\Feature;

use App\Filament\Resources\PayrollPeriods\Pages\EditPayrollPeriod;
use App\Models\Employee;
use App\Models\HubstaffTimeEntry;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\Services\HubstaffEmployeeMappingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class HubstaffEmployeeMappingTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_maps_current_entries_and_recalculates_the_period(): void
    {
        $period = PayrollPeriod::query()->create([
            'name' => 'June 1, 2026',
            'starts_at' => '2026-06-01',
            'ends_at' => '2026-06-01',
            'status' => 'importado',
        ]);
        $employee = Employee::query()->create([
            'name' => 'Empleado agregado después',
            'daily_hours' => 8,
            'hourly_rate' => 10,
            'active' => true,
        ]);
        HubstaffTimeEntry::query()->create([
            'payroll_period_id' => $period->id,
            'hubstaff_member' => 'Nombre original Hubstaff',
            'date' => '2026-06-01',
            'regular_seconds' => 28800,
            'total_seconds' => 28800,
        ]);

        $updatedEntries = app(HubstaffEmployeeMappingService::class)->map(
            $period,
            'Nombre original Hubstaff',
            $employee->id,
        );

        $this->assertSame(1, $updatedEntries);
        $this->assertDatabaseHas('employee_name_mappings', [
            'employee_id' => $employee->id,
            'hubstaff_member' => 'Nombre original Hubstaff',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('hubstaff_time_entries', [
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'hubstaff_member' => 'Nombre original Hubstaff',
        ]);
        $this->assertDatabaseHas('daily_time_reviews', [
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'date' => '2026-06-01 00:00:00',
            'hubstaff_total_seconds' => 28800,
        ]);
        $this->assertDatabaseHas('payroll_results', [
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
        ]);
        $this->assertDatabaseHas('payroll_periods', [
            'id' => $period->id,
            'status' => 'en_revision',
        ]);
        $this->assertSame(0, HubstaffTimeEntry::query()
            ->where('payroll_period_id', $period->id)
            ->whereNull('employee_id')
            ->count());
    }

    public function test_rrhh_can_map_an_employee_from_the_period_edit_action(): void
    {
        $user = User::query()->create([
            'name' => 'RRHH Mapping',
            'email' => 'rrhh-mapping@example.com',
            'password' => 'password',
            'profile' => 'rrhh',
            'active' => true,
        ]);
        $period = PayrollPeriod::query()->create([
            'name' => 'June 2, 2026',
            'starts_at' => '2026-06-02',
            'ends_at' => '2026-06-02',
            'status' => 'importado',
        ]);
        $employee = Employee::query()->create([
            'name' => 'Empleado disponible',
            'daily_hours' => 8,
            'hourly_rate' => 10,
            'active' => true,
        ]);
        HubstaffTimeEntry::query()->create([
            'payroll_period_id' => $period->id,
            'hubstaff_member' => 'Nombre pendiente',
            'date' => '2026-06-02',
            'total_seconds' => 28800,
        ]);

        $this->actingAs($user);

        Livewire::test(EditPayrollPeriod::class, ['record' => $period->getRouteKey()])
            ->callAction('mapHubstaffEmployee', [
                'hubstaff_member' => 'Nombre pendiente',
                'employee_id' => $employee->id,
            ])
            ->assertNotified();

        $this->assertDatabaseHas('hubstaff_time_entries', [
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'hubstaff_member' => 'Nombre pendiente',
        ]);
    }
}
