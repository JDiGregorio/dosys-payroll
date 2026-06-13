<?php

namespace App\Filament\Resources\PayrollPeriods\Pages;

use App\Filament\Resources\PayrollPeriods\PayrollPeriodResource;
use App\Services\PayrollCalculationService;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPayrollPeriod extends EditRecord
{
    protected static string $resource = PayrollPeriodResource::class;

    private array $deductionTypeIds = [];

    protected function getHeaderActions(): array
    {
        return [
            PayrollPeriodResource::importHubstaffAction(),
            PayrollPeriodResource::mapHubstaffEmployeeAction(),
            PayrollPeriodResource::recalculatePayrollAction(),
            DeleteAction::make()->label('Eliminar'),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->deductionTypeIds = $data['deduction_type_ids'] ?? [];
        unset($data['deduction_type_ids']);

        return $data;
    }

    protected function afterSave(): void
    {
        $this->record->deductionTypes()->sync($this->deductionTypeIds);

        if ($this->record->status !== 'cerrado' && $this->record->hubstaffTimeEntries()->exists()) {
            $service = app(PayrollCalculationService::class);
            $service->generateDailyReviews($this->record);
            $service->recalculatePayrollResults($this->record);
        }
    }
}
