<?php

namespace App\Filament\Pages;

use App\Filament\Resources\DailyTimeReviews\DailyTimeReviewResource;
use App\Models\DailyTimeReview;
use App\Models\HubstaffTimeEntry;
use App\Models\PayrollPeriod;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ImportAlerts extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?string $navigationLabel = 'Alertas de importación';

    protected static ?string $title = 'Alertas de importación';

    protected static string|\UnitEnum|null $navigationGroup = 'Planilla';

    protected static ?int $navigationSort = 20;

    protected string $view = 'filament.pages.import-alerts';

    public ?int $periodId = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return (bool) $user?->active && ($user->isRrhh() || $user->isSupervisor());
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        $this->periodId = PayrollPeriod::query()->open()->latest('starts_at')->value('id');
    }

    public function periods(): Collection
    {
        return PayrollPeriod::query()->open()->orderByDesc('starts_at')->get();
    }

    public function selectedPeriod(): ?PayrollPeriod
    {
        return $this->periodId ? PayrollPeriod::query()->find($this->periodId) : null;
    }

    public function unmappedMembers(): Collection
    {
        if (! $this->periodId || ! auth()->user()?->isRrhh()) {
            return collect();
        }

        return HubstaffTimeEntry::query()
            ->when($this->periodId, fn ($query) => $query->where('payroll_period_id', $this->periodId))
            ->where('active', true)
            ->whereNull('employee_id')
            ->select('hubstaff_member')
            ->distinct()
            ->orderBy('hubstaff_member')
            ->limit(50)
            ->pluck('hubstaff_member');
    }

    public function shortPayableDays(): Collection
    {
        return $this->visibleReviewsQuery()
            ->whereColumn('payable_seconds', '<', 'expected_paid_seconds')
            ->where('unjustified_absence_seconds', '>', 0)
            ->orderBy('date')
            ->limit(50)
            ->get();
    }

    public function highIdleDays(): Collection
    {
        return $this->visibleReviewsQuery()
            ->where('hubstaff_idle_seconds', '>', 180)
            ->orderBy('date')
            ->orderBy('employee_id')
            ->limit(50)
            ->get();
    }

    public function reviewUrl(DailyTimeReview $review): string
    {
        return DailyTimeReviewResource::getUrl('edit', ['record' => $review]);
    }

    private function visibleReviewsQuery(): Builder
    {
        return DailyTimeReview::query()
            ->with('employee.campaign', 'employee.team')
            ->whereHas('employee', fn (Builder $query) => $query->visibleTo(auth()->user()))
            ->when(
                $this->periodId,
                fn (Builder $query) => $query->where('payroll_period_id', $this->periodId),
                fn (Builder $query) => $query->whereRaw('1 = 0'),
            );
    }
}
