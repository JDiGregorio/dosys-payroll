<?php

namespace App\Filament\Resources\DailyTimeReviews\Pages;

use App\Filament\Pages\DailyReviewCalendar;
use App\Filament\Resources\DailyTimeReviews\DailyTimeReviewResource;
use App\Services\PayrollCalculationService;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;

class EditDailyTimeReview extends EditRecord
{
    protected static string $resource = DailyTimeReviewResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $data + DailyTimeReviewResource::hourStates($this->record);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return DailyTimeReviewResource::secondsFromHourStates($data, $this->record);
    }

    protected function afterSave(): void
    {
        $service = app(PayrollCalculationService::class);
        $service->recalculateDailyReview($this->record);
        $service->recalculateEmployeePayrollResult($this->record->payrollPeriod, $this->record->employee);
    }

    protected function getRedirectUrl(): ?string
    {
        return $this->calendarUrl();
    }

    protected function getCancelFormAction(): Action
    {
        return Action::make('cancel')
            ->label('Cancelar')
            ->url($this->calendarUrl())
            ->color('gray');
    }

    private function calendarUrl(): string
    {
        $periodId = request()->integer('period_id') ?: $this->record->payroll_period_id;
        $employeeId = request()->integer('employee_id') ?: $this->record->employee_id;

        return DailyReviewCalendar::getUrl().'?'.http_build_query([
            'period_id' => $periodId,
            'employee_id' => $employeeId,
        ]);
    }
}
