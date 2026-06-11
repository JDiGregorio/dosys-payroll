<?php

namespace App\Filament\Pages;

use App\Filament\Resources\DailyTimeReviews\DailyTimeReviewResource;
use App\Models\DailyTimeReview;
use App\Models\HubstaffTimeEntry;
use App\Models\PayrollPeriod;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
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
        return auth()->user()?->isRrhh() ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->isRrhh() ?? false;
    }

    public function mount(): void
    {
        $this->periodId = PayrollPeriod::query()->where('status', '!=', 'cerrado')->latest('starts_at')->value('id');
    }

    public function periods(): Collection
    {
        return PayrollPeriod::query()->where('status', '!=', 'cerrado')->orderByDesc('starts_at')->get();
    }

    public function selectedPeriod(): ?PayrollPeriod
    {
        return $this->periodId ? PayrollPeriod::query()->find($this->periodId) : null;
    }

    public function unmappedMembers(): Collection
    {
        return HubstaffTimeEntry::query()
            ->when($this->periodId, fn ($query) => $query->where('payroll_period_id', $this->periodId))
            ->whereNull('employee_id')
            ->select('hubstaff_member')
            ->distinct()
            ->orderBy('hubstaff_member')
            ->limit(50)
            ->pluck('hubstaff_member');
    }

    public function shortPayableDays(): Collection
    {
        return DailyTimeReview::query()
            ->with('employee.campaign', 'employee.team')
            ->when($this->periodId, fn ($query) => $query->where('payroll_period_id', $this->periodId))
            ->whereRaw('payable_seconds < expected_seconds + assigned_overtime_seconds')
            ->orderBy('date')
            ->limit(50)
            ->get();
    }

    public function highIdleDays(): Collection
    {
        return DailyTimeReview::query()
            ->with('employee')
            ->when($this->periodId, fn ($query) => $query->where('payroll_period_id', $this->periodId))
            ->where('hubstaff_idle_seconds', '>', 1800)
            ->orderByDesc('hubstaff_idle_seconds')
            ->limit(50)
            ->get();
    }

    public function reviewUrl(DailyTimeReview $review): string
    {
        return DailyTimeReviewResource::getUrl('edit', ['record' => $review]);
    }
}
