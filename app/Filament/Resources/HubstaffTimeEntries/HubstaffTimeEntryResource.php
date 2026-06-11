<?php

namespace App\Filament\Resources\HubstaffTimeEntries;

use App\Filament\Resources\Concerns\RrhhOnlyResource;
use App\Filament\Resources\HubstaffTimeEntries\Pages\CreateHubstaffTimeEntry;
use App\Filament\Resources\HubstaffTimeEntries\Pages\EditHubstaffTimeEntry;
use App\Filament\Resources\HubstaffTimeEntries\Pages\ListHubstaffTimeEntries;
use App\Models\HubstaffTimeEntry;
use App\Services\TimeParserService;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class HubstaffTimeEntryResource extends Resource
{
    use RrhhOnlyResource;

    protected static ?string $model = HubstaffTimeEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $navigationLabel = 'Registros Hubstaff';

    protected static ?string $modelLabel = 'registro Hubstaff';

    protected static ?string $pluralModelLabel = 'registros Hubstaff';

    protected static string|\UnitEnum|null $navigationGroup = 'Planilla';

    protected static ?int $navigationSort = 30;

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('payroll_period_id')->label('Período')->relationship('payrollPeriod', 'name')->required(),
            Select::make('employee_id')->label('Empleado')->relationship('employee', 'name')->searchable()->preload(),
            TextInput::make('hubstaff_member')->label('Miembro Hubstaff')->required(),
            DatePicker::make('date')->label('Fecha')->required(),
            TextInput::make('project')->label('Proyecto'),
            TextInput::make('team')->label('Team'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('payrollPeriod.name')->label('Período')->sortable(),
                TextColumn::make('date')->label('Fecha')->date()->sortable(),
                TextColumn::make('hubstaff_member')->label('Miembro Hubstaff')->searchable(),
                TextColumn::make('employee.name')->label('Empleado')->searchable(),
                TextColumn::make('project')->label('Proyecto')->searchable(),
                TextColumn::make('regular_seconds')->label('Regular')->state(fn (HubstaffTimeEntry $record) => app(TimeParserService::class)->secondsToHourMinute($record->regular_seconds)),
                TextColumn::make('total_seconds')->label('Total')->state(fn (HubstaffTimeEntry $record) => app(TimeParserService::class)->secondsToHourMinute($record->total_seconds)),
                TextColumn::make('activity_percentage')->label('Actividad')->suffix('%'),
                TextColumn::make('idle_percentage')->label('Idle %')->suffix('%'),
                TextColumn::make('idle_seconds')->label('Idle')->state(fn (HubstaffTimeEntry $record) => app(TimeParserService::class)->secondsToHourMinute($record->idle_seconds)),
            ])
            ->filters([
                SelectFilter::make('payroll_period_id')->relationship('payrollPeriod', 'name')->label('Período'),
                SelectFilter::make('employee_id')->relationship('employee', 'name')->searchable()->label('Empleado'),
                SelectFilter::make('project')->label('Proyecto')->options(fn () => HubstaffTimeEntry::query()->whereNotNull('project')->distinct()->pluck('project', 'project')->all()),
                Filter::make('date')->label('Fecha')->form([DatePicker::make('date')->label('Fecha')])->query(fn (Builder $query, array $data) => $query->when($data['date'] ?? null, fn (Builder $query, $date) => $query->whereDate('date', $date))),
                Filter::make('unmapped')->label('Sin empleado')->query(fn (Builder $query) => $query->whereNull('employee_id')),
            ])
            ->recordActions([
                EditAction::make()->label('Editar'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListHubstaffTimeEntries::route('/'),
            'create' => CreateHubstaffTimeEntry::route('/create'),
            'edit' => EditHubstaffTimeEntry::route('/{record}/edit'),
        ];
    }
}
