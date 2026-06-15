<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Filament\Resources\Employees\EmployeeResource;
use App\Models\PayrollPeriod;
use App\Services\PayrollCalculationService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditEmployee extends EditRecord
{
    protected static string $resource = EmployeeResource::class;

    private ?int $userAccountId = null;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('suggestValues')
                ->label('Sugerir valores')
                ->icon('heroicon-o-sparkles')
                ->requiresConfirmation()
                ->modalDescription('Los valores se tomarán del Tier y de los datos actuales del empleado. Por defecto solo se completan campos vacíos o en cero.')
                ->form([
                    Toggle::make('overwrite_existing')
                        ->label('Sobrescribir valores existentes')
                        ->default(false),
                ])
                ->action(function (array $data): void {
                    $suggestions = EmployeeResource::suggestedCompensation($this->record);

                    if (! ($data['overwrite_existing'] ?? false)) {
                        $suggestions = array_filter(
                            $suggestions,
                            fn (float $value, string $field): bool => (float) $this->record->{$field} <= 0,
                            ARRAY_FILTER_USE_BOTH,
                        );
                    }

                    if ($suggestions !== []) {
                        $this->record->update($suggestions);
                        $this->refreshFormData(array_keys($suggestions));
                    }

                    Notification::make()
                        ->title('Valores sugeridos aplicados')
                        ->body('Revisa los valores antes de guardar el empleado.')
                        ->success()
                        ->send();
                }),
            DeleteAction::make()->label('Eliminar'),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->userAccountId = $data['user_account_id'] ?? null;
        unset($data['user_account_id']);

        return EmployeeResource::normalizeCompensation($data);
    }

    protected function afterSave(): void
    {
        EmployeeResource::syncUserAccount($this->record, $this->userAccountId);

        PayrollPeriod::query()
            ->where('status', '!=', 'cerrado')
            ->whereHas('dailyTimeReviews', fn ($query) => $query->where('employee_id', $this->record->id))
            ->each(function (PayrollPeriod $period): void {
                $service = app(PayrollCalculationService::class);
                $service->recalculatePeriodPreservingManual($period);
            });
    }
}
