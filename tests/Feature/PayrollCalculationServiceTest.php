<?php

namespace Tests\Feature;

use App\Filament\Resources\DailyTimeReviews\DailyTimeReviewResource;
use App\Models\Campaign;
use App\Models\DailyTimeReview;
use App\Models\DeductionType;
use App\Models\Employee;
use App\Models\EmployeeAdditionalDeduction;
use App\Models\HubstaffTimeEntry;
use App\Models\PaidTimeProject;
use App\Models\PayrollBonus;
use App\Models\PayrollDeduction;
use App\Models\PayrollOvertimeAdjustment;
use App\Models\PayrollPeriod;
use App\Models\ScheduleType;
use App\Models\Team;
use App\Services\PayrollCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class PayrollCalculationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_generates_daily_reviews_and_payroll_results(): void
    {
        $period = PayrollPeriod::query()->create([
            'name' => 'May 2026',
            'starts_at' => '2026-05-01',
            'ends_at' => '2026-05-15',
            'apply_deductions' => true,
        ]);

        $employee = Employee::query()->create([
            'name' => 'Ana Gomez',
            'daily_hours' => 8,
            'hourly_rate' => 10,
        ]);

        PaidTimeProject::query()->create([
            'name' => 'Lunch',
            'match_type' => 'contains',
            'category' => 'lunch',
            'is_paid' => true,
        ]);

        HubstaffTimeEntry::query()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'hubstaff_member' => 'Ana Gomez',
            'date' => '2026-05-01',
            'project' => 'Operations',
            'regular_seconds' => 25200,
            'total_seconds' => 28800,
            'idle_seconds' => 1800,
            'activity_percentage' => 80,
            'idle_percentage' => 10,
        ]);

        PayrollBonus::query()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'scope_type' => 'employee',
            'type' => 'manual',
            'amount' => 25,
            'status' => 'aprobado',
        ]);

        $service = app(PayrollCalculationService::class);
        $service->generateDailyReviews($period);

        $review = DailyTimeReview::query()->firstOrFail();

        $this->assertSame(28800, $review->expected_seconds);
        $this->assertSame(1800, $review->unjustified_idle_seconds);
        $this->assertSame(28800, $review->payable_seconds);
        $this->assertSame('80.00', $review->activity_percentage);
        $this->assertSame('10.00', $review->idle_percentage);

        $review->update([
            'justified_idle_seconds' => 900,
            'unjustified_idle_seconds' => 900,
            'justified_absence_seconds' => 600,
        ]);

        $service->recalculateDailyReview($review);
        $service->generatePayrollResults($period);

        $this->assertDatabaseHas('payroll_results', [
            'employee_id' => $employee->id,
            'payable_seconds' => 28800,
            'bonuses_amount' => 25,
            'net_amount' => 105,
        ]);
    }

    public function test_idle_is_kept_as_reported_without_justification(): void
    {
        $period = PayrollPeriod::query()->create([
            'name' => 'May 2026',
            'starts_at' => '2026-05-01',
            'ends_at' => '2026-05-15',
            'apply_deductions' => true,
        ]);
        $employee = Employee::query()->create(['name' => 'Ana Gomez']);
        $review = DailyTimeReview::query()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'date' => '2026-05-01',
            'expected_seconds' => 28800,
            'hubstaff_total_seconds' => 28800,
            'hubstaff_idle_seconds' => 1800,
            'justified_idle_seconds' => 1500,
            'unjustified_idle_seconds' => 1500,
        ]);

        app(PayrollCalculationService::class)->recalculateDailyReview($review);

        $review->refresh();

        $this->assertSame(0, $review->justified_idle_seconds);
        $this->assertSame(1800, $review->unjustified_idle_seconds);
        $this->assertSame(1800, $review->pending_idle_seconds);
    }

    public function test_team_bonus_and_deductions_are_included_in_net_amount(): void
    {
        $period = PayrollPeriod::query()->create([
            'name' => 'May 2026',
            'starts_at' => '2026-05-01',
            'ends_at' => '2026-05-15',
            'apply_deductions' => true,
        ]);
        $campaign = Campaign::query()->create(['name' => 'PALMETTO']);
        $team = Team::query()->create(['name' => 'BDC', 'campaign_id' => $campaign->id]);
        $employee = Employee::query()->create([
            'name' => 'Ana Gomez',
            'campaign_id' => $campaign->id,
            'team_id' => $team->id,
            'daily_hours' => 8,
            'hourly_rate' => 10,
            'location' => 'remote',
            'internet_subsidy_amount' => 15,
            'applies_ihss' => true,
        ]);
        $deductionType = DeductionType::query()->create([
            'name' => 'IHSS',
            'code' => 'ihss',
            'calculation_type' => 'fixed',
            'default_amount' => 5,
        ]);
        $period->deductionTypes()->sync([$deductionType->id]);
        DailyTimeReview::query()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'date' => '2026-05-01',
            'expected_seconds' => 28800,
            'hubstaff_total_seconds' => 28800,
            'payable_seconds' => 28800,
            'status' => 'aprobado_rrhh',
        ]);
        PayrollBonus::query()->create([
            'payroll_period_id' => $period->id,
            'scope_type' => 'team',
            'team_id' => $team->id,
            'type' => 'productivity',
            'amount' => 20,
            'status' => 'aprobado',
        ]);

        app(PayrollCalculationService::class)->recalculatePayrollResults($period);
        $deduction = PayrollDeduction::query()->firstOrFail();

        $this->assertDatabaseHas('payroll_results', [
            'employee_id' => $employee->id,
            'worked_salary_amount' => 80,
            'productivity_bonus_amount' => 20,
            'internet_subsidy_amount' => 15,
            'ihss_amount' => 5,
            'net_amount' => 110,
        ]);
        $this->assertSame('aprobado', $deduction->status);
    }

    public function test_blank_days_are_unpaid_until_marked_as_paid_day_off(): void
    {
        $period = PayrollPeriod::query()->create([
            'name' => 'May 2026',
            'starts_at' => '2026-05-01',
            'ends_at' => '2026-05-02',
        ]);
        $employee = Employee::query()->create([
            'name' => 'Ana Gomez',
            'daily_hours' => 8,
            'hourly_rate' => 10,
            'overtime_hours' => 5,
        ]);

        HubstaffTimeEntry::query()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'hubstaff_member' => 'Ana Gomez',
            'date' => '2026-05-01',
            'project' => 'Operations',
            'regular_seconds' => 28800,
            'total_seconds' => 28800,
        ]);

        $service = app(PayrollCalculationService::class);
        $service->generateDailyReviews($period);
        $service->generatePayrollResults($period);

        $this->assertDatabaseHas('payroll_results', [
            'employee_id' => $employee->id,
            'worked_days' => 1,
            'worked_salary_amount' => 80,
        ]);

        $blankReview = DailyTimeReview::query()
            ->where('employee_id', $employee->id)
            ->whereDate('date', '2026-05-02')
            ->firstOrFail();

        $this->assertSame(0, $blankReview->assigned_overtime_seconds);

        $blankReview->update(['paid_day_off' => true]);
        $service->recalculateDailyReview($blankReview);
        $service->generatePayrollResults($period);

        $this->assertDatabaseHas('daily_time_reviews', [
            'id' => $blankReview->id,
            'paid_day_off' => true,
            'unjustified_absence_seconds' => 0,
            'payable_seconds' => 28800,
        ]);
        $this->assertDatabaseHas('payroll_results', [
            'employee_id' => $employee->id,
            'worked_days' => 2,
            'worked_salary_amount' => 160,
            'lost_time_seconds' => 0,
            'lost_time_amount' => 0,
        ]);
    }

    public function test_justified_absence_pays_only_normal_hours_without_lost_time_or_overtime(): void
    {
        $period = PayrollPeriod::query()->create([
            'name' => 'Justified absence',
            'starts_at' => '2026-05-01',
            'ends_at' => '2026-05-01',
        ]);
        $employee = Employee::query()->create([
            'name' => 'Ana Gomez',
            'daily_hours' => 8,
            'hourly_rate' => 10,
            'overtime_hours' => 5,
            'overtime_hourly_rate' => 12.5,
        ]);
        $review = DailyTimeReview::query()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'date' => '2026-05-01',
            'expected_seconds' => 28800,
            'assigned_overtime_seconds' => 3600,
            'assigned_overtime_fulfilled' => false,
            'hubstaff_total_seconds' => 0,
            'justified_absence_seconds' => 28800,
            'unjustified_absence_seconds' => 0,
        ]);

        $service = app(PayrollCalculationService::class);
        $service->recalculateDailyReview($review);
        $service->recalculateEmployeePayrollResult($period, $employee);

        $this->assertDatabaseHas('daily_time_reviews', [
            'id' => $review->id,
            'assigned_overtime_seconds' => 0,
            'justified_absence_seconds' => 28800,
            'unjustified_absence_seconds' => 0,
            'payable_seconds' => 28800,
        ]);
        $this->assertDatabaseHas('payroll_results', [
            'employee_id' => $employee->id,
            'payable_seconds' => 28800,
            'worked_salary_amount' => 80,
            'overtime_seconds' => 0,
            'overtime_amount' => 0,
            'lost_time_seconds' => 0,
            'lost_time_amount' => 0,
            'net_amount' => 80,
        ]);
    }

    public function test_additional_employee_deductions_are_applied_to_selected_period(): void
    {
        $period = PayrollPeriod::query()->create([
            'name' => 'May 2026',
            'starts_at' => '2026-05-01',
            'ends_at' => '2026-05-15',
            'apply_deductions' => true,
        ]);
        $employee = Employee::query()->create([
            'name' => 'Ana Gomez',
            'daily_hours' => 8,
            'hourly_rate' => 10,
            'applies_ihss' => false,
        ]);

        EmployeeAdditionalDeduction::query()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'amount' => 75,
            'description' => 'Ajuste por pago extra anterior',
            'active' => true,
        ]);

        DailyTimeReview::query()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'date' => '2026-05-01',
            'expected_seconds' => 28800,
            'hubstaff_total_seconds' => 28800,
            'payable_seconds' => 28800,
        ]);

        app(PayrollCalculationService::class)->recalculatePayrollResults($period);

        $this->assertDatabaseHas('payroll_deductions', [
            'employee_id' => $employee->id,
            'amount' => 75,
            'description' => 'Ajuste por pago extra anterior',
            'status' => 'aprobado',
        ]);
        $this->assertDatabaseHas('payroll_results', [
            'employee_id' => $employee->id,
            'total_deductions_amount' => 75,
            'net_amount' => 5,
        ]);
    }

    public function test_daily_review_difference_uses_regular_hours_plus_assigned_daily_overtime(): void
    {
        $period = PayrollPeriod::query()->create([
            'name' => 'May 2026',
            'starts_at' => '2026-05-01',
            'ends_at' => '2026-05-01',
        ]);
        $employee = Employee::query()->create([
            'name' => 'Ana Gomez',
            'daily_hours' => 8,
            'hourly_rate' => 10,
            'overtime_hours' => 5,
        ]);

        HubstaffTimeEntry::query()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'hubstaff_member' => 'Ana Gomez',
            'date' => '2026-05-01',
            'total_seconds' => 32400,
        ]);

        app(PayrollCalculationService::class)->generateDailyReviews($period);

        $this->assertDatabaseHas('daily_time_reviews', [
            'employee_id' => $employee->id,
            'expected_seconds' => 28800,
            'assigned_overtime_seconds' => 3600,
            'hubstaff_total_seconds' => 32400,
            'difference_seconds' => 0,
            'possible_overtime_seconds' => 3600,
        ]);
    }

    public function test_recalculate_refreshes_assigned_daily_overtime_for_existing_reviews(): void
    {
        $period = PayrollPeriod::query()->create([
            'name' => 'May 2026',
            'starts_at' => '2026-05-01',
            'ends_at' => '2026-05-01',
        ]);
        $employee = Employee::query()->create([
            'name' => 'Ana Gomez',
            'daily_hours' => 8,
            'hourly_rate' => 10,
            'overtime_hours' => 5,
        ]);

        DailyTimeReview::query()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'date' => '2026-05-01',
            'expected_seconds' => 28800,
            'assigned_overtime_seconds' => 0,
            'assigned_overtime_fulfilled' => true,
            'hubstaff_total_seconds' => 32400,
            'payable_seconds' => 32400,
        ]);

        app(PayrollCalculationService::class)->recalculatePayrollResults($period);

        $this->assertDatabaseHas('daily_time_reviews', [
            'employee_id' => $employee->id,
            'assigned_overtime_seconds' => 3600,
            'difference_seconds' => 0,
            'possible_overtime_seconds' => 3600,
        ]);
    }

    public function test_idle_does_not_double_count_missing_required_time(): void
    {
        $period = PayrollPeriod::query()->create([
            'name' => 'May 2026',
            'starts_at' => '2026-05-01',
            'ends_at' => '2026-05-01',
        ]);
        $employee = Employee::query()->create([
            'name' => 'Ana Gomez',
            'daily_hours' => 8,
            'hourly_rate' => 10,
            'overtime_hours' => 5,
        ]);

        DailyTimeReview::query()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'date' => '2026-05-01',
            'expected_seconds' => 28800,
            'assigned_overtime_seconds' => 0,
            'hubstaff_total_seconds' => 28440,
            'hubstaff_idle_seconds' => 4140,
            'payable_seconds' => 28440,
        ]);

        app(PayrollCalculationService::class)->recalculatePayrollResults($period);

        $this->assertDatabaseHas('daily_time_reviews', [
            'employee_id' => $employee->id,
            'assigned_overtime_seconds' => 3600,
            'difference_seconds' => -3960,
            'payable_seconds' => 28440,
            'possible_overtime_seconds' => 0,
        ]);
    }

    public function test_overtime_amount_includes_fulfilled_preassigned_overtime_and_manual_adjustments(): void
    {
        $period = PayrollPeriod::query()->create([
            'name' => 'May 2026',
            'starts_at' => '2026-05-01',
            'ends_at' => '2026-05-15',
        ]);
        $employee = Employee::query()->create([
            'name' => 'Ana Gomez',
            'daily_hours' => 8,
            'hourly_rate' => 10,
            'overtime_hours' => 4,
            'overtime_hourly_rate' => 12.5,
            'monthly_overtime_amount' => round(4 * 12.5 * (30 / 7), 2),
        ]);

        DailyTimeReview::query()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'date' => '2026-05-01',
            'expected_seconds' => 28800,
            'assigned_overtime_seconds' => 2880,
            'assigned_overtime_fulfilled' => true,
            'hubstaff_total_seconds' => 35280,
            'payable_seconds' => 31680,
        ]);
        PayrollOvertimeAdjustment::query()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'hours' => 1,
            'hourly_rate' => 12.5,
            'amount' => 12.5,
            'description' => 'Hora extra adicional',
            'active' => true,
        ]);

        app(PayrollCalculationService::class)->recalculatePayrollResults($period);

        $this->assertDatabaseHas('payroll_results', [
            'employee_id' => $employee->id,
            'overtime_hourly_rate' => 12.5,
            'overtime_seconds' => 6480,
            'overtime_amount' => 22.5,
        ]);
    }

    public function test_full_justification_can_complete_confirmed_assigned_overtime(): void
    {
        $period = PayrollPeriod::query()->create([
            'name' => 'May 12 example',
            'starts_at' => '2026-05-12',
            'ends_at' => '2026-05-12',
        ]);
        $employee = Employee::query()->create([
            'name' => 'Ana Gomez',
            'daily_hours' => 8,
            'hourly_rate' => 10,
            'overtime_hours' => 5,
            'overtime_hourly_rate' => 12.5,
        ]);
        $review = DailyTimeReview::query()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'date' => '2026-05-12',
            'expected_seconds' => 28800,
            'assigned_overtime_seconds' => 3600,
            'assigned_overtime_fulfilled' => true,
            'hubstaff_total_seconds' => 28440,
            'hubstaff_idle_seconds' => 4140,
        ]);

        $data = DailyTimeReviewResource::secondsFromHourStates([
            'justified_lost_time_hours' => '1:06',
            'assigned_overtime_fulfilled' => true,
        ], $review);
        $review->update($data);

        $service = app(PayrollCalculationService::class);
        $service->recalculateDailyReview($review);
        $service->recalculateEmployeePayrollResult($period, $employee);

        $this->assertDatabaseHas('daily_time_reviews', [
            'id' => $review->id,
            'justified_absence_seconds' => 3960,
            'hubstaff_idle_seconds' => 4140,
            'difference_seconds' => -3960,
            'payable_seconds' => 32400,
            'possible_overtime_seconds' => 3600,
        ]);
        $this->assertDatabaseHas('payroll_results', [
            'employee_id' => $employee->id,
            'regular_lost_seconds' => 0,
            'overtime_lost_seconds' => 0,
            'lost_time_seconds' => 0,
            'worked_salary_amount' => 80,
            'overtime_amount' => 12.5,
            'lost_time_amount' => 0,
            'net_amount' => 92.5,
        ]);
    }

    public function test_three_justified_minutes_complete_a_nine_hour_payable_day(): void
    {
        $period = PayrollPeriod::query()->create([
            'name' => 'May 19 example',
            'starts_at' => '2026-05-19',
            'ends_at' => '2026-05-19',
        ]);
        $employee = Employee::query()->create([
            'name' => 'Ailen',
            'daily_hours' => 8,
            'hourly_rate' => 10,
            'overtime_hours' => 5,
            'overtime_hourly_rate' => 12.5,
        ]);
        $review = DailyTimeReview::query()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'date' => '2026-05-19',
            'expected_seconds' => 28800,
            'assigned_overtime_seconds' => 3600,
            'assigned_overtime_fulfilled' => true,
            'hubstaff_total_seconds' => 32220,
            'justified_absence_seconds' => 180,
        ]);

        $service = app(PayrollCalculationService::class);
        $service->recalculateDailyReview($review);
        $service->recalculateEmployeePayrollResult($period, $employee);

        $this->assertDatabaseHas('daily_time_reviews', [
            'id' => $review->id,
            'payable_seconds' => 32400,
            'unjustified_absence_seconds' => 0,
            'possible_overtime_seconds' => 3600,
        ]);
        $this->assertDatabaseHas('payroll_results', [
            'employee_id' => $employee->id,
            'payable_seconds' => 32400,
            'lost_time_seconds' => 0,
            'worked_salary_amount' => 80,
            'overtime_seconds' => 3600,
            'overtime_amount' => 12.5,
            'net_amount' => 92.5,
        ]);
    }

    public function test_partial_justification_only_pays_worked_plus_justified_regular_time(): void
    {
        $period = PayrollPeriod::query()->create([
            'name' => 'Partial justification',
            'starts_at' => '2026-05-01',
            'ends_at' => '2026-05-01',
        ]);
        $employee = Employee::query()->create([
            'name' => 'Ana Gomez',
            'daily_hours' => 8,
            'hourly_rate' => 10,
        ]);
        $review = DailyTimeReview::query()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'date' => '2026-05-01',
            'expected_seconds' => 28800,
            'hubstaff_total_seconds' => 7200,
            'justified_absence_seconds' => 10800,
        ]);

        $service = app(PayrollCalculationService::class);
        $service->recalculateDailyReview($review);
        $service->recalculateEmployeePayrollResult($period, $employee);

        $this->assertDatabaseHas('payroll_results', [
            'employee_id' => $employee->id,
            'payable_seconds' => 18000,
            'regular_lost_seconds' => 10800,
            'worked_salary_amount' => 50,
            'lost_time_amount' => 30,
            'net_amount' => 50,
        ]);
    }

    public function test_additional_deduction_applies_and_recalculates_even_when_global_deductions_are_off(): void
    {
        $period = PayrollPeriod::query()->create([
            'name' => 'Additional deduction only',
            'starts_at' => '2026-05-01',
            'ends_at' => '2026-05-01',
            'apply_deductions' => false,
        ]);
        $employee = Employee::query()->create([
            'name' => 'Ana Gomez',
            'daily_hours' => 8,
            'hourly_rate' => 10,
        ]);
        DailyTimeReview::query()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'date' => '2026-05-01',
            'expected_seconds' => 28800,
            'hubstaff_total_seconds' => 28800,
            'payable_seconds' => 28800,
        ]);

        app(PayrollCalculationService::class)->recalculatePayrollResults($period);

        EmployeeAdditionalDeduction::query()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'amount' => 25,
            'description' => 'Deducción manual',
            'active' => true,
        ]);

        $this->assertDatabaseHas('payroll_results', [
            'employee_id' => $employee->id,
            'total_deductions_amount' => 25,
            'net_amount' => 55,
        ]);
    }

    public function test_hours_above_preassigned_overtime_require_manual_adjustment_to_be_paid(): void
    {
        $period = PayrollPeriod::query()->create([
            'name' => 'Overtime cap',
            'starts_at' => '2026-05-01',
            'ends_at' => '2026-05-01',
        ]);
        $employee = Employee::query()->create([
            'name' => 'Ana Gomez',
            'daily_hours' => 8,
            'hourly_rate' => 10,
            'overtime_hours' => 5,
            'overtime_hourly_rate' => 12.5,
        ]);
        DailyTimeReview::query()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'date' => '2026-05-01',
            'expected_seconds' => 28800,
            'assigned_overtime_seconds' => 3600,
            'assigned_overtime_fulfilled' => true,
            'hubstaff_total_seconds' => 36000,
        ]);

        app(PayrollCalculationService::class)->recalculatePayrollResults($period);

        $this->assertDatabaseHas('payroll_results', [
            'employee_id' => $employee->id,
            'overtime_seconds' => 3600,
            'overtime_amount' => 12.5,
        ]);

        PayrollOvertimeAdjustment::query()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'hours' => 1,
            'hourly_rate' => 12.5,
            'amount' => 12.5,
            'active' => true,
        ]);

        $this->assertDatabaseHas('payroll_results', [
            'employee_id' => $employee->id,
            'overtime_seconds' => 7200,
            'overtime_amount' => 25,
        ]);
    }

    public function test_manual_overtime_cannot_pay_hours_without_hubstaff_excess(): void
    {
        $period = PayrollPeriod::query()->create([
            'name' => 'No overtime excess',
            'starts_at' => '2026-05-01',
            'ends_at' => '2026-05-01',
        ]);
        $employee = Employee::query()->create([
            'name' => 'Ana Gomez',
            'daily_hours' => 8,
            'hourly_rate' => 10,
            'overtime_hours' => 5,
            'overtime_hourly_rate' => 12.5,
        ]);
        DailyTimeReview::query()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'date' => '2026-05-01',
            'expected_seconds' => 28800,
            'assigned_overtime_seconds' => 3600,
            'assigned_overtime_fulfilled' => true,
            'hubstaff_total_seconds' => 32400,
        ]);
        PayrollOvertimeAdjustment::query()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'hours' => 1,
            'hourly_rate' => 12.5,
            'amount' => 12.5,
            'active' => true,
        ]);

        app(PayrollCalculationService::class)->recalculatePayrollResults($period);

        $this->assertDatabaseHas('payroll_results', [
            'employee_id' => $employee->id,
            'overtime_seconds' => 3600,
            'overtime_amount' => 12.5,
        ]);
    }

    public function test_four_weekly_overtime_hours_are_assigned_as_one_hour_on_four_workdays(): void
    {
        $period = PayrollPeriod::query()->create([
            'name' => 'Elalf May 25-29',
            'starts_at' => '2026-05-25',
            'ends_at' => '2026-05-29',
        ]);
        $employee = Employee::query()->create([
            'name' => 'Elalf',
            'daily_hours' => 8,
            'hourly_rate' => 10,
            'overtime_hours' => 4,
            'overtime_hourly_rate' => 12.5,
        ]);
        $workedSecondsByDate = [
            '2026-05-25' => 28800,
            '2026-05-26' => 39540,
            '2026-05-27' => 39600,
            '2026-05-28' => 32400,
            '2026-05-29' => 32400,
        ];

        foreach ($workedSecondsByDate as $date => $totalSeconds) {
            HubstaffTimeEntry::query()->create([
                'payroll_period_id' => $period->id,
                'employee_id' => $employee->id,
                'hubstaff_member' => 'Elalf',
                'date' => $date,
                'total_seconds' => $totalSeconds,
            ]);
        }

        $service = app(PayrollCalculationService::class);
        $service->generateDailyReviews($period);
        $service->recalculatePayrollResults($period);

        $may26 = DailyTimeReview::query()
            ->where('employee_id', $employee->id)
            ->whereDate('date', '2026-05-26')
            ->firstOrFail();

        $this->assertSame(3600, $may26->assigned_overtime_seconds);
        $this->assertSame(7140, $may26->difference_seconds);
        $this->assertSame(32400, $may26->payable_seconds);
        $this->assertSame(14400, DailyTimeReview::query()
            ->where('payroll_period_id', $period->id)
            ->where('employee_id', $employee->id)
            ->sum('assigned_overtime_seconds'));
        $this->assertDatabaseHas('payroll_results', [
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'overtime_seconds' => 14400,
            'overtime_amount' => 50,
        ]);
    }

    public function test_fifth_overtime_day_is_additional_after_the_four_hour_weekly_pool_is_used(): void
    {
        $period = PayrollPeriod::query()->create([
            'name' => 'Weekly overtime cap',
            'starts_at' => '2026-05-25',
            'ends_at' => '2026-05-29',
        ]);
        $employee = Employee::query()->create([
            'name' => 'Four hour employee',
            'daily_hours' => 8,
            'hourly_rate' => 10,
            'overtime_hours' => 4,
            'overtime_hourly_rate' => 12.5,
        ]);

        foreach (range(25, 29) as $day) {
            HubstaffTimeEntry::query()->create([
                'payroll_period_id' => $period->id,
                'employee_id' => $employee->id,
                'hubstaff_member' => 'Four hour employee',
                'date' => "2026-05-{$day}",
                'total_seconds' => 32400,
            ]);
        }

        $service = app(PayrollCalculationService::class);
        $service->generateDailyReviews($period);
        $service->recalculatePayrollResults($period);

        $fifthDay = DailyTimeReview::query()
            ->where('employee_id', $employee->id)
            ->whereDate('date', '2026-05-29')
            ->firstOrFail();

        $this->assertSame(0, $fifthDay->assigned_overtime_seconds);
        $this->assertSame(3600, $fifthDay->difference_seconds);
        $this->assertDatabaseHas('payroll_results', [
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'overtime_seconds' => 14400,
        ]);

        PayrollOvertimeAdjustment::query()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'hours' => 1,
            'hourly_rate' => 12.5,
            'amount' => 12.5,
            'active' => true,
        ]);

        $this->assertDatabaseHas('payroll_results', [
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'overtime_seconds' => 18000,
            'overtime_amount' => 62.5,
        ]);
    }

    public function test_weekly_overtime_pool_is_shared_when_a_week_crosses_two_payroll_periods(): void
    {
        $firstPeriod = PayrollPeriod::query()->create([
            'name' => 'First half',
            'starts_at' => '2026-05-11',
            'ends_at' => '2026-05-15',
        ]);
        $secondPeriod = PayrollPeriod::query()->create([
            'name' => 'Second half',
            'starts_at' => '2026-05-16',
            'ends_at' => '2026-05-17',
        ]);
        $employee = Employee::query()->create([
            'name' => 'Cross-period employee',
            'daily_hours' => 8,
            'hourly_rate' => 10,
            'overtime_hours' => 4,
            'overtime_hourly_rate' => 12.5,
        ]);

        foreach ([
            [$firstPeriod, '2026-05-13'],
            [$firstPeriod, '2026-05-14'],
            [$firstPeriod, '2026-05-15'],
            [$secondPeriod, '2026-05-16'],
            [$secondPeriod, '2026-05-17'],
        ] as [$period, $date]) {
            HubstaffTimeEntry::query()->create([
                'payroll_period_id' => $period->id,
                'employee_id' => $employee->id,
                'hubstaff_member' => 'Cross-period employee',
                'date' => $date,
                'total_seconds' => 32400,
            ]);
        }

        $service = app(PayrollCalculationService::class);
        $service->generateDailyReviews($firstPeriod);
        $service->generateDailyReviews($secondPeriod);
        $service->recalculatePayrollResults($firstPeriod);
        $service->recalculatePayrollResults($secondPeriod);

        $this->assertSame(14400, DailyTimeReview::query()
            ->where('employee_id', $employee->id)
            ->whereBetween('date', ['2026-05-11', '2026-05-17'])
            ->sum('assigned_overtime_seconds'));
        $this->assertSame(3600, DailyTimeReview::query()
            ->where('payroll_period_id', $secondPeriod->id)
            ->sum('assigned_overtime_seconds'));
    }

    public function test_rotating_schedule_uses_ten_regular_hours_plus_one_overtime_hour(): void
    {
        $schedule = ScheduleType::query()->create([
            'name' => 'Rotativa',
            'code' => 'rotativa',
            'weekly_hours' => 40,
            'daily_hours' => 10,
            'active' => true,
        ]);
        $period = PayrollPeriod::query()->create([
            'name' => 'Rotating cycle',
            'starts_at' => '2026-05-26',
            'ends_at' => '2026-06-02',
            'status' => 'en_revision',
        ]);
        $employee = Employee::query()->create([
            'name' => 'Rotating employee',
            'schedule_type_id' => $schedule->id,
            'schedule_cycle_anchor_date' => '2026-05-26',
            'weekly_hours' => 40,
            'daily_hours' => 8,
            'hourly_rate' => 10,
            'monthly_salary' => 2400,
            'overtime_hours' => 4,
            'overtime_hourly_rate' => 12.5,
        ]);
        foreach (range(26, 29) as $day) {
            HubstaffTimeEntry::query()->create([
                'payroll_period_id' => $period->id,
                'employee_id' => $employee->id,
                'hubstaff_member' => 'Rotating employee',
                'date' => "2026-05-{$day}",
                'total_seconds' => 39600,
            ]);
        }

        $service = app(PayrollCalculationService::class);
        $service->generateDailyReviews($period);
        $service->recalculatePayrollResults($period);

        $this->assertDatabaseHas('daily_time_reviews', [
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'date' => '2026-05-26 00:00:00',
            'expected_seconds' => 36000,
            'assigned_overtime_seconds' => 3600,
            'assigned_overtime_fulfilled' => true,
            'difference_seconds' => 0,
            'payable_seconds' => 39600,
        ]);
        $this->assertDatabaseHas('daily_time_reviews', [
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'date' => '2026-05-30 00:00:00',
            'expected_seconds' => 0,
            'assigned_overtime_seconds' => 0,
            'payable_seconds' => 0,
        ]);
        $this->assertDatabaseHas('payroll_results', [
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'monthly_salary' => 2400,
            'worked_salary_amount' => 400,
            'overtime_seconds' => 14400,
            'overtime_amount' => 50,
            'lost_time_seconds' => 0,
            'lost_time_amount' => 0,
        ]);
    }

    public function test_rotating_partial_overtime_is_paid_proportionally_and_can_be_completed_with_justification(): void
    {
        $schedule = ScheduleType::query()->create([
            'name' => 'Rotativa',
            'code' => 'rotativa',
            'weekly_hours' => 40,
            'daily_hours' => 10,
            'active' => true,
        ]);
        $period = PayrollPeriod::query()->create([
            'name' => 'Rotating missing minute',
            'starts_at' => '2026-05-26',
            'ends_at' => '2026-05-26',
            'status' => 'en_revision',
        ]);
        $employee = Employee::query()->create([
            'name' => 'Rotating employee with missing minute',
            'schedule_type_id' => $schedule->id,
            'schedule_cycle_anchor_date' => '2026-05-26',
            'weekly_hours' => 40,
            'daily_hours' => 8,
            'hourly_rate' => 10,
            'monthly_salary' => 2400,
            'overtime_hours' => 4,
            'overtime_hourly_rate' => 12.5,
        ]);
        HubstaffTimeEntry::query()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'hubstaff_member' => $employee->name,
            'date' => '2026-05-26',
            'total_seconds' => 37800,
        ]);

        $service = app(PayrollCalculationService::class);
        $service->generateDailyReviews($period);
        $service->recalculatePayrollResults($period);

        $review = DailyTimeReview::query()
            ->where('payroll_period_id', $period->id)
            ->where('employee_id', $employee->id)
            ->firstOrFail();

        $this->assertSame(1800, (int) $review->unjustified_absence_seconds);
        $this->assertSame(37800, (int) $review->payable_seconds);
        $this->assertDatabaseHas('payroll_results', [
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'worked_salary_amount' => 100,
            'overtime_seconds' => 1800,
            'overtime_amount' => 6.25,
            'lost_time_seconds' => 0,
            'lost_time_amount' => 0,
        ]);

        $review->update([
            'status' => 'revisado_supervisor',
            'assigned_overtime_fulfilled' => true,
        ]);
        $service->recalculateDailyReview($review->fresh());
        $service->recalculatePayrollResults($period);

        $this->assertDatabaseHas('daily_time_reviews', [
            'id' => $review->id,
            'payable_seconds' => 37800,
            'unjustified_absence_seconds' => 1800,
        ]);
        $this->assertDatabaseHas('payroll_results', [
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'worked_salary_amount' => 95,
            'overtime_seconds' => 3600,
            'overtime_amount' => 12.5,
            'lost_time_seconds' => 1800,
            'lost_time_amount' => 5,
        ]);

        $review->update([
            'justified_absence_seconds' => 1800,
        ]);
        $service->recalculateDailyReview($review->fresh());
        $service->recalculatePayrollResults($period);

        $this->assertDatabaseHas('daily_time_reviews', [
            'id' => $review->id,
            'payable_seconds' => 39600,
            'unjustified_absence_seconds' => 0,
        ]);
        $this->assertDatabaseHas('payroll_results', [
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'worked_salary_amount' => 100,
            'overtime_seconds' => 3600,
            'overtime_amount' => 12.5,
            'lost_time_seconds' => 0,
            'lost_time_amount' => 0,
        ]);
    }

    public function test_rotating_schedule_command_only_resets_the_four_target_employees(): void
    {
        $schedule = ScheduleType::query()->create([
            'name' => 'Rotativa',
            'code' => 'rotativa',
            'weekly_hours' => 40,
            'daily_hours' => 10,
            'active' => true,
        ]);
        $period = PayrollPeriod::query()->create([
            'name' => 'Production correction',
            'starts_at' => '2026-05-26',
            'ends_at' => '2026-06-10',
            'status' => 'en_revision',
        ]);
        $targetNames = [
            'Elalf Shamir Dominguez Pineda',
            'Emely Charlote Mejia Duarte',
            'Wilman Josué Elías Agurcia',
            'Valery Rachel Bermudez Lanza',
        ];
        $targets = collect($targetNames)->map(fn (string $name): Employee => Employee::query()->create([
            'name' => $name,
            'schedule_type_id' => $schedule->id,
            'weekly_hours' => 40,
            'daily_hours' => 8,
            'hourly_rate' => 10,
            'monthly_salary' => 2400,
            'overtime_hours' => 4,
            'overtime_hourly_rate' => 12.5,
            'active' => true,
        ]));
        $otherEmployee = Employee::query()->create([
            'name' => 'Empleado diurno revisado',
            'daily_hours' => 8,
            'hourly_rate' => 10,
            'monthly_salary' => 2400,
            'overtime_hours' => 4,
            'overtime_hourly_rate' => 12.5,
            'active' => true,
        ]);

        foreach ($targets as $employee) {
            HubstaffTimeEntry::query()->create([
                'payroll_period_id' => $period->id,
                'employee_id' => $employee->id,
                'hubstaff_member' => $employee->name,
                'date' => '2026-05-26',
                'total_seconds' => 39600,
            ]);
        }
        HubstaffTimeEntry::query()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $otherEmployee->id,
            'hubstaff_member' => $otherEmployee->name,
            'date' => '2026-05-26',
            'total_seconds' => 27000,
        ]);

        $service = app(PayrollCalculationService::class);
        $service->generateDailyReviews($period);

        $targetReview = DailyTimeReview::query()
            ->where('employee_id', $targets->first()->id)
            ->whereDate('date', '2026-05-26')
            ->firstOrFail();
        $targetReview->update([
            'status' => 'revisado_supervisor',
            'justified_absence_seconds' => 600,
            'supervisor_comment' => 'Se debe reiniciar',
        ]);
        $otherReview = DailyTimeReview::query()
            ->where('employee_id', $otherEmployee->id)
            ->whereDate('date', '2026-05-26')
            ->firstOrFail();
        $otherReview->update([
            'status' => 'revisado_supervisor',
            'justified_absence_seconds' => 1800,
            'assigned_overtime_seconds' => 2880,
            'assigned_overtime_fulfilled' => true,
            'supervisor_comment' => 'Debe conservarse',
        ]);

        $previewExitCode = Artisan::call('payroll:apply-period-corrections', [
            '--period' => $period->id,
        ]);
        $this->assertSame(0, $previewExitCode, Artisan::output());
        $this->assertDatabaseHas('daily_time_reviews', [
            'id' => $targetReview->id,
            'status' => 'revisado_supervisor',
            'justified_absence_seconds' => 600,
        ]);

        $applyExitCode = Artisan::call('payroll:apply-period-corrections', [
            '--period' => $period->id,
            '--apply' => true,
        ]);
        $this->assertSame(0, $applyExitCode, Artisan::output());

        $this->assertDatabaseHas('daily_time_reviews', [
            'id' => $targetReview->id,
            'status' => 'pendiente',
            'justified_absence_seconds' => 0,
            'supervisor_comment' => null,
            'expected_seconds' => 36000,
            'assigned_overtime_seconds' => 3600,
        ]);
        $this->assertDatabaseHas('daily_time_reviews', [
            'id' => $otherReview->id,
            'status' => 'revisado_supervisor',
            'justified_absence_seconds' => 1800,
            'assigned_overtime_seconds' => 3600,
            'assigned_overtime_fulfilled' => true,
            'supervisor_comment' => 'Debe conservarse',
        ]);

        foreach ($targets as $employee) {
            $this->assertDatabaseHas('employees', [
                'id' => $employee->id,
                'weekly_hours' => 40,
                'overtime_hours' => 4,
            ]);
        }

        $this->assertDatabaseHas('employees', [
            'id' => $targets->firstWhere('name', 'Elalf Shamir Dominguez Pineda')->id,
            'schedule_cycle_anchor_date' => '2026-05-25 00:00:00',
            'monthly_salary' => 2400,
        ]);
    }
}
