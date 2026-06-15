<?php

namespace App\Filament\Resources\PayrollResults\Pages;

use App\Filament\Resources\PayrollResults\PayrollResultResource;
use App\Services\PayrollCalculationService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditPayrollResult extends EditRecord
{
    protected static string $resource = PayrollResultResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('recalculateEmployee')
                ->label('Recalcular empleado')
                ->icon('heroicon-o-arrow-path')
                ->requiresConfirmation()
                ->modalDescription('Se actualizarán únicamente los cálculos derivados del empleado, preservando justificaciones, comentarios y aprobaciones.')
                ->action(function (PayrollCalculationService $service): void {
                    $service->recalculateEmployeePreservingManual(
                        $this->record->payrollPeriod,
                        $this->record->employee,
                    );
                    $this->refreshFormData(array_keys($this->record->fresh()->getAttributes()));
                    Notification::make()->title('Cálculos del empleado actualizados')->success()->send();
                }),
        ];
    }
}
