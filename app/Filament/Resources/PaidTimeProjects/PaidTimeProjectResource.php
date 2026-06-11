<?php

namespace App\Filament\Resources\PaidTimeProjects;

use App\Filament\Resources\Concerns\RrhhOnlyResource;
use App\Filament\Resources\PaidTimeProjects\Pages\CreatePaidTimeProject;
use App\Filament\Resources\PaidTimeProjects\Pages\EditPaidTimeProject;
use App\Filament\Resources\PaidTimeProjects\Pages\ListPaidTimeProjects;
use App\Models\PaidTimeProject;
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
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class PaidTimeProjectResource extends Resource
{
    use RrhhOnlyResource;

    protected static ?string $model = PaidTimeProject::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?string $navigationLabel = 'Proyectos pagados';

    protected static ?string $modelLabel = 'proyecto pagado';

    protected static ?string $pluralModelLabel = 'proyectos pagados';

    protected static string|\UnitEnum|null $navigationGroup = 'Configuración';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Nombre')->required()->maxLength(255),
            Select::make('match_type')->label('Tipo de coincidencia')->options(['exact' => 'Exacta', 'contains' => 'Contiene'])->default('exact')->required(),
            Select::make('category')->label('Categoría')->options([
                'break' => 'Break',
                'lunch' => 'Lunch',
                'training' => 'Training',
                'meeting' => 'Meeting',
                'coaching' => 'Coaching',
                'other' => 'Other',
            ])->default('other')->required(),
            Toggle::make('is_paid')->label('Pagado')->default(true),
            Toggle::make('requires_review')->label('Requiere revisión')->default(false),
            Toggle::make('active')->label('Activo')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Nombre')->searchable()->sortable(),
                TextColumn::make('match_type')->label('Coincidencia')->badge(),
                TextColumn::make('category')->label('Categoría')->badge(),
                IconColumn::make('is_paid')->label('Pagado')->boolean(),
                IconColumn::make('requires_review')->label('Requiere revisión')->boolean(),
                IconColumn::make('active')->label('Activo')->boolean(),
            ])
            ->filters([
                SelectFilter::make('category')->options([
                    'break' => 'Break',
                    'lunch' => 'Lunch',
                    'training' => 'Training',
                    'meeting' => 'Meeting',
                    'coaching' => 'Coaching',
                    'other' => 'Other',
                ]),
                TernaryFilter::make('is_paid'),
                TernaryFilter::make('requires_review'),
                TernaryFilter::make('active'),
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
            'index' => ListPaidTimeProjects::route('/'),
            'create' => CreatePaidTimeProject::route('/create'),
            'edit' => EditPaidTimeProject::route('/{record}/edit'),
        ];
    }
}
