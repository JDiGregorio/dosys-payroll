<?php

namespace App\Filament\Resources\PayrollOvertimeAdjustments;

use App\Filament\Resources\PayrollOvertimeAdjustments\Pages\CreatePayrollOvertimeAdjustment;
use App\Filament\Resources\PayrollOvertimeAdjustments\Pages\EditPayrollOvertimeAdjustment;
use App\Filament\Resources\PayrollOvertimeAdjustments\Pages\ListPayrollOvertimeAdjustments;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\PayrollOvertimeAdjustment;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class PayrollOvertimeAdjustmentResource extends Resource
{
    protected static ?string $model = PayrollOvertimeAdjustment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $navigationLabel = 'Horas extras adicionales';

    protected static ?string $modelLabel = 'hora extra adicional';

    protected static ?string $pluralModelLabel = 'horas extras adicionales';

    protected static string|\UnitEnum|null $navigationGroup = 'Planilla';

    protected static ?int $navigationSort = 55;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->whereHas('payrollPeriod', fn (Builder $query) => $query->open())
            ->with(['employee', 'payrollPeriod']);

        if (auth()->user()?->isSupervisor()) {
            $query->whereHas('employee', fn (Builder $query) => $query->visibleTo(auth()->user()));
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
        return self::canEdit($record);
    }

    public static function canDeleteAny(): bool
    {
        return (bool) auth()->user()?->active;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return (bool) auth()->user()?->active;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('payroll_period_id')
                ->label('Período')
                ->relationship('payrollPeriod', 'name', modifyQueryUsing: fn (Builder $query) => $query->open())
                ->searchable()
                ->preload()
                ->required(),
            Select::make('employee_id')
                ->label('Empleado')
                ->relationship('employee', 'name', modifyQueryUsing: fn (Builder $query) => $query->visibleTo(auth()->user()))
                ->searchable()
                ->preload()
                ->live()
                ->afterStateUpdated(function (?int $state, Get $get, Set $set): void {
                    if ($state) {
                        $set('hourly_rate', self::employeeOvertimeRate($state));
                    }

                    self::syncAmount($get, $set);
                })
                ->required(),
            TextInput::make('hours')
                ->label('Horas adicionales')
                ->helperText('Solo se pagarán hasta el excedente real registrado en Hubstaff sobre las horas normales y preasignadas.')
                ->numeric()
                ->minValue(0.01)
                ->default(0)
                ->live(onBlur: true)
                ->afterStateUpdated(fn (Get $get, Set $set) => self::syncAmount($get, $set))
                ->required(),
            TextInput::make('hourly_rate')
                ->label('Valor hora extra')
                ->numeric()
                ->minValue(0)
                ->default(0)
                ->live(onBlur: true)
                ->afterStateUpdated(fn (Get $get, Set $set) => self::syncAmount($get, $set))
                ->required(),
            TextInput::make('amount')->label('Monto')->numeric()->readOnly()->default(0),
            TextInput::make('description')->label('Descripción')->maxLength(255),
            Toggle::make('active')->label('Activo')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('payrollPeriod.name')->label('Período')->sortable(),
                TextColumn::make('employee.name')->label('Empleado')->searchable()->sortable(),
                TextColumn::make('hours')->label('Horas')->numeric()->sortable(),
                TextColumn::make('hourly_rate')->label('Valor hora')->money('HNL', locale: 'en-US'),
                TextColumn::make('amount')->label('Monto')->money('HNL', locale: 'en-US')->sortable(),
                TextColumn::make('description')->label('Descripción')->limit(40),
                IconColumn::make('active')->label('Activo')->boolean(),
            ])
            ->filters([
                SelectFilter::make('payroll_period_id')->label('Período')->relationship('payrollPeriod', 'name', modifyQueryUsing: fn (Builder $query) => $query->open()),
                SelectFilter::make('employee_id')
                    ->label('Empleado')
                    ->relationship('employee', 'name', modifyQueryUsing: fn (Builder $query) => $query->visibleTo(auth()->user()))
                    ->searchable(),
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

    public static function normalizeAmount(array $data): array
    {
        if (auth()->user()?->isSupervisor()) {
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

        $data['amount'] = round((float) ($data['hours'] ?? 0) * (float) ($data['hourly_rate'] ?? 0), 2);

        return $data;
    }

    private static function employeeOvertimeRate(int $employeeId): float
    {
        $employee = Employee::query()->find($employeeId);

        return (float) $employee?->overtime_hourly_rate ?: (float) $employee?->hourly_rate * 1.25;
    }

    private static function syncAmount(Get $get, Set $set): void
    {
        $set('amount', round((float) ($get('hours') ?? 0) * (float) ($get('hourly_rate') ?? 0), 2));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPayrollOvertimeAdjustments::route('/'),
            'create' => CreatePayrollOvertimeAdjustment::route('/create'),
            'edit' => EditPayrollOvertimeAdjustment::route('/{record}/edit'),
        ];
    }
}
