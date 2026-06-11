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
use App\Models\Team;
use App\Services\PayrollCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_idle_review_cannot_exceed_total_idle_when_recalculated(): void
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

        $this->assertSame(300, $review->refresh()->unjustified_idle_seconds);
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
            'lost_time_seconds' => 3600,
            'lost_time_amount' => 10,
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
}
