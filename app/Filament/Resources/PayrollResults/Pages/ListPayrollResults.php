<?php

namespace App\Filament\Resources\PayrollResults\Pages;

use App\Filament\Resources\PayrollResults\PayrollResultResource;
use App\Models\PayrollPeriod;
use Filament\Resources\Pages\ListRecords;

class ListPayrollResults extends ListRecords
{
    protected static string $resource = PayrollResultResource::class;

    public function mount(): void
    {
        parent::mount();

        if (filled($this->tableFilters['payroll_period_id']['value'] ?? null)) {
            return;
        }

        $periodId = PayrollPeriod::query()->open()->latest('starts_at')->value('id')
            ?? PayrollPeriod::query()->latest('starts_at')->value('id');

        if (! $periodId) {
            return;
        }

        $this->tableFilters = array_replace_recursive($this->tableFilters ?? [], [
            'payroll_period_id' => ['value' => (string) $periodId],
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
