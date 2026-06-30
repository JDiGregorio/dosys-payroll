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
use App\Models\PayrollResult;
use App\Models\ScheduleType;
use App\Models\Team;
use App\Models\User;
use App\Models\WorkScheduleTemplate;
use App\Services\PayrollCalculationService;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
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

    public function test_recalculation_preserves_existing_idle_classification(): void
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

        $this->assertSame(1500, $review->justified_idle_seconds);
        $this->assertSame(1500, $review->unjustified_idle_seconds);
        $this->assertSame(0, $review->pending_idle_seconds);
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

    public function test_existing_paid_day_off_uses_updated_schedule_expected_hours(): void
    {
        $schedule = ScheduleType::query()->create([
            'name' => 'Diurna',
            'code' => 'diurna',
            'active' => true,
        ]);
        $template = $this->createTemplate('Diurna lunes 8h test', 'diurna', [8, 7, 7, 7, 7]);
        $period = PayrollPeriod::query()->create([
            'name' => 'Off con plantilla nueva',
            'starts_at' => '2026-06-01',
            'ends_at' => '2026-06-01',
        ]);
        $employee = Employee::query()->create([
            'name' => 'Empleado off existente',
            'schedule_type_id' => $schedule->id,
            'work_schedule_template_id' => $template->id,
            'daily_hours' => 7.2,
            'hourly_rate' => 10,
        ]);
        $review = DailyTimeReview::query()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'date' => '2026-06-01',
            'expected_seconds' => 25920,
            'expected_ordinary_seconds' => 25920,
            'hubstaff_total_seconds' => 0,
            'paid_day_off' => true,
            'unjustified_absence_seconds' => 25920,
            'status' => 'revisado_supervisor',
        ]);

        $service = app(PayrollCalculationService::class);
        $service->recalculateDailyReview($review);
        $service->recalculateEmployeePayrollResult($period, $employee);

        $this->assertDatabaseHas('daily_time_reviews', [
            'id' => $review->id,
            'paid_day_off' => true,
            'expected_ordinary_seconds' => 28800,
            'unjustified_absence_seconds' => 0,
            'payable_seconds' => 28800,
        ]);
        $this->assertDatabaseHas('payroll_results', [
            'employee_id' => $employee->id,
            'worked_days' => 1,
            'worked_salary_amount' => 80,
            'lost_time_seconds' => 0,
        ]);
    }

    public function test_paid_day_off_for_36h_template_pays_exact_daily_scheduled_hours(): void
    {
        $schedule = ScheduleType::query()->create([
            'name' => 'Diurna',
            'code' => 'diurna',
            'active' => true,
        ]);
        $template = $this->createTemplate('Diurna 36h OFF test', 'diurna', [8, 7, 7, 7, 7]);
        $period = PayrollPeriod::query()->create([
            'name' => 'Off 36h',
            'starts_at' => '2026-06-01',
            'ends_at' => '2026-06-02',
        ]);
        $employee = Employee::query()->create([
            'name' => 'Palmetto 36h OFF',
            'schedule_type_id' => $schedule->id,
            'work_schedule_template_id' => $template->id,
            'ordinary_weekly_hours' => 36,
            'daily_hours' => 0,
            'hourly_rate' => 59.1667,
            'salary_calculation_method' => 'hourly_actual_hours',
        ]);

        $service = app(PayrollCalculationService::class);
        $service->generateDailyReviews($period);

        DailyTimeReview::query()
            ->where('employee_id', $employee->id)
            ->where('payroll_period_id', $period->id)
            ->update([
                'paid_day_off' => true,
                'unjustified_absence_seconds' => 9999,
                'status' => 'revisado_supervisor',
            ]);

        DailyTimeReview::query()
            ->where('employee_id', $employee->id)
            ->where('payroll_period_id', $period->id)
            ->get()
            ->each(fn (DailyTimeReview $review) => $service->recalculateDailyReview($review));
        $service->recalculateEmployeePayrollResult($period, $employee);

        $this->assertDatabaseHas('daily_time_reviews', [
            'employee_id' => $employee->id,
            'date' => '2026-06-01 00:00:00',
            'paid_day_off' => true,
            'expected_ordinary_seconds' => 28800,
            'payable_seconds' => 28800,
            'unjustified_absence_seconds' => 0,
        ]);
        $this->assertDatabaseHas('daily_time_reviews', [
            'employee_id' => $employee->id,
            'date' => '2026-06-02 00:00:00',
            'paid_day_off' => true,
            'expected_ordinary_seconds' => 25200,
            'payable_seconds' => 25200,
            'unjustified_absence_seconds' => 0,
        ]);
        $this->assertDatabaseHas('payroll_results', [
            'employee_id' => $employee->id,
            'payable_seconds' => 54000,
            'worked_salary_amount' => 887.50,
            'lost_time_seconds' => 0,
            'lost_time_amount' => 0,
        ]);
    }

    public function test_paid_day_off_for_non_rotating_zero_expected_day_pays_employee_daily_hours(): void
    {
        $schedule = ScheduleType::query()->create([
            'name' => 'Diurna',
            'code' => 'diurna',
            'active' => true,
        ]);
        $template = $this->createTemplate('Diurna 36h OFF non working day test', 'diurna', [8, 7, 7, 7, 7]);
        $period = PayrollPeriod::query()->create([
            'name' => 'Off fin de semana',
            'starts_at' => '2026-06-06',
            'ends_at' => '2026-06-06',
        ]);
        $employee = Employee::query()->create([
            'name' => 'Palmetto 36h weekend OFF',
            'schedule_type_id' => $schedule->id,
            'work_schedule_template_id' => $template->id,
            'ordinary_weekly_hours' => 36,
            'daily_hours' => 7,
            'hourly_rate' => 59.1667,
            'salary_calculation_method' => 'hourly_actual_hours',
        ]);

        $service = app(PayrollCalculationService::class);
        $service->generateDailyReviews($period);

        $review = DailyTimeReview::query()
            ->where('employee_id', $employee->id)
            ->where('payroll_period_id', $period->id)
            ->firstOrFail();

        $review->update([
            'paid_day_off' => true,
            'status' => 'revisado_supervisor',
        ]);

        $service->recalculateDailyReview($review);
        $service->recalculateEmployeePayrollResult($period, $employee);

        $this->assertDatabaseHas('daily_time_reviews', [
            'id' => $review->id,
            'scheduled_work_day' => false,
            'expected_ordinary_seconds' => 0,
            'paid_day_off' => true,
            'payable_seconds' => 25200,
            'unjustified_absence_seconds' => 0,
        ]);
        $this->assertDatabaseHas('payroll_results', [
            'employee_id' => $employee->id,
            'worked_days' => 1,
            'payable_seconds' => 25200,
            'worked_salary_amount' => 414.17,
            'lost_time_seconds' => 0,
        ]);
    }

    public function test_paid_day_off_for_rotating_schedule_pays_work_days_not_rest_days(): void
    {
        $schedule = ScheduleType::query()->create([
            'name' => 'Rotativa',
            'code' => 'rotativa',
            'active' => true,
        ]);
        $template = $this->createTemplate('Rotativa OFF test', 'rotativa', [11, 11, 11, 11]);
        $period = PayrollPeriod::query()->create([
            'name' => 'Off rotativo',
            'starts_at' => '2026-05-29',
            'ends_at' => '2026-05-30',
        ]);
        $employee = Employee::query()->create([
            'name' => 'Rotativo OFF',
            'schedule_type_id' => $schedule->id,
            'work_schedule_template_id' => $template->id,
            'schedule_cycle_anchor_date' => '2026-05-26',
            'rotation_work_days' => 4,
            'rotation_rest_days' => 4,
            'daily_hours' => 11,
            'hourly_rate' => 60,
            'salary_calculation_method' => 'hourly_actual_hours',
        ]);

        $service = app(PayrollCalculationService::class);
        $service->generateDailyReviews($period);

        DailyTimeReview::query()
            ->where('employee_id', $employee->id)
            ->where('payroll_period_id', $period->id)
            ->update([
                'paid_day_off' => true,
                'unjustified_absence_seconds' => 9999,
                'status' => 'revisado_supervisor',
            ]);

        DailyTimeReview::query()
            ->where('employee_id', $employee->id)
            ->where('payroll_period_id', $period->id)
            ->get()
            ->each(fn (DailyTimeReview $review) => $service->recalculateDailyReview($review));
        $service->recalculateEmployeePayrollResult($period, $employee);

        $this->assertDatabaseHas('daily_time_reviews', [
            'employee_id' => $employee->id,
            'date' => '2026-05-29 00:00:00',
            'scheduled_work_day' => true,
            'paid_day_off' => true,
            'payable_seconds' => 39600,
            'unjustified_absence_seconds' => 0,
        ]);
        $this->assertDatabaseHas('daily_time_reviews', [
            'employee_id' => $employee->id,
            'date' => '2026-05-30 00:00:00',
            'scheduled_work_day' => false,
            'paid_day_off' => true,
            'payable_seconds' => 0,
            'unjustified_absence_seconds' => 0,
        ]);
        $this->assertDatabaseHas('payroll_results', [
            'employee_id' => $employee->id,
            'payable_seconds' => 39600,
            'worked_salary_amount' => 660,
            'lost_time_seconds' => 0,
            'lost_time_amount' => 0,
        ]);
    }

    public function test_recalculate_preserving_manual_allows_paid_day_off_absence_cleanup(): void
    {
        $period = PayrollPeriod::query()->create([
            'name' => 'Off preservado',
            'starts_at' => '2026-06-01',
            'ends_at' => '2026-06-01',
        ]);
        $employee = Employee::query()->create([
            'name' => 'Empleado off preservado',
            'daily_hours' => 8,
            'hourly_rate' => 10,
            'active' => true,
        ]);
        $review = DailyTimeReview::query()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'date' => '2026-06-01',
            'expected_seconds' => 28800,
            'expected_ordinary_seconds' => 28800,
            'hubstaff_total_seconds' => 0,
            'paid_day_off' => true,
            'unjustified_absence_seconds' => 28800,
            'status' => 'revisado_supervisor',
            'supervisor_comment' => 'OFF autorizado',
        ]);

        app(PayrollCalculationService::class)->recalculatePeriodPreservingManual($period);

        $this->assertDatabaseHas('daily_time_reviews', [
            'id' => $review->id,
            'paid_day_off' => true,
            'unjustified_absence_seconds' => 0,
            'payable_seconds' => 28800,
            'status' => 'revisado_supervisor',
            'supervisor_comment' => 'OFF autorizado',
        ]);
        $this->assertDatabaseHas('payroll_results', [
            'employee_id' => $employee->id,
            'worked_salary_amount' => 80,
            'lost_time_seconds' => 0,
        ]);
    }

    public function test_existing_full_justified_absence_uses_updated_schedule_expected_hours(): void
    {
        $schedule = ScheduleType::query()->create([
            'name' => 'Diurna',
            'code' => 'diurna',
            'active' => true,
        ]);
        $template = $this->createTemplate('Diurna lunes 8h justificada test', 'diurna', [8, 7, 7, 7, 7]);
        $period = PayrollPeriod::query()->create([
            'name' => 'Justificación con plantilla nueva',
            'starts_at' => '2026-06-01',
            'ends_at' => '2026-06-01',
        ]);
        $employee = Employee::query()->create([
            'name' => 'Empleado ausencia justificada existente',
            'schedule_type_id' => $schedule->id,
            'work_schedule_template_id' => $template->id,
            'daily_hours' => 7.2,
            'hourly_rate' => 10,
            'overtime_hours' => 5,
        ]);
        $review = DailyTimeReview::query()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'date' => '2026-06-01',
            'expected_seconds' => 25920,
            'expected_ordinary_seconds' => 25920,
            'assigned_overtime_seconds' => 3600,
            'hubstaff_total_seconds' => 0,
            'justified_absence_seconds' => 25920,
            'unjustified_absence_seconds' => 0,
            'status' => 'revisado_supervisor',
        ]);

        $service = app(PayrollCalculationService::class);
        $service->recalculateDailyReview($review);
        $service->recalculateEmployeePayrollResult($period, $employee);

        $this->assertDatabaseHas('daily_time_reviews', [
            'id' => $review->id,
            'expected_ordinary_seconds' => 28800,
            'justified_absence_seconds' => 25920,
            'unjustified_absence_seconds' => 0,
            'payable_seconds' => 28800,
        ]);
        $this->assertDatabaseHas('payroll_results', [
            'employee_id' => $employee->id,
            'payable_seconds' => 28800,
            'worked_salary_amount' => 80,
            'overtime_seconds' => 0,
            'lost_time_seconds' => 0,
            'lost_time_amount' => 0,
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

    public function test_additional_deductions_are_classified_and_summed_with_automatic_deductions(): void
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
            'applies_ihss' => true,
            'applies_private_insurance' => true,
        ]);
        $ihss = DeductionType::query()->create([
            'name' => 'IHSS',
            'code' => 'ihss',
            'calculation_type' => 'fixed',
            'default_amount' => 5,
        ]);
        $privateInsurance = DeductionType::query()->create([
            'name' => 'Pan Ame Seguro',
            'code' => 'private_insurance',
            'calculation_type' => 'fixed',
            'default_amount' => 6,
        ]);
        $period->deductionTypes()->sync([$ihss->id, $privateInsurance->id]);

        foreach ([
            ['type' => 'ihss', 'amount' => 7, 'description' => 'IHSS adicional'],
            ['type' => 'private_insurance', 'amount' => 9, 'description' => 'Seguro adicional'],
            ['type' => 'adjustment', 'amount' => 11, 'description' => 'Ajuste cambio de tier'],
            ['type' => 'other', 'amount' => 13, 'description' => 'Otra deducción'],
        ] as $deduction) {
            EmployeeAdditionalDeduction::query()->create([
                'payroll_period_id' => $period->id,
                'employee_id' => $employee->id,
                ...$deduction,
                'active' => true,
            ]);
        }

        DailyTimeReview::query()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'date' => '2026-05-01',
            'expected_seconds' => 28800,
            'hubstaff_total_seconds' => 28800,
            'payable_seconds' => 28800,
        ]);

        app(PayrollCalculationService::class)->recalculatePayrollResults($period);

        $this->assertDatabaseHas('payroll_results', [
            'employee_id' => $employee->id,
            'private_insurance_amount' => 15,
            'ihss_amount' => 12,
            'tier_adjustment_deduction_amount' => 11,
            'other_deductions_amount' => 13,
            'total_deductions_amount' => 51,
            'net_amount' => 29,
        ]);
    }

    public function test_recalculation_removes_existing_results_for_inactive_employees(): void
    {
        $period = PayrollPeriod::query()->create([
            'name' => 'May 2026',
            'starts_at' => '2026-05-01',
            'ends_at' => '2026-05-15',
        ]);
        $employee = Employee::query()->create([
            'name' => 'Empleado inactivo',
            'active' => false,
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
        PayrollResult::query()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'net_amount' => 80,
        ]);

        app(PayrollCalculationService::class)->recalculatePayrollResults($period);

        $this->assertDatabaseMissing('payroll_results', [
            'employee_id' => $employee->id,
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
            'assigned_overtime_fulfilled' => false,
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
            'overtime_seconds' => 7200,
            'additional_overtime_seconds' => 3600,
            'overtime_amount' => 25,
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

    public function test_manual_overtime_is_paid_when_registered_even_without_hubstaff_excess(): void
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
            'overtime_seconds' => 7200,
            'additional_overtime_seconds' => 3600,
            'overtime_amount' => 25,
            'net_amount' => 105,
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
            'ordinary_weekly_hours' => 40,
            'daily_hours' => 10,
            'hourly_rate' => 10,
            'monthly_salary' => 2400,
            'overtime_hours' => 4,
            'preassigned_overtime_weekly_hours' => 4,
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
            'assigned_overtime_fulfilled' => false,
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
            'ordinary_weekly_hours' => 40,
            'daily_hours' => 10,
            'hourly_rate' => 10,
            'monthly_salary' => 2400,
            'overtime_hours' => 4,
            'preassigned_overtime_weekly_hours' => 4,
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

        $this->assertSame(0, (int) $review->unjustified_absence_seconds);
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
            'unjustified_absence_seconds' => 0,
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

    public function test_rotating_schedule_command_preserves_existing_manual_reviews(): void
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
            'status' => 'revisado_supervisor',
            'justified_absence_seconds' => 600,
            'supervisor_comment' => 'Se debe reiniciar',
            'expected_seconds' => 39600,
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
                'weekly_hours' => 44,
                'ordinary_weekly_hours' => 44,
                'daily_hours' => 11,
                'overtime_hours' => 4,
                'preassigned_overtime_weekly_hours' => 4,
                'monthly_salary' => 2400,
            ]);
        }

        $this->assertDatabaseHas('employees', [
            'id' => $targets->firstWhere('name', 'Elalf Shamir Dominguez Pineda')->id,
            'schedule_cycle_anchor_date' => '2026-05-25 00:00:00',
            'monthly_salary' => 2400,
        ]);
    }

    public function test_day_schedule_template_uses_exact_daily_pattern_instead_of_weekly_division(): void
    {
        $schedule = ScheduleType::query()->create([
            'name' => 'Diurna',
            'code' => 'diurna',
            'active' => true,
        ]);
        $fortyHourTemplate = $this->createTemplate('Diurna 40h test', 'diurna', [8, 8, 8, 8, 8]);
        $variableTemplate = $this->createTemplate('Diurna 36h test', 'diurna', [7, 7, 7, 7, 8]);
        $period = PayrollPeriod::query()->create([
            'name' => 'Patrones diurnos',
            'starts_at' => '2026-05-01',
            'ends_at' => '2026-05-04',
        ]);
        $fortyHourEmployee = Employee::query()->create([
            'name' => 'Diurno 40h',
            'schedule_type_id' => $schedule->id,
            'work_schedule_template_id' => $fortyHourTemplate->id,
            'ordinary_weekly_hours' => 40,
            'daily_hours' => 8,
        ]);
        $variableEmployee = Employee::query()->create([
            'name' => 'Diurno 36h',
            'schedule_type_id' => $schedule->id,
            'work_schedule_template_id' => $variableTemplate->id,
            'ordinary_weekly_hours' => 36,
            'daily_hours' => 0,
        ]);

        app(PayrollCalculationService::class)->generateDailyReviews($period);

        $this->assertDatabaseHas('daily_time_reviews', [
            'employee_id' => $fortyHourEmployee->id,
            'date' => '2026-05-01 00:00:00',
            'expected_ordinary_seconds' => 28800,
        ]);
        $this->assertDatabaseHas('daily_time_reviews', [
            'employee_id' => $variableEmployee->id,
            'date' => '2026-05-01 00:00:00',
            'expected_ordinary_seconds' => 28800,
        ]);
        $this->assertDatabaseHas('daily_time_reviews', [
            'employee_id' => $variableEmployee->id,
            'date' => '2026-05-04 00:00:00',
            'expected_ordinary_seconds' => 25200,
        ]);
    }

    public function test_rotating_four_by_four_only_expects_scheduled_work_days(): void
    {
        $schedule = ScheduleType::query()->create([
            'name' => 'Rotativa',
            'code' => 'rotativa',
            'active' => true,
        ]);
        $template = $this->createTemplate('Rotativa 4x4 test', 'rotativa', [11, 11, 11, 11]);
        $period = PayrollPeriod::query()->create([
            'name' => 'Ciclo 4x4',
            'starts_at' => '2026-05-26',
            'ends_at' => '2026-06-02',
        ]);
        $employee = Employee::query()->create([
            'name' => 'Rotativo 4x4',
            'schedule_type_id' => $schedule->id,
            'work_schedule_template_id' => $template->id,
            'schedule_cycle_anchor_date' => '2026-05-26',
            'rotation_work_days' => 4,
            'rotation_rest_days' => 4,
            'daily_hours' => 11,
        ]);

        app(PayrollCalculationService::class)->generateDailyReviews($period);

        $reviews = DailyTimeReview::query()
            ->where('employee_id', $employee->id)
            ->orderBy('date')
            ->get();

        $this->assertSame(4, $reviews->where('scheduled_work_day', true)->count());
        $this->assertSame(4, $reviews->where('scheduled_work_day', false)->count());
        $this->assertSame(158400, (int) $reviews->sum('expected_ordinary_seconds'));
    }

    public function test_employee_schedule_assignment_overrides_employee_fallback_for_its_date_range(): void
    {
        $schedule = ScheduleType::query()->create([
            'name' => 'Diurna',
            'code' => 'diurna',
            'active' => true,
        ]);
        $template = $this->createTemplate('Asignación 36h test', 'diurna', [7, 7, 7, 7, 8]);
        $period = PayrollPeriod::query()->create([
            'name' => 'Asignación histórica',
            'starts_at' => '2026-05-04',
            'ends_at' => '2026-05-04',
        ]);
        $employee = Employee::query()->create([
            'name' => 'Empleado con asignación',
            'schedule_type_id' => $schedule->id,
            'daily_hours' => 8,
        ]);
        $employee->scheduleAssignments()->create([
            'work_schedule_template_id' => $template->id,
            'starts_at' => '2026-05-01',
            'ends_at' => '2026-05-31',
            'active' => true,
        ]);

        app(PayrollCalculationService::class)->generateDailyReviews($period);

        $this->assertDatabaseHas('daily_time_reviews', [
            'employee_id' => $employee->id,
            'date' => '2026-05-04 00:00:00',
            'expected_ordinary_seconds' => 25200,
        ]);
    }

    public function test_employee_schedule_transition_command_splits_rotative_and_diurnal_dates(): void
    {
        $diurnalSchedule = ScheduleType::query()->create([
            'name' => 'Diurna',
            'code' => 'diurna',
            'active' => true,
        ]);
        $rotativeSchedule = ScheduleType::query()->create([
            'name' => 'Rotativa',
            'code' => 'rotativa',
            'active' => true,
        ]);
        $diurnalTemplate = $this->createTemplate('Diurna 40h - 5 días x 8h', 'diurna', [8, 8, 8, 8, 8]);
        $rotativeTemplate = $this->createTemplate('Rotativa 4x4', 'rotativa', [11, 11, 11, 11]);
        $period = PayrollPeriod::query()->create([
            'name' => 'Junio 11-16',
            'starts_at' => '2026-06-11',
            'ends_at' => '2026-06-20',
        ]);
        $employee = Employee::query()->create([
            'name' => 'Elalf Shamir Dominguez Pineda',
            'schedule_type_id' => $rotativeSchedule->id,
            'work_schedule_template_id' => $rotativeTemplate->id,
            'schedule_cycle_anchor_date' => '2026-06-11',
            'rotation_work_days' => 4,
            'rotation_rest_days' => 4,
            'weekly_hours' => 44,
            'ordinary_weekly_hours' => 44,
            'daily_hours' => 11,
            'preassigned_overtime_weekly_hours' => 4,
            'salary_calculation_method' => 'semi_monthly_fixed_with_deductions',
            'hourly_rate' => 10,
        ]);
        HubstaffTimeEntry::query()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'hubstaff_member' => $employee->name,
            'date' => '2026-06-15',
            'project' => 'Operations',
            'regular_seconds' => 32400,
            'total_seconds' => 32400,
        ]);
        HubstaffTimeEntry::query()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'hubstaff_member' => $employee->name,
            'date' => '2026-06-20',
            'project' => 'Operations',
            'regular_seconds' => 28800,
            'total_seconds' => 28800,
        ]);

        $exitCode = Artisan::call('payroll:apply-employee-schedule-transition', [
            '--period' => $period->id,
            '--employee' => $employee->name,
            '--rotative-start' => '2026-06-11',
            '--rotative-end' => '2026-06-13',
            '--diurnal-start' => '2026-06-14',
            '--apply' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertDatabaseHas('employee_schedule_assignments', [
            'employee_id' => $employee->id,
            'work_schedule_template_id' => $rotativeTemplate->id,
            'starts_at' => '2026-06-11 00:00:00',
            'ends_at' => '2026-06-13 00:00:00',
        ]);
        $this->assertDatabaseHas('employee_schedule_assignments', [
            'employee_id' => $employee->id,
            'work_schedule_template_id' => $diurnalTemplate->id,
            'starts_at' => '2026-06-14 00:00:00',
            'ends_at' => null,
        ]);
        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'schedule_type_id' => $diurnalSchedule->id,
            'work_schedule_template_id' => $diurnalTemplate->id,
            'ordinary_weekly_hours' => 40,
            'daily_hours' => 8,
            'preassigned_overtime_weekly_hours' => 5,
        ]);
        $this->assertDatabaseHas('daily_time_reviews', [
            'employee_id' => $employee->id,
            'date' => '2026-06-11 00:00:00',
            'expected_ordinary_seconds' => 39600,
            'preassigned_overtime_seconds' => 0,
        ]);
        $this->assertDatabaseHas('daily_time_reviews', [
            'employee_id' => $employee->id,
            'date' => '2026-06-15 00:00:00',
            'expected_ordinary_seconds' => 28800,
            'preassigned_overtime_seconds' => 3600,
            'expected_paid_seconds' => 32400,
        ]);
        $this->assertDatabaseHas('daily_time_reviews', [
            'employee_id' => $employee->id,
            'date' => '2026-06-20 00:00:00',
            'scheduled_work_day' => false,
            'expected_ordinary_seconds' => 0,
            'preassigned_overtime_seconds' => 0,
            'hubstaff_total_seconds' => 28800,
            'payable_seconds' => 28800,
            'unjustified_absence_seconds' => 0,
        ]);
    }

    public function test_rotating_fixed_salary_uses_employee_values_and_preassigned_overtime(): void
    {
        [$period, $employee] = $this->createRotatingFixedSalaryScenario(
            name: 'Elalf test',
            monthlySalary: 14200,
            semiMonthlySalary: 7100,
            hourlyRate: 59.20,
            overtimeRate: 74.00,
        );

        app(PayrollCalculationService::class)->recalculatePeriodPreservingManual($period);

        $this->assertDatabaseHas('payroll_results', [
            'employee_id' => $employee->id,
            'salary_calculation_method' => 'semi_monthly_fixed_with_deductions',
            'scheduled_days' => 8,
            'worked_salary_amount' => 7100,
            'preassigned_overtime_seconds' => 28800,
            'overtime_amount' => 592,
            'gross_amount' => 7692,
            'lost_time_seconds' => 0,
        ]);
    }

    public function test_rotating_fixed_salary_never_uses_another_employees_salary(): void
    {
        [$firstPeriod] = $this->createRotatingFixedSalaryScenario(
            name: 'Elalf reference',
            monthlySalary: 14200,
            semiMonthlySalary: 7100,
            hourlyRate: 59.20,
            overtimeRate: 74.00,
            periodName: 'Primer rotativo',
            startsAt: '2026-05-01',
            endsAt: '2026-05-16',
        );
        [$secondPeriod, $employee] = $this->createRotatingFixedSalaryScenario(
            name: 'Rotativo salario distinto',
            monthlySalary: 16500,
            semiMonthlySalary: 8250,
            hourlyRate: 68.75,
            overtimeRate: 85.9375,
            periodName: 'Segundo rotativo',
            startsAt: '2026-06-01',
            endsAt: '2026-06-16',
        );

        $service = app(PayrollCalculationService::class);
        $service->recalculatePeriodPreservingManual($firstPeriod);
        $service->recalculatePeriodPreservingManual($secondPeriod);

        $this->assertDatabaseHas('payroll_results', [
            'employee_id' => $employee->id,
            'monthly_salary' => 16500,
            'biweekly_salary_amount' => 8250,
            'worked_salary_amount' => 8250,
            'overtime_amount' => 687.5,
            'gross_amount' => 8937.5,
        ]);
    }

    public function test_paid_lunch_not_tracked_is_added_once_to_rotating_payable_time(): void
    {
        [$period, $employee] = $this->createRotatingFixedSalaryScenario(
            name: 'Rotativo lunch',
            monthlySalary: 12000,
            semiMonthlySalary: 6000,
            hourlyRate: 50,
            overtimeRate: 62.5,
            periodName: 'Un día rotativo',
            startsAt: '2026-05-26',
            endsAt: '2026-05-26',
            preassignedPeriodHours: 1,
        );

        app(PayrollCalculationService::class)->recalculatePeriodPreservingManual($period);

        $this->assertDatabaseHas('daily_time_reviews', [
            'employee_id' => $employee->id,
            'hubstaff_total_seconds' => 39600,
            'paid_time_not_tracked_seconds' => 3600,
            'expected_hubstaff_seconds' => 39600,
            'expected_paid_seconds' => 43200,
            'payable_seconds' => 43200,
        ]);
    }

    public function test_justified_rotating_absence_pays_only_ordinary_time_without_lunch_or_overtime(): void
    {
        [$period, $employee] = $this->createRotatingFixedSalaryScenario(
            name: 'Rotativo ausencia justificada',
            monthlySalary: 14200,
            semiMonthlySalary: 7100,
            hourlyRate: 59.20,
            overtimeRate: 74,
            periodName: 'Ausencia rotativa',
            startsAt: '2026-05-26',
            endsAt: '2026-05-26',
            preassignedPeriodHours: 1,
            createHubstaffEntries: false,
        );

        $service = app(PayrollCalculationService::class);
        $service->generateDailyReviews($period);
        $review = DailyTimeReview::query()
            ->where('employee_id', $employee->id)
            ->where('payroll_period_id', $period->id)
            ->firstOrFail();
        $review->update([
            'justified_absence_seconds' => 39600,
            'unjustified_absence_seconds' => 0,
            'status' => 'revisado_supervisor',
        ]);

        $service->recalculateDailyReview($review->fresh());
        $service->recalculateEmployeePayrollResult($period, $employee);

        $this->assertDatabaseHas('daily_time_reviews', [
            'id' => $review->id,
            'paid_time_not_tracked_seconds' => 3600,
            'payable_seconds' => 39600,
        ]);
        $this->assertDatabaseHas('payroll_results', [
            'employee_id' => $employee->id,
            'worked_salary_amount' => 7100,
            'preassigned_overtime_seconds' => 0,
            'overtime_amount' => 0,
            'lost_time_seconds' => 0,
        ]);
    }

    public function test_fractional_preassigned_overtime_is_paid_proportionally(): void
    {
        $period = PayrollPeriod::query()->create([
            'name' => 'Extra fraccionada',
            'starts_at' => '2026-05-04',
            'ends_at' => '2026-05-04',
        ]);
        $employee = Employee::query()->create([
            'name' => 'Empleado media hora extra',
            'daily_hours' => 8,
            'ordinary_weekly_hours' => 40,
            'preassigned_overtime_weekly_hours' => 0.5,
            'hourly_rate' => 10,
            'overtime_hourly_rate' => 12.5,
        ]);
        HubstaffTimeEntry::query()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'hubstaff_member' => $employee->name,
            'date' => '2026-05-04',
            'total_seconds' => 30600,
        ]);

        app(PayrollCalculationService::class)->recalculatePeriodPreservingManual($period);

        $this->assertDatabaseHas('payroll_results', [
            'employee_id' => $employee->id,
            'preassigned_overtime_seconds' => 1800,
            'overtime_amount' => 6.25,
        ]);
    }

    public function test_recalculate_period_command_preserves_all_existing_manual_records(): void
    {
        $period = PayrollPeriod::query()->create([
            'name' => 'Período editado',
            'starts_at' => '2026-05-01',
            'ends_at' => '2026-05-01',
            'status' => 'en_revision',
        ]);
        $employee = Employee::query()->create([
            'name' => 'Empleado revisado',
            'daily_hours' => 8,
            'hourly_rate' => 10,
        ]);
        $entry = HubstaffTimeEntry::query()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'hubstaff_member' => $employee->name,
            'date' => '2026-05-01',
            'total_seconds' => 27000,
            'idle_seconds' => 600,
        ]);

        $service = app(PayrollCalculationService::class);
        $service->generateDailyReviews($period);
        $review = DailyTimeReview::query()->firstOrFail();
        $supervisor = User::query()->create([
            'name' => 'Supervisor prueba',
            'email' => 'supervisor-preserve@example.com',
            'password' => 'password',
            'profile' => 'supervisor',
        ]);
        $rrhh = User::query()->create([
            'name' => 'RRHH prueba',
            'email' => 'rrhh-preserve@example.com',
            'password' => 'password',
            'profile' => 'rrhh',
        ]);
        $review->update([
            'justified_idle_seconds' => 300,
            'unjustified_idle_seconds' => 300,
            'justified_absence_seconds' => 1800,
            'unjustified_absence_seconds' => 0,
            'approved_overtime_seconds' => 900,
            'supervisor_comment' => 'Validado por supervisor',
            'rrhh_comment' => 'Aprobado por RRHH',
            'status' => 'aprobado_rrhh',
            'reviewed_by' => $supervisor->id,
            'approved_by' => $rrhh->id,
        ]);
        $bonus = PayrollBonus::query()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'scope_type' => 'employee',
            'type' => 'manual',
            'amount' => 75,
            'status' => 'aprobado',
            'description' => 'Bono manual preservado',
        ]);
        $deductionType = DeductionType::query()->create([
            'name' => 'Deducción manual',
            'code' => 'manual_preserved',
            'calculation_type' => 'fixed',
        ]);
        $deduction = PayrollDeduction::query()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'deduction_type_id' => $deductionType->id,
            'amount' => 25,
            'status' => 'aprobado',
            'description' => 'Deducción preservada',
        ]);
        $reviewState = $review->fresh()->getAttributes();
        $entryState = $entry->fresh()->getAttributes();
        $bonusState = $bonus->fresh()->getAttributes();
        $deductionState = $deduction->fresh()->getAttributes();

        $exitCode = Artisan::call('payroll:recalculate-period', [
            'period_id' => $period->id,
            '--preserve-manual' => true,
        ]);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame($entryState, $entry->fresh()->getAttributes());
        $this->assertSame($bonusState, $bonus->fresh()->getAttributes());
        $this->assertSame($deductionState, $deduction->fresh()->getAttributes());

        foreach ([
            'justified_idle_seconds',
            'unjustified_idle_seconds',
            'justified_absence_seconds',
            'unjustified_absence_seconds',
            'approved_overtime_seconds',
            'supervisor_comment',
            'rrhh_comment',
            'status',
            'reviewed_by',
            'approved_by',
        ] as $field) {
            $this->assertSame($reviewState[$field], $review->fresh()->getAttribute($field));
        }
    }

    public function test_palmetto_debt_collections_command_assigns_employee_specific_36h_templates(): void
    {
        $campaign = Campaign::query()->create(['name' => 'PALMETTO']);
        $debtTeam = Team::query()->create(['name' => 'DEBT COLLECTIONS', 'campaign_id' => $campaign->id]);
        $otherTeam = Team::query()->create(['name' => 'BDC', 'campaign_id' => $campaign->id]);
        $schedule = ScheduleType::query()->create([
            'name' => 'Diurna',
            'code' => 'diurna',
            'active' => true,
        ]);
        $period = PayrollPeriod::query()->create([
            'name' => 'Palmetto 36h',
            'starts_at' => '2026-06-01',
            'ends_at' => '2026-06-05',
            'status' => 'en_revision',
        ]);
        $mondayEmployee = Employee::query()->create([
            'name' => 'Iris Lunes',
            'campaign_id' => $campaign->id,
            'team_id' => $debtTeam->id,
            'schedule_type_id' => $schedule->id,
            'weekly_hours' => 36,
            'ordinary_weekly_hours' => 36,
            'daily_hours' => 7.2,
            'hourly_rate' => 10,
            'active' => true,
        ]);
        $wednesdayEmployee = Employee::query()->create([
            'name' => 'Carlos Miércoles',
            'campaign_id' => $campaign->id,
            'team_id' => $debtTeam->id,
            'schedule_type_id' => $schedule->id,
            'weekly_hours' => 36,
            'ordinary_weekly_hours' => 36,
            'daily_hours' => 7.2,
            'hourly_rate' => 10,
            'active' => true,
        ]);
        $fortyHourEmployee = Employee::query()->create([
            'name' => 'Empleado 40h',
            'campaign_id' => $campaign->id,
            'team_id' => $debtTeam->id,
            'schedule_type_id' => $schedule->id,
            'weekly_hours' => 40,
            'ordinary_weekly_hours' => 40,
            'daily_hours' => 8,
            'hourly_rate' => 10,
            'active' => true,
        ]);
        $otherTeamEmployee = Employee::query()->create([
            'name' => 'Empleado otro team',
            'campaign_id' => $campaign->id,
            'team_id' => $otherTeam->id,
            'schedule_type_id' => $schedule->id,
            'weekly_hours' => 36,
            'ordinary_weekly_hours' => 36,
            'daily_hours' => 7.2,
            'hourly_rate' => 10,
            'active' => true,
        ]);

        foreach (CarbonPeriod::create('2026-06-01', '2026-06-05') as $date) {
            HubstaffTimeEntry::query()->create([
                'payroll_period_id' => $period->id,
                'employee_id' => $mondayEmployee->id,
                'hubstaff_member' => $mondayEmployee->name,
                'date' => $date->toDateString(),
                'total_seconds' => $date->dayOfWeekIso === 1 ? 28800 : 25200,
            ]);
            HubstaffTimeEntry::query()->create([
                'payroll_period_id' => $period->id,
                'employee_id' => $wednesdayEmployee->id,
                'hubstaff_member' => $wednesdayEmployee->name,
                'date' => $date->toDateString(),
                'total_seconds' => $date->dayOfWeekIso === 3 ? 28800 : 25200,
            ]);
            HubstaffTimeEntry::query()->create([
                'payroll_period_id' => $period->id,
                'employee_id' => $fortyHourEmployee->id,
                'hubstaff_member' => $fortyHourEmployee->name,
                'date' => $date->toDateString(),
                'total_seconds' => 28800,
            ]);
            HubstaffTimeEntry::query()->create([
                'payroll_period_id' => $period->id,
                'employee_id' => $otherTeamEmployee->id,
                'hubstaff_member' => $otherTeamEmployee->name,
                'date' => $date->toDateString(),
                'total_seconds' => $date->dayOfWeekIso === 2 ? 28800 : 25200,
            ]);
        }

        $service = app(PayrollCalculationService::class);
        $service->generateDailyReviews($period);
        $manualReview = DailyTimeReview::query()
            ->where('employee_id', $mondayEmployee->id)
            ->whereDate('date', '2026-06-02')
            ->firstOrFail();
        $manualReview->update([
            'status' => 'revisado_supervisor',
            'justified_absence_seconds' => 900,
            'supervisor_comment' => 'Justificación ya revisada',
        ]);

        $previewExitCode = Artisan::call('payroll:apply-palmetto-36h-schedules', [
            '--period' => $period->id,
        ]);
        $this->assertSame(0, $previewExitCode, Artisan::output());

        $applyExitCode = Artisan::call('payroll:apply-palmetto-36h-schedules', [
            '--period' => $period->id,
            '--apply' => true,
        ]);
        $this->assertSame(0, $applyExitCode, Artisan::output());

        $this->assertSame('Diurna 36h - lunes 8h', $mondayEmployee->fresh('workScheduleTemplate')->workScheduleTemplate?->name);
        $this->assertSame('Diurna 36h - miércoles 8h', $wednesdayEmployee->fresh('workScheduleTemplate')->workScheduleTemplate?->name);
        $this->assertNull($fortyHourEmployee->fresh()->work_schedule_template_id);
        $this->assertNull($otherTeamEmployee->fresh()->work_schedule_template_id);
        $this->assertDatabaseHas('daily_time_reviews', [
            'employee_id' => $mondayEmployee->id,
            'date' => '2026-06-01 00:00:00',
            'expected_ordinary_seconds' => 28800,
        ]);
        $this->assertDatabaseHas('daily_time_reviews', [
            'employee_id' => $mondayEmployee->id,
            'date' => '2026-06-02 00:00:00',
            'expected_ordinary_seconds' => 25200,
            'justified_absence_seconds' => 900,
            'status' => 'revisado_supervisor',
            'supervisor_comment' => 'Justificación ya revisada',
        ]);
        $this->assertDatabaseHas('daily_time_reviews', [
            'employee_id' => $wednesdayEmployee->id,
            'date' => '2026-06-03 00:00:00',
            'expected_ordinary_seconds' => 28800,
        ]);
    }

    public function test_palmetto_debt_collections_command_can_skip_employees_without_hubstaff_inference(): void
    {
        $campaign = Campaign::query()->create(['name' => 'PALMETTO']);
        $team = Team::query()->create(['name' => 'DEBT COLLECTIONS', 'campaign_id' => $campaign->id]);
        $schedule = ScheduleType::query()->create([
            'name' => 'Diurna',
            'code' => 'diurna',
            'active' => true,
        ]);
        $period = PayrollPeriod::query()->create([
            'name' => 'Palmetto skip',
            'starts_at' => '2026-06-01',
            'ends_at' => '2026-06-05',
            'status' => 'en_revision',
        ]);
        $inferred = Employee::query()->create([
            'name' => 'Empleado inferido',
            'campaign_id' => $campaign->id,
            'team_id' => $team->id,
            'schedule_type_id' => $schedule->id,
            'weekly_hours' => 36,
            'ordinary_weekly_hours' => 36,
            'daily_hours' => 7.2,
            'hourly_rate' => 10,
            'active' => true,
        ]);
        $notInferred = Employee::query()->create([
            'name' => 'Empleado sin Hubstaff',
            'campaign_id' => $campaign->id,
            'team_id' => $team->id,
            'schedule_type_id' => $schedule->id,
            'weekly_hours' => 36,
            'ordinary_weekly_hours' => 36,
            'daily_hours' => 7.2,
            'hourly_rate' => 10,
            'active' => true,
        ]);

        foreach (CarbonPeriod::create('2026-06-01', '2026-06-05') as $date) {
            HubstaffTimeEntry::query()->create([
                'payroll_period_id' => $period->id,
                'employee_id' => $inferred->id,
                'hubstaff_member' => $inferred->name,
                'date' => $date->toDateString(),
                'total_seconds' => $date->dayOfWeekIso === 2 ? 28800 : 25200,
            ]);
        }

        app(PayrollCalculationService::class)->generateDailyReviews($period);

        $applyExitCode = Artisan::call('payroll:apply-palmetto-36h-schedules', [
            '--period' => $period->id,
            '--apply' => true,
            '--skip-uninferred' => true,
        ]);

        $this->assertSame(0, $applyExitCode, Artisan::output());
        $this->assertSame('Diurna 36h - martes 8h', $inferred->fresh('workScheduleTemplate')->workScheduleTemplate?->name);
        $this->assertNull($notInferred->fresh()->work_schedule_template_id);
    }

    /**
     * @param  array<int, float|int>  $hours
     */
    private function createTemplate(string $name, string $scheduleType, array $hours): WorkScheduleTemplate
    {
        $template = WorkScheduleTemplate::query()->create([
            'name' => $name,
            'schedule_type' => $scheduleType,
            'active' => true,
        ]);

        foreach ($hours as $index => $dailyHours) {
            $template->days()->create([
                'day_number' => $index + 1,
                'expected_seconds' => (int) round($dailyHours * 3600),
                'is_working_day' => true,
            ]);
        }

        return $template;
    }

    /**
     * @return array{PayrollPeriod, Employee}
     */
    private function createRotatingFixedSalaryScenario(
        string $name,
        float $monthlySalary,
        float $semiMonthlySalary,
        float $hourlyRate,
        float $overtimeRate,
        string $periodName = 'Rotativo fijo',
        string $startsAt = '2026-05-26',
        string $endsAt = '2026-06-10',
        float $preassignedPeriodHours = 8,
        bool $createHubstaffEntries = true,
    ): array {
        $schedule = ScheduleType::query()->firstOrCreate(
            ['code' => 'rotativa'],
            ['name' => 'Rotativa', 'active' => true],
        );
        $template = WorkScheduleTemplate::query()->firstOrCreate(
            ['name' => 'Rotativa 4x4 fixture'],
            ['schedule_type' => 'rotativa', 'active' => true],
        );

        if ($template->days()->doesntExist()) {
            foreach (range(1, 4) as $dayNumber) {
                $template->days()->create([
                    'day_number' => $dayNumber,
                    'expected_seconds' => 39600,
                    'is_working_day' => true,
                ]);
            }
        }

        $period = PayrollPeriod::query()->create([
            'name' => $periodName,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'status' => 'en_revision',
        ]);
        $employee = Employee::query()->create([
            'name' => $name,
            'schedule_type_id' => $schedule->id,
            'work_schedule_template_id' => $template->id,
            'schedule_cycle_anchor_date' => $startsAt,
            'rotation_work_days' => 4,
            'rotation_rest_days' => 4,
            'ordinary_weekly_hours' => 44,
            'daily_hours' => 11,
            'preassigned_overtime_weekly_hours' => 4,
            'preassigned_overtime_period_hours' => $preassignedPeriodHours,
            'salary_calculation_method' => 'semi_monthly_fixed_with_deductions',
            'monthly_salary' => $monthlySalary,
            'semi_monthly_salary' => $semiMonthlySalary,
            'hourly_rate' => $hourlyRate,
            'overtime_hourly_rate' => $overtimeRate,
            'hubstaff_expected_hours_per_workday' => 11,
            'paid_hours_per_workday' => 12,
            'paid_lunch_minutes_per_workday' => 60,
            'lunch_included_in_hubstaff_total' => false,
            'breaks_included_in_hubstaff_total' => true,
        ]);

        foreach (CarbonPeriod::create($startsAt, $endsAt) as $date) {
            $cycleDay = $date->diffInDays(Carbon::parse($startsAt)) % 8;

            if (! $createHubstaffEntries || $cycleDay >= 4) {
                continue;
            }

            HubstaffTimeEntry::query()->create([
                'payroll_period_id' => $period->id,
                'employee_id' => $employee->id,
                'hubstaff_member' => $employee->name,
                'date' => $date->toDateString(),
                'total_seconds' => 39600,
            ]);
        }

        return [$period, $employee];
    }
}
