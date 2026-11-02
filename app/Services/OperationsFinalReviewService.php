<?php

namespace App\Services;

use App\Models\DailyTimeReview;
use App\Models\HubstaffTimeEntry;
use App\Models\PayrollPeriod;
use App\Models\TrackabiDailyActivity;
use App\Models\TrackingImportAlert;
use App\Models\User;
use App\Services\Trackabi\TrackabiActivityCoverageService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class OperationsFinalReviewService
{
    public function __construct(private readonly TrackabiActivityCoverageService $coverage) {}

    public function required(PayrollPeriod $period): bool
    {
        return config('trackabi.provider') === 'direct'
            && $period->starts_at->toDateString() >= (string) config('trackabi_direct.operations_final_review_from_date');
    }

    public function pendingReviews(PayrollPeriod $period): int
    {
        return DailyTimeReview::query()->where('payroll_period_id', $period->id)
            ->where('status', 'pendiente')->count();
    }

    public function isCurrent(PayrollPeriod $period): bool
    {
        return $period->operations_final_reviewed_by !== null
            && $period->operations_final_review_fingerprint !== null
            && hash_equals($period->operations_final_review_fingerprint, $this->fingerprint($period));
    }

    public function approve(PayrollPeriod $period, User $reviewer): void
    {
        if (! $reviewer->can_final_review_operations || ! $reviewer->isRrhh()) {
            throw new RuntimeException('El usuario no tiene permiso de revisión final de Operaciones.');
        }

        DB::transaction(function () use ($period, $reviewer): void {
            $locked = PayrollPeriod::query()->lockForUpdate()->findOrFail($period->id);
            if (! $this->required($locked) || ! in_array($locked->status, ['borrador', 'importado', 'en_revision'], true)) {
                throw new RuntimeException('La revisión final solo aplica a un período Trackabi abierto.');
            }
            if (! DailyTimeReview::query()->where('payroll_period_id', $locked->id)->exists()) {
                throw new RuntimeException('No hay revisiones diarias para aprobar en este período.');
            }
            $pending = $this->pendingReviews($locked);
            $coverage = $this->coverage->summary($locked);
            if ($pending > 0 || $coverage['missing'] > 0 || $coverage['legacy_historical'] > 0) {
                throw new RuntimeException("No se puede aprobar: {$pending} revisiones pendientes y {$coverage['missing']} días sin actividad.");
            }
            $unresolvedData = TrackingImportAlert::query()->where('payroll_period_id', $locked->id)
                ->where('resolved', false)->whereIn('code', [
                    'TRACKABI_SUSPICIOUS_LOGGED_TIME', 'TRACKABI_DIRECT_DATA_AFTER_REVIEW',
                    'TRACKABI_ACTIVITY_DATA_AFTER_REVIEW',
                ])->count();
            if ($unresolvedData > 0) {
                throw new RuntimeException("Hay {$unresolvedData} alertas de datos Trackabi pendientes de resolver.");
            }

            $locked->update([
                'operations_final_reviewed_by' => $reviewer->id,
                'operations_final_reviewed_at' => now(),
                'operations_final_review_fingerprint' => $this->fingerprint($locked),
            ]);
        });
    }

    private function fingerprint(PayrollPeriod $period): string
    {
        $reviews = DailyTimeReview::query()->where('payroll_period_id', $period->id)->orderBy('id')
            ->get(['id', 'status', 'paid_day_off', 'justified_absence_seconds',
                'supervisor_adjustment_seconds', 'unjustified_absence_seconds', 'payable_seconds',
                'expected_hubstaff_seconds', 'preassigned_overtime_seconds', 'lost_time_source'])
            ->toArray();
        $activity = TrackabiDailyActivity::query()->where('payroll_period_id', $period->id)->orderBy('id')
            ->get(['id', 'measured_activity_seconds', 'productive_activity_seconds', 'unproductive_activity_seconds'])
            ->toArray();
        $entries = HubstaffTimeEntry::query()->where('payroll_period_id', $period->id)->orderBy('id')
            ->get(['id', 'active', 'total_seconds', 'trackabi_project_id'])->toArray();
        $alerts = TrackingImportAlert::query()->where('payroll_period_id', $period->id)
            ->where('resolved', false)->orderBy('id')->pluck('id')->all();

        return hash('sha256', json_encode([$reviews, $activity, $entries, $alerts], JSON_THROW_ON_ERROR));
    }
}
