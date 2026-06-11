<?php

namespace App\Filament\Resources\Campaigns;

use App\Filament\Resources\Campaigns\Pages\CreateCampaign;
use App\Filament\Resources\Campaigns\Pages\EditCampaign;
use App\Filament\Resources\Campaigns\Pages\ListCampaigns;
use App\Filament\Resources\Concerns\RrhhOnlyResource;
use App\Models\Campaign;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CampaignResource extends Resource
{
    use RrhhOnlyResource;

    protected static ?string $model = Campaign::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBriefcase;

    protected static ?string $navigationLabel = 'Campañas';

    protected static ?string $modelLabel = 'campaña';

    protected static ?string $pluralModelLabel = 'campañas';

    protected static string|\UnitEnum|null $navigationGroup = 'Configuración';

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->isRrhh() ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Nombre')->required()->maxLength(255)->unique(ignoreRecord: true),
            Toggle::make('active')->label('Activo')->default(true),
            Repeater::make('hubstaffProjectMappings')
                ->label('Proyectos Hubstaff asociados')
                ->relationship()
                ->schema([
                    TextInput::make('hubstaff_project')
                        ->label('Nombre del proyecto en Hubstaff')
                        ->required()
                        ->maxLength(255)
                        ->unique(table: 'hubstaff_project_mappings', column: 'hubstaff_project', ignoreRecord: true),
                    Toggle::make('active')->label('Activo')->default(true),
                ])
                ->addActionLabel('Agregar proyecto Hubstaff')
                ->columns(2)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Nombre')->searchable()->sortable(),
                IconColumn::make('active')->label('Activo')->boolean(),
            ])
            ->recordActions([EditAction::make()->label('Editar')])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()->label('Eliminar')])]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCampaigns::route('/'),
            'create' => CreateCampaign::route('/create'),
            'edit' => EditCampaign::route('/{record}/edit'),
        ];
    }
}
