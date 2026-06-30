<?php

namespace App\Filament\Resources\PayrollBonuses;

use App\Filament\Resources\PayrollBonuses\Pages\CreatePayrollBonus;
use App\Filament\Resources\PayrollBonuses\Pages\EditPayrollBonus;
use App\Filament\Resources\PayrollBonuses\Pages\ListPayrollBonuses;
use App\Models\Employee;
use App\Models\PayrollBonus;
use App\Models\PayrollPeriod;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class PayrollBonusResource extends Resource
{
    protected static ?string $model = PayrollBonus::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGift;

    protected static ?string $navigationLabel = 'Bonos de planilla';

    protected static ?string $modelLabel = 'bono de planilla';

    protected static ?string $pluralModelLabel = 'bonos de planilla';

    protected static string|\UnitEnum|null $navigationGroup = 'Planilla';

    protected static ?int $navigationSort = 50;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->whereHas('payrollPeriod', fn (Builder $query) => $query->open())
            ->with(['employee', 'team', 'campaign', 'payrollPeriod']);
        $user = auth()->user();

        if ($user?->isSupervisor()) {
            $query->whereHas('employee', fn (Builder $query) => $query->visibleTo($user));
        }

        return $query;
    }

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->active;
    }

    public static function canCreate(): bool
    {
        return (bool) auth()->user()?->active && PayrollPeriod::hasOpenPeriod();
    }

    public static function canEdit(Model $record): bool
    {
        $user = auth()->user();

        return $record->payrollPeriod?->status !== 'cerrado'
            && ($user?->isRrhh()
            || ($user?->isSupervisor()
                && $record->employee?->supervisor_user_id === $user->id
                && $record->payrollPeriod?->status !== 'cerrado'));
    }

    public static function canDelete(Model $record): bool
    {
        return (auth()->user()?->isRrhh() ?? false)
            && $record->payrollPeriod?->status !== 'cerrado';
    }

    public static function canDeleteAny(): bool
    {
        return auth()->user()?->isRrhh() ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('payroll_period_id')
                ->label('Período')
                ->relationship('payrollPeriod', 'name', modifyQueryUsing: fn (Builder $query) => $query->open())
                ->required(),
            Select::make('scope_type')->label('Aplica a')->options([
                'employee' => 'Empleado',
                ...((auth()->user()?->isRrhh() ?? false) ? [
                    'team' => 'Team',
                    'campaign' => 'Campaña',
                ] : []),
            ])->default('employee')->required()->live()->afterStateUpdated(function (Set $set): void {
                $set('employee_id', null);
                $set('team_id', null);
                $set('campaign_id', null);
            }),
            Select::make('employee_id')
                ->label('Empleado')
                ->relationship('employee', 'name', modifyQueryUsing: fn (Builder $query) => $query
                    ->visibleTo(auth()->user())
                    ->where('active', true))
                ->searchable()
                ->preload()
                ->visible(fn ($get) => $get('scope_type') === 'employee')
                ->required(fn ($get) => $get('scope_type') === 'employee'),
            Select::make('team_id')->label('Team')->relationship('team', 'name')->searchable()->preload()->visible(fn ($get) => $get('scope_type') === 'team')->required(fn ($get) => $get('scope_type') === 'team'),
            Select::make('campaign_id')->label('Campaña')->relationship('campaign', 'name')->searchable()->preload()->visible(fn ($get) => $get('scope_type') === 'campaign')->required(fn ($get) => $get('scope_type') === 'campaign'),
            Select::make('type')->label('Tipo')->options(self::typeOptions())->default('manual')->required(),
            TextInput::make('amount')->label('Monto')->numeric()->default(0)->required(),
            TextInput::make('description')->label('Descripción')->maxLength(255),
            Select::make('status')->label('Estado')->options(self::statusOptions())->default('aprobado')->required()->hidden(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('payrollPeriod.name')->label('Período')->sortable(),
                TextColumn::make('scope_type')->label('Aplica a')->badge()->formatStateUsing(fn (string $state) => ['employee' => 'Empleado', 'team' => 'Team', 'campaign' => 'Campaña'][$state] ?? $state),
                TextColumn::make('employee.name')->label('Empleado')->searchable()->sortable(),
                TextColumn::make('team.name')->label('Team'),
                TextColumn::make('campaign.name')->label('Campaña'),
                TextColumn::make('type')->label('Tipo')->badge()->formatStateUsing(fn (string $state) => self::typeOptions()[$state] ?? $state),
                TextColumn::make('amount')->label('Monto')->money('HNL', locale: 'en-US')->sortable(),
            ])
            ->filters([
                SelectFilter::make('payroll_period_id')->relationship('payrollPeriod', 'name', modifyQueryUsing: fn (Builder $query) => $query->open())->label('Período'),
                SelectFilter::make('type')->label('Tipo')->options(self::typeOptions()),
            ])
            ->recordActions([
                EditAction::make()->label('Editar'),
                DeleteAction::make()->label('Eliminar'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->label('Eliminar'),
                ]),
            ]);
    }

    public static function typeOptions(): array
    {
        return [
            'qa' => 'Bono QA',
            'productivity' => 'Bono de Productividad',
            'time_management' => 'Bono TM',
            'manual' => 'Manual',
            'referred' => 'Bono referido',
            'adjustment' => 'Ajuste Cambio de Tier',
            'vacation' => 'Vacaciones',
            'other' => 'Otro',
            'internet_subsidy' => 'Subsidio por internet',
        ];
    }

    public static function statusOptions(): array
    {
        return [
            'propuesto' => 'Propuesto',
            'aprobado' => 'Aprobado',
            'rechazado' => 'Rechazado',
        ];
    }

    public static function normalizeScopeData(array $data): array
    {
        if (auth()->user()?->isSupervisor()) {
            $data['scope_type'] = 'employee';
            $data['team_id'] = null;
            $data['campaign_id'] = null;

            $employeeIsAllowed = Employee::query()
                ->visibleTo(auth()->user())
                ->whereKey($data['employee_id'] ?? null)
                ->exists();

            if (! $employeeIsAllowed) {
                throw ValidationException::withMessages([
                    'employee_id' => 'Solo puedes seleccionar empleados asignados a tu supervisión.',
                ]);
            }
        }

        if (($data['scope_type'] ?? null) !== 'employee') {
            $data['employee_id'] = null;
        }

        if (($data['scope_type'] ?? null) !== 'team') {
            $data['team_id'] = null;
        }

        if (($data['scope_type'] ?? null) !== 'campaign') {
            $data['campaign_id'] = null;
        }

        $data['status'] = $data['status'] ?? 'aprobado';

        return $data;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPayrollBonuses::route('/'),
            'create' => CreatePayrollBonus::route('/create'),
            'edit' => EditPayrollBonus::route('/{record}/edit'),
        ];
    }
}
