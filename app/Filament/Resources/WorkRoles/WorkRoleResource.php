<?php

namespace App\Filament\Resources\WorkRoles;

use App\Filament\Resources\Concerns\RrhhOnlyResource;
use App\Filament\Resources\WorkRoles\Pages\CreateWorkRole;
use App\Filament\Resources\WorkRoles\Pages\EditWorkRole;
use App\Filament\Resources\WorkRoles\Pages\ListWorkRoles;
use App\Models\WorkRole;
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

class WorkRoleResource extends Resource
{
    use RrhhOnlyResource;

    protected static ?string $model = WorkRole::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static ?string $navigationLabel = 'Roles laborales';

    protected static ?string $modelLabel = 'rol laboral';

    protected static ?string $pluralModelLabel = 'roles laborales';

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
            'index' => ListWorkRoles::route('/'),
            'create' => CreateWorkRole::route('/create'),
            'edit' => EditWorkRole::route('/{record}/edit'),
        ];
    }
}
