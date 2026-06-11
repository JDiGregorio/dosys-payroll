<?php

namespace App\Filament\Resources\PayrollDeductions;

use App\Filament\Resources\Concerns\RrhhOnlyResource;
use App\Filament\Resources\PayrollDeductions\Pages\CreatePayrollDeduction;
use App\Filament\Resources\PayrollDeductions\Pages\EditPayrollDeduction;
use App\Filament\Resources\PayrollDeductions\Pages\ListPayrollDeductions;
use App\Models\PayrollDeduction;
use App\Services\PayrollCalculationService;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PayrollDeductionResource extends Resource
{
    use RrhhOnlyResource;

    protected static ?string $model = PayrollDeduction::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptRefund;

    protected static ?string $navigationLabel = 'Deducciones del período';

    protected static ?string $modelLabel = 'deducción del período';

    protected static ?string $pluralModelLabel = 'deducciones del período';

    protected static string|\UnitEnum|null $navigationGroup = 'Planilla';

    protected static ?int $navigationSort = 60;

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('payroll_period_id')->label('Período')->relationship('payrollPeriod', 'name')->required(),
            Select::make('employee_id')->label('Empleado')->relationship('employee', 'name')->searchable()->preload()->required(),
            Select::make('deduction_type_id')->label('Tipo de deducción')->relationship('deductionType', 'name')->searchable()->preload()->required(),
            TextInput::make('amount')->label('Monto')->numeric()->default(0)->required(),
            TextInput::make('description')->label('Descripción'),
            Select::make('status')->label('Estado')->options([
                'borrador' => 'Borrador',
                'aprobado' => 'Aprobado',
                'rechazado' => 'Rechazado',
            ])->default('borrador')->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('payrollPeriod.name')->label('Período')->sortable(),
            TextColumn::make('employee.name')->label('Empleado')->searchable()->sortable(),
            TextColumn::make('deductionType.name')->label('Deducción'),
            TextColumn::make('amount')->label('Monto')->money('HNL'),
            TextColumn::make('status')->label('Estado')->badge(),
        ])->filters([
            SelectFilter::make('payroll_period_id')->label('Período')->relationship('payrollPeriod', 'name'),
            SelectFilter::make('status')->label('Estado')->options(['borrador' => 'Borrador', 'aprobado' => 'Aprobado', 'rechazado' => 'Rechazado']),
        ])->recordActions([
            EditAction::make()->label('Editar')->after(fn (PayrollDeduction $record) => app(PayrollCalculationService::class)->recalculateEmployeePayrollResult($record->payrollPeriod, $record->employee)),
        ])->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()->label('Eliminar')])]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPayrollDeductions::route('/'),
            'create' => CreatePayrollDeduction::route('/create'),
            'edit' => EditPayrollDeduction::route('/{record}/edit'),
        ];
    }
}
