<?php

namespace App\Filament\Resources\EmployeeAdditionalDeductions;

use App\Filament\Resources\Concerns\RrhhOnlyResource;
use App\Filament\Resources\EmployeeAdditionalDeductions\Pages\CreateEmployeeAdditionalDeduction;
use App\Filament\Resources\EmployeeAdditionalDeductions\Pages\EditEmployeeAdditionalDeduction;
use App\Filament\Resources\EmployeeAdditionalDeductions\Pages\ListEmployeeAdditionalDeductions;
use App\Models\EmployeeAdditionalDeduction;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class EmployeeAdditionalDeductionResource extends Resource
{
    use RrhhOnlyResource;

    protected static ?string $model = EmployeeAdditionalDeduction::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMinusCircle;

    protected static ?string $navigationLabel = 'Deducciones adicionales';

    protected static ?string $modelLabel = 'deducción adicional';

    protected static ?string $pluralModelLabel = 'deducciones adicionales';

    protected static string|\UnitEnum|null $navigationGroup = 'RRHH';

    protected static ?int $navigationSort = 20;

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->isRrhh() ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('payroll_period_id')->label('Período a cobrar')->relationship('payrollPeriod', 'name')->searchable()->preload()->required(),
            Select::make('employee_id')->label('Empleado')->relationship('employee', 'name')->searchable()->preload()->required(),
            TextInput::make('amount')->label('Monto')->numeric()->minValue(0.01)->default(0)->required(),
            TextInput::make('description')->label('Descripción')->maxLength(255)->required(),
            Toggle::make('active')->label('Activo')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('payrollPeriod.name')->label('Período')->sortable(),
                TextColumn::make('employee.name')->label('Empleado')->searchable()->sortable(),
                TextColumn::make('amount')->label('Monto')->money('HNL')->sortable(),
                TextColumn::make('description')->label('Descripción')->searchable()->limit(40),
                IconColumn::make('active')->label('Activo')->boolean(),
            ])
            ->filters([
                SelectFilter::make('payroll_period_id')->label('Período')->relationship('payrollPeriod', 'name'),
                SelectFilter::make('employee_id')->label('Empleado')->relationship('employee', 'name')->searchable(),
            ])
            ->recordActions([
                EditAction::make()->label('Editar'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->label('Eliminar'),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmployeeAdditionalDeductions::route('/'),
            'create' => CreateEmployeeAdditionalDeduction::route('/create'),
            'edit' => EditEmployeeAdditionalDeduction::route('/{record}/edit'),
        ];
    }
}
