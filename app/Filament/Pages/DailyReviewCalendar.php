<?php

namespace App\Filament\Pages;

use App\Filament\Resources\DailyTimeReviews\DailyTimeReviewResource;
use App\Models\DailyTimeReview;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Services\TimeParserService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class DailyReviewCalendar extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendar;

    protected static ?string $navigationLabel = 'Revisión diaria';

    protected static ?string $title = 'Revisión diaria';

    protected static string|\UnitEnum|null $navigationGroup = 'Planilla';

    protected static ?int $navigationSort = 40;

    protected string $view = 'filament.pages.daily-review-calendar';

    public ?int $periodId = null;

    public ?int $employeeId = null;

    public function mount(): void
    {
        $this->periodId = $this->resolvePeriodId(request()->integer('period_id') ?: null);
        $this->employeeId = $this->resolveEmployeeId(request()->integer('employee_id') ?: null);
    }

    public function updatedPeriodId(): void
    {
        $this->employeeId = $this->employees()->first()?->id;
    }

    public function selectPeriod(mixed $periodId): void
    {
        $this->periodId = filled($periodId) ? (int) $periodId : null;
        $this->employeeId = $this->employees()->first()?->id;
    }

    public function selectEmployee(mixed $employeeId): void
    {
        $this->employeeId = $this->resolveEmployeeId(filled($employeeId) ? (int) $employeeId : null);
    }

    public function periods(): Collection
    {
        return PayrollPeriod::query()->where('status', '!=', 'cerrado')->orderByDesc('starts_at')->get();
    }

    public function employees(): Collection
    {
        return $this->employeesForPeriod($this->periodId)->get();
    }

    public function selectedPeriod(): ?PayrollPeriod
    {
        return $this->periodId ? PayrollPeriod::query()->find($this->periodId) : null;
    }

    public function selectedEmployee(): ?Employee
    {
        return $this->employeeId ? Employee::query()->find($this->employeeId) : null;
    }

    public function calendarDays(): Collection
    {
        $period = $this->selectedPeriod();

        if (! $period) {
            return collect();
        }

        $startsAt = $period->starts_at->copy()->startOfWeek(CarbonInterface::MONDAY);
        $endsAt = $period->ends_at->copy()->endOfWeek(CarbonInterface::SUNDAY);

        return collect(CarbonPeriod::create($startsAt, $endsAt));
    }

    public function weekdayLabels(): array
    {
        return ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];
    }

    public function isInsidePeriod(Carbon $day): bool
    {
        $period = $this->selectedPeriod();

        if (! $period) {
            return false;
        }

        return $day->betweenIncluded($period->starts_at, $period->ends_at);
    }

    public function reviewsByDate(): Collection
    {
        if (! $this->periodId || ! $this->employeeId) {
            return collect();
        }

        return DailyTimeReview::query()
            ->where('payroll_period_id', $this->periodId)
            ->where('employee_id', $this->employeeId)
            ->get()
            ->keyBy(fn (DailyTimeReview $review) => $review->date->toDateString());
    }

    public function reviewUrl(DailyTimeReview $review): string
    {
        return DailyTimeReviewResource::getUrl('edit', ['record' => $review])
            .'?'.http_build_query([
                'period_id' => $this->periodId,
                'employee_id' => $this->employeeId,
            ]);
    }

    public function calendarUrl(?int $periodId = null, ?int $employeeId = null): string
    {
        $query = array_filter([
            'period_id' => $periodId,
            'employee_id' => $employeeId,
        ], fn ($value): bool => filled($value));

        $url = static::getUrl();

        return $query === [] ? $url : $url.'?'.http_build_query($query);
    }

    public function hours(int $seconds): string
    {
        return app(TimeParserService::class)->secondsToHourMinute($seconds);
    }

    public function statusLabel(?string $status): string
    {
        return match ($status) {
            'pendiente' => 'Pendiente',
            'revisado_supervisor', 'aprobado_rrhh' => 'Aplicada',
            default => 'Sin revisión',
        };
    }

    public function reviewStatusLabel(?DailyTimeReview $review): string
    {
        return DailyTimeReviewResource::displayStatusLabel($review);
    }

    public function isCorrectPendingReview(?DailyTimeReview $review): bool
    {
        return DailyTimeReviewResource::isCorrectPendingReview($review);
    }

    public function statusClasses(?string $status): string
    {
        return match ($status) {
            'pendiente' => 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300',
            'revisado_supervisor' => 'bg-sky-100 text-sky-800 dark:bg-sky-500/15 dark:text-sky-300',
            'aprobado_rrhh' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300',
            default => 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300',
        };
    }

    private function resolvePeriodId(?int $periodId): ?int
    {
        if ($periodId && PayrollPeriod::query()->where('status', '!=', 'cerrado')->whereKey($periodId)->exists()) {
            return $periodId;
        }

        return PayrollPeriod::query()->where('status', '!=', 'cerrado')->latest('starts_at')->value('id');
    }

    private function resolveEmployeeId(?int $employeeId): ?int
    {
        $employees = $this->employeesForPeriod($this->periodId)->get();

        if ($employeeId && $employees->contains('id', $employeeId)) {
            return $employeeId;
        }

        return $employees->first()?->id;
    }

    private function employeesForPeriod(?int $periodId): Builder
    {
        $query = Employee::query()
            ->visibleTo(auth()->user())
            ->where('active', true)
            ->orderBy('name');

        if ($periodId) {
            $query->whereHas('dailyTimeReviews', fn ($query) => $query->where('payroll_period_id', $periodId));
        }

        return $query;
    }
}
