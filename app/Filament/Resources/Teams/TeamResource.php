<?php

namespace App\Filament\Resources\Teams;

use App\Filament\Resources\Concerns\RrhhOnlyResource;
use App\Filament\Resources\Teams\Pages\CreateTeam;
use App\Filament\Resources\Teams\Pages\EditTeam;
use App\Filament\Resources\Teams\Pages\ListTeams;
use App\Models\Team;
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

class TeamResource extends Resource
{
    use RrhhOnlyResource;

    protected static ?string $model = Team::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $navigationLabel = 'Teams';

    protected static ?string $modelLabel = 'team';

    protected static ?string $pluralModelLabel = 'teams';

    protected static string|\UnitEnum|null $navigationGroup = 'Configuración';

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->isRrhh() ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('campaign_id')->label('Campaña')->relationship('campaign', 'name')->searchable()->preload(),
            TextInput::make('name')->label('Nombre')->required()->maxLength(255),
            Toggle::make('active')->label('Activo')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Nombre')->searchable()->sortable(),
                TextColumn::make('campaign.name')->label('Campaña')->sortable(),
                IconColumn::make('active')->label('Activo')->boolean(),
            ])
            ->filters([SelectFilter::make('campaign_id')->label('Campaña')->relationship('campaign', 'name')])
            ->recordActions([EditAction::make()->label('Editar')])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()->label('Eliminar')])]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTeams::route('/'),
            'create' => CreateTeam::route('/create'),
            'edit' => EditTeam::route('/{record}/edit'),
        ];
    }
}
