<?php

namespace App\Filament\Resources\EmployeeNameMappings;

use App\Filament\Resources\Concerns\RrhhOnlyResource;
use App\Filament\Resources\EmployeeNameMappings\Pages\CreateEmployeeNameMapping;
use App\Filament\Resources\EmployeeNameMappings\Pages\EditEmployeeNameMapping;
use App\Filament\Resources\EmployeeNameMappings\Pages\ListEmployeeNameMappings;
use App\Models\EmployeeNameMapping;
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
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class EmployeeNameMappingResource extends Resource
{
    use RrhhOnlyResource;

    protected static ?string $model = EmployeeNameMapping::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLink;

    protected static ?string $navigationLabel = 'Mapeo de nombres';

    protected static ?string $modelLabel = 'mapeo de nombre';

    protected static ?string $pluralModelLabel = 'mapeo de nombres';

    protected static string|\UnitEnum|null $navigationGroup = 'RRHH';

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function canViewAny(): bool
    {
        return false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('employee_id')->label('Empleado')->relationship('employee', 'name')->searchable()->preload()->required(),
            TextInput::make('hubstaff_member')->label('Nombre en Hubstaff')->required()->maxLength(255)->unique(ignoreRecord: true),
            TextInput::make('confidence')->label('Confianza')->numeric()->minValue(0)->maxValue(100),
            Toggle::make('is_active')->label('Activo')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('hubstaff_member')->label('Nombre en Hubstaff')->searchable()->sortable(),
                TextColumn::make('employee.name')->label('Empleado')->searchable()->sortable(),
                TextColumn::make('confidence')->label('Confianza')->numeric(),
                IconColumn::make('is_active')->label('Activo')->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Activo'),
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
            'index' => ListEmployeeNameMappings::route('/'),
            'create' => CreateEmployeeNameMapping::route('/create'),
            'edit' => EditEmployeeNameMapping::route('/{record}/edit'),
        ];
    }
}
