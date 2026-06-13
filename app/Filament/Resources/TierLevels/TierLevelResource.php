<?php

namespace App\Filament\Resources\TierLevels;

use App\Filament\Resources\Concerns\RrhhOnlyResource;
use App\Filament\Resources\TierLevels\Pages\CreateTierLevel;
use App\Filament\Resources\TierLevels\Pages\EditTierLevel;
use App\Filament\Resources\TierLevels\Pages\ListTierLevels;
use App\Models\TierLevel;
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
use Filament\Tables\Table;

class TierLevelResource extends Resource
{
    use RrhhOnlyResource;

    protected static ?string $model = TierLevel::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'Niveles salariales';

    protected static ?string $modelLabel = 'nivel salarial';

    protected static ?string $pluralModelLabel = 'niveles salariales';

    protected static string|\UnitEnum|null $navigationGroup = 'Configuración';

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->isRrhh() ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Nombre')->required()->unique(ignoreRecord: true),
            TextInput::make('position_name')->label('Puesto de referencia'),
            TextInput::make('category')->label('Categoría'),
            Select::make('schedule_type_id')->label('Jornada')->relationship('scheduleType', 'name')->searchable()->preload(),
            TextInput::make('weekly_hours')->label('Horas semanales')->numeric()->default(0),
            TextInput::make('monthly_salary')->label('Salario mensual')->numeric(),
            TextInput::make('hourly_rate')->label('Pago por hora')->numeric(),
            Toggle::make('active')->label('Activo')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Nivel')->searchable()->sortable(),
            TextColumn::make('position_name')->label('Puesto')->limit(35),
            TextColumn::make('scheduleType.name')->label('Jornada'),
            TextColumn::make('weekly_hours')->label('Horas'),
            TextColumn::make('monthly_salary')->label('Salario')->money('HNL', locale: 'en-US'),
            TextColumn::make('hourly_rate')->label('Hora')->money('HNL', locale: 'en-US'),
            IconColumn::make('active')->label('Activo')->boolean(),
        ])->recordActions([EditAction::make()->label('Editar')])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()->label('Eliminar')])]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTierLevels::route('/'),
            'create' => CreateTierLevel::route('/create'),
            'edit' => EditTierLevel::route('/{record}/edit'),
        ];
    }
}
