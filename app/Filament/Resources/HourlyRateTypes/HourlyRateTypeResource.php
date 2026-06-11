<?php

namespace App\Filament\Resources\HourlyRateTypes;

use App\Filament\Resources\Concerns\RrhhOnlyResource;
use App\Filament\Resources\HourlyRateTypes\Pages\CreateHourlyRateType;
use App\Filament\Resources\HourlyRateTypes\Pages\EditHourlyRateType;
use App\Filament\Resources\HourlyRateTypes\Pages\ListHourlyRateTypes;
use App\Models\HourlyRateType;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class HourlyRateTypeResource extends Resource
{
    use RrhhOnlyResource;

    protected static ?string $model = HourlyRateType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $navigationLabel = 'Tipos de hora';

    protected static ?string $modelLabel = 'tipo de hora';

    protected static ?string $pluralModelLabel = 'tipos de hora';

    protected static string|\UnitEnum|null $navigationGroup = 'Configuración';

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->isRrhh() ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Nombre')->required(),
            TextInput::make('code')->label('Código')->required()->unique(ignoreRecord: true),
            TextInput::make('multiplier')->label('Multiplicador')->numeric()->default(1)->required(),
            Toggle::make('active')->label('Activo')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Nombre')->searchable()->sortable(),
            TextColumn::make('code')->label('Código')->badge(),
            TextColumn::make('multiplier')->label('Multiplicador')->numeric(2),
            IconColumn::make('active')->label('Activo')->boolean(),
        ])->recordActions([EditAction::make()->label('Editar')])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()->label('Eliminar')])]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListHourlyRateTypes::route('/'),
            'create' => CreateHourlyRateType::route('/create'),
            'edit' => EditHourlyRateType::route('/{record}/edit'),
        ];
    }
}
