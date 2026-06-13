<?php

namespace App\Filament\Resources\DeductionTypes;

use App\Filament\Resources\Concerns\RrhhOnlyResource;
use App\Filament\Resources\DeductionTypes\Pages\CreateDeductionType;
use App\Filament\Resources\DeductionTypes\Pages\EditDeductionType;
use App\Filament\Resources\DeductionTypes\Pages\ListDeductionTypes;
use App\Models\DeductionType;
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

class DeductionTypeResource extends Resource
{
    use RrhhOnlyResource;

    protected static ?string $model = DeductionType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static ?string $navigationLabel = 'Tipos de deducción';

    protected static ?string $modelLabel = 'tipo de deducción';

    protected static ?string $pluralModelLabel = 'tipos de deducción';

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
            Select::make('calculation_type')->label('Tipo de cálculo')->options([
                'fixed' => 'Monto fijo',
                'percentage' => 'Porcentaje',
                'manual' => 'Manual',
            ])->required(),
            TextInput::make('default_amount')->label('Monto por defecto')->numeric()->default(0),
            TextInput::make('default_percentage')->label('Porcentaje por defecto')->numeric()->default(0),
            Toggle::make('active')->label('Activo')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Nombre')->searchable()->sortable(),
            TextColumn::make('code')->label('Código')->badge(),
            TextColumn::make('calculation_type')->label('Cálculo')->badge(),
            TextColumn::make('default_amount')->label('Monto')->money('HNL', locale: 'en-US'),
            IconColumn::make('active')->label('Activo')->boolean(),
        ])->recordActions([EditAction::make()->label('Editar')])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()->label('Eliminar')])]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDeductionTypes::route('/'),
            'create' => CreateDeductionType::route('/create'),
            'edit' => EditDeductionType::route('/{record}/edit'),
        ];
    }
}
