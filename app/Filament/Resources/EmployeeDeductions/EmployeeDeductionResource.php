<?php

namespace App\Filament\Resources\EmployeeDeductions;

use App\Filament\Resources\Concerns\RrhhOnlyResource;
use App\Filament\Resources\EmployeeDeductions\Pages\CreateEmployeeDeduction;
use App\Filament\Resources\EmployeeDeductions\Pages\EditEmployeeDeduction;
use App\Filament\Resources\EmployeeDeductions\Pages\ListEmployeeDeductions;
use App\Models\EmployeeDeduction;
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

class EmployeeDeductionResource extends Resource
{
    use RrhhOnlyResource;

    protected static ?string $model = EmployeeDeduction::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMinusCircle;

    protected static ?string $navigationLabel = 'Deducciones configuradas';

    protected static ?string $modelLabel = 'deducción configurada';

    protected static ?string $pluralModelLabel = 'deducciones configuradas';

    protected static string|\UnitEnum|null $navigationGroup = 'RRHH';

    protected static ?int $navigationSort = 20;

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('employee_id')->label('Empleado')->relationship('employee', 'name')->searchable()->preload()->required(),
            Select::make('deduction_type_id')->label('Tipo de deducción')->relationship('deductionType', 'name')->searchable()->preload()->required(),
            TextInput::make('amount')->label('Monto')->numeric()->default(0),
            TextInput::make('percentage')->label('Porcentaje')->numeric()->default(0),
            Toggle::make('active')->label('Activo')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('employee.name')->label('Empleado')->searchable()->sortable(),
            TextColumn::make('deductionType.name')->label('Deducción')->sortable(),
            TextColumn::make('amount')->label('Monto')->money('HNL', locale: 'en-US'),
            TextColumn::make('percentage')->label('Porcentaje'),
            IconColumn::make('active')->label('Activo')->boolean(),
        ])->filters([
            SelectFilter::make('deduction_type_id')->label('Tipo')->relationship('deductionType', 'name'),
        ])->recordActions([EditAction::make()->label('Editar')])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()->label('Eliminar')])]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmployeeDeductions::route('/'),
            'create' => CreateEmployeeDeduction::route('/create'),
            'edit' => EditEmployeeDeduction::route('/{record}/edit'),
        ];
    }
}
