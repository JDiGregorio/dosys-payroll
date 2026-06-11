<?php

namespace App\Filament\Resources\ScheduleTypes;

use App\Filament\Resources\Concerns\RrhhOnlyResource;
use App\Filament\Resources\ScheduleTypes\Pages\CreateScheduleType;
use App\Filament\Resources\ScheduleTypes\Pages\EditScheduleType;
use App\Filament\Resources\ScheduleTypes\Pages\ListScheduleTypes;
use App\Models\ScheduleType;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ScheduleTypeResource extends Resource
{
    use RrhhOnlyResource;

    protected static ?string $model = ScheduleType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendar;

    protected static ?string $navigationLabel = 'Jornadas';

    protected static ?string $modelLabel = 'jornada';

    protected static ?string $pluralModelLabel = 'jornadas';

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
            TimePicker::make('start_time')->label('Hora inicio')->seconds(false),
            TimePicker::make('end_time')->label('Hora fin')->seconds(false),
            TextInput::make('weekly_hours')->label('Horas semanales')->numeric()->default(0),
            TextInput::make('daily_hours')->label('Horas diarias')->numeric()->default(0),
            Textarea::make('description')->label('Descripción')->columnSpanFull(),
            Toggle::make('active')->label('Activo')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Nombre')->searchable()->sortable(),
            TextColumn::make('code')->label('Código')->badge(),
            TextColumn::make('weekly_hours')->label('Horas semanales'),
            TextColumn::make('daily_hours')->label('Horas diarias'),
            IconColumn::make('active')->label('Activo')->boolean(),
        ])->recordActions([EditAction::make()->label('Editar')])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()->label('Eliminar')])]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListScheduleTypes::route('/'),
            'create' => CreateScheduleType::route('/create'),
            'edit' => EditScheduleType::route('/{record}/edit'),
        ];
    }
}
