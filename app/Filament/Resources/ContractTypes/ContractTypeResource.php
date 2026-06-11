<?php

namespace App\Filament\Resources\ContractTypes;

use App\Filament\Resources\Concerns\RrhhOnlyResource;
use App\Filament\Resources\ContractTypes\Pages\CreateContractType;
use App\Filament\Resources\ContractTypes\Pages\EditContractType;
use App\Filament\Resources\ContractTypes\Pages\ListContractTypes;
use App\Models\ContractType;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ContractTypeResource extends Resource
{
    use RrhhOnlyResource;

    protected static ?string $model = ContractType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = 'Tipos de contrato';

    protected static ?string $modelLabel = 'tipo de contrato';

    protected static ?string $pluralModelLabel = 'tipos de contrato';

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
            TextInput::make('min_weekly_hours')->label('Mínimo horas semanales')->numeric(),
            TextInput::make('max_weekly_hours')->label('Máximo horas semanales')->numeric(),
            Textarea::make('description')->label('Descripción')->columnSpanFull(),
            Toggle::make('active')->label('Activo')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Nombre')->searchable()->sortable(),
            TextColumn::make('code')->label('Código')->badge(),
            TextColumn::make('min_weekly_hours')->label('Mínimo'),
            TextColumn::make('max_weekly_hours')->label('Máximo'),
            IconColumn::make('active')->label('Activo')->boolean(),
        ])->recordActions([EditAction::make()->label('Editar')])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()->label('Eliminar')])]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListContractTypes::route('/'),
            'create' => CreateContractType::route('/create'),
            'edit' => EditContractType::route('/{record}/edit'),
        ];
    }
}
