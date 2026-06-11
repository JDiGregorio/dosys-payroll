<?php

namespace App\Filament\Resources\HubstaffProjectMappings;

use App\Filament\Resources\Concerns\RrhhOnlyResource;
use App\Filament\Resources\HubstaffProjectMappings\Pages\CreateHubstaffProjectMapping;
use App\Filament\Resources\HubstaffProjectMappings\Pages\EditHubstaffProjectMapping;
use App\Filament\Resources\HubstaffProjectMappings\Pages\ListHubstaffProjectMappings;
use App\Models\HubstaffProjectMapping;
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

class HubstaffProjectMappingResource extends Resource
{
    use RrhhOnlyResource;

    protected static ?string $model = HubstaffProjectMapping::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?string $navigationLabel = 'Mapeo proyectos Hubstaff';

    protected static ?string $modelLabel = 'mapeo de proyecto Hubstaff';

    protected static ?string $pluralModelLabel = 'mapeos de proyectos Hubstaff';

    protected static string|\UnitEnum|null $navigationGroup = 'Configuración';

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
            TextInput::make('hubstaff_project')->label('Proyecto en Hubstaff')->required()->maxLength(255)->unique(ignoreRecord: true),
            Select::make('campaign_id')->label('Campaña')->relationship('campaign', 'name')->searchable()->preload(),
            Toggle::make('active')->label('Activo')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('hubstaff_project')->label('Proyecto en Hubstaff')->searchable()->sortable(),
                TextColumn::make('campaign.name')->label('Campaña')->sortable(),
                IconColumn::make('active')->label('Activo')->boolean(),
            ])
            ->filters([
                TernaryFilter::make('active')->label('Activo'),
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
            'index' => ListHubstaffProjectMappings::route('/'),
            'create' => CreateHubstaffProjectMapping::route('/create'),
            'edit' => EditHubstaffProjectMapping::route('/{record}/edit'),
        ];
    }
}
