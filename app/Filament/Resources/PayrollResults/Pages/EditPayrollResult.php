<?php

namespace App\Filament\Resources\PayrollResults\Pages;

use App\Filament\Resources\PayrollResults\PayrollResultResource;
use App\Services\PayrollCalculationService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditPayrollResult extends EditRecord
{
    protected static string $resource = PayrollResultResource::class;

    protected function getHeaderActions(): array
    {
        return [
            PayrollResultResource::previewVoucherAction(),
            PayrollResultResource::sendVoucherAction(),
            Action::make('recalculateEmployee')
                ->label('Recalcular empleado')
                ->icon('heroicon-o-arrow-path')
                ->visible(fn (): bool => $this->record->payrollPeriod?->status !== 'cerrado')
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

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if ($this->record->payrollPeriod?->status === 'cerrado') {
            throw ValidationException::withMessages([
                'payroll_period_id' => 'No se puede modificar una planilla calculada de un período cerrado.',
            ]);
        }

        return $data;
    }
}
