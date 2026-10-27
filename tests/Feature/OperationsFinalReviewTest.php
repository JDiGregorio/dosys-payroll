<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\DailyTimeReview;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\TrackabiActivityImportBatch;
use App\Models\TrackabiDailyActivity;
use App\Models\User;
use App\Services\OperationsFinalReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationsFinalReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_final_review_requires_permission_and_completed_reviews_and_becomes_stale_after_change(): void
    {
        config(['trackabi.provider' => 'direct', 'trackabi_direct.cutover_date' => '2026-09-26']);
        $period = PayrollPeriod::query()->create([
            'name' => 'Periodo 11', 'starts_at' => '2026-10-11', 'ends_at' => '2026-10-25', 'status' => 'en_revision',
        ]);
        $campaign = Campaign::query()->create(['name' => 'PALMETTO']);
        $employee = Employee::query()->create([
            'name' => 'Agent', 'campaign_id' => $campaign->id, 'daily_hours' => 8, 'active' => true,
        ]);
        $review = DailyTimeReview::query()->create([
            'payroll_period_id' => $period->id, 'employee_id' => $employee->id,
            'date' => '2026-10-21', 'status' => 'pendiente', 'scheduled_work_day' => true,
            'expected_ordinary_seconds' => 28800, 'expected_hubstaff_seconds' => 28800,
        ]);
        $batch = TrackabiActivityImportBatch::query()->create([
            'payroll_period_id' => $period->id, 'activity_date' => '2026-10-21',
            'filename' => 'test.csv', 'file_hash' => str_repeat('e', 64),
            'rows' => 1, 'matched' => 1, 'unmatched' => 0, 'imported_at' => now(),
        ]);
        TrackabiDailyActivity::query()->create([
            'employee_id' => $employee->id, 'payroll_period_id' => $period->id,
            'import_batch_id' => $batch->id, 'activity_date' => '2026-10-21',
            'productive_activity_seconds' => 25200, 'unproductive_activity_seconds' => 0,
            'measured_activity_seconds' => 25200, 'imported_at' => now(),
        ]);
        $reviewer = User::factory()->create([
            'profile' => 'rrhh', 'can_final_review_operations' => true,
        ]);
        $service = app(OperationsFinalReviewService::class);
        try {
            $service->approve($period, $reviewer);
            $this->fail('Una revisión pendiente no puede aprobarse.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('revisiones pendientes', $exception->getMessage());
        }
        $review->update(['status' => 'revisado_supervisor']);
        $other = User::factory()->create(['profile' => 'rrhh', 'can_final_review_operations' => false]);
        $this->expectException(\RuntimeException::class);
        $service->approve($period, $other);
    }

    public function test_approval_records_reviewer_and_invalidates_when_daily_decision_changes(): void
    {
        config(['trackabi.provider' => 'direct', 'trackabi_direct.cutover_date' => '2026-09-26']);
        $period = PayrollPeriod::query()->create([
            'name' => 'Periodo 11', 'starts_at' => '2026-10-11', 'ends_at' => '2026-10-25', 'status' => 'en_revision',
        ]);
        $reviewer = User::factory()->create(['profile' => 'rrhh', 'can_final_review_operations' => true]);
        $campaign = Campaign::query()->create(['name' => 'PALMETTO']);
        $employee = Employee::query()->create(['name' => 'Agent', 'campaign_id' => $campaign->id, 'active' => true]);
        $review = DailyTimeReview::query()->create([
            'payroll_period_id' => $period->id, 'employee_id' => $employee->id,
            'date' => '2026-10-21', 'status' => 'revisado_supervisor',
        ]);
        $service = app(OperationsFinalReviewService::class);
        $service->approve($period, $reviewer);
        $this->assertSame($reviewer->id, $period->fresh()->operations_final_reviewed_by);
        $this->assertTrue($service->isCurrent($period->fresh()));

        $review->update(['justified_absence_seconds' => 600]);
        $this->assertFalse($service->isCurrent($period->fresh()));
    }
}
