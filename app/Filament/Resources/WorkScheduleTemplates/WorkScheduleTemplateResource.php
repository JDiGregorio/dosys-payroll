<?php

namespace App\Filament\Resources\WorkScheduleTemplates;

use App\Filament\Resources\Concerns\RrhhOnlyResource;
use App\Filament\Resources\WorkScheduleTemplates\Pages\CreateWorkScheduleTemplate;
use App\Filament\Resources\WorkScheduleTemplates\Pages\EditWorkScheduleTemplate;
use App\Filament\Resources\WorkScheduleTemplates\Pages\ListWorkScheduleTemplates;
use App\Models\WorkScheduleTemplate;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class WorkScheduleTemplateResource extends Resource
{
    use RrhhOnlyResource;

    protected static ?string $model = WorkScheduleTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $navigationLabel = 'Plantillas de horario';

    protected static ?string $modelLabel = 'plantilla de horario';

    protected static ?string $pluralModelLabel = 'plantillas de horario';

    protected static string|\UnitEnum|null $navigationGroup = 'Configuración';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Nombre')->required()->maxLength(255),
            Select::make('schedule_type')
                ->label('Jornada')
                ->options([
                    'diurna' => 'Diurna',
                    'rotativa' => 'Rotativa',
                    'nocturna' => 'Nocturna',
                ])
                ->required(),
            Toggle::make('active')->label('Activa')->default(true),
            Textarea::make('description')->label('Descripción')->columnSpanFull(),
            Repeater::make('days')
                ->label('Patrón diario')
                ->relationship()
                ->schema([
                    TextInput::make('day_number')->label('Día del patrón')->numeric()->minValue(1)->required(),
                    TextInput::make('expected_seconds')
                        ->label('Horas ordinarias esperadas')
                        ->numeric()
                        ->step(0.01)
                        ->minValue(0)
                        ->formatStateUsing(fn (mixed $state): float => round((int) $state / 3600, 2))
                        ->dehydrateStateUsing(fn (mixed $state): int => max((int) round((float) $state * 3600), 0))
                        ->required(),
                    Toggle::make('is_working_day')->label('Día laborable')->default(true),
                    TextInput::make('notes')->label('Notas')->maxLength(255),
                ])
                ->columns(4)
                ->defaultItems(0)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Plantilla')->searchable()->sortable(),
                TextColumn::make('schedule_type')->label('Jornada')->badge(),
                TextColumn::make('days_count')->label('Días')->counts('days'),
                IconColumn::make('active')->label('Activa')->boolean(),
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
            'index' => ListWorkScheduleTemplates::route('/'),
            'create' => CreateWorkScheduleTemplate::route('/create'),
            'edit' => EditWorkScheduleTemplate::route('/{record}/edit'),
        ];
    }
}
