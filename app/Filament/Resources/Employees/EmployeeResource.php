<?php

namespace App\Filament\Resources\Employees;

use App\Filament\Resources\Employees\Pages\CreateEmployee;
use App\Filament\Resources\Employees\Pages\EditEmployee;
use App\Filament\Resources\Employees\Pages\ListEmployees;
use App\Models\ContractType;
use App\Models\Employee;
use App\Models\ScheduleType;
use App\Models\TierLevel;
use App\Models\User;
use BackedEnum;
use Closure;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
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
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class EmployeeResource extends Resource
{
    protected static ?string $model = Employee::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $navigationLabel = 'Empleados';

    protected static ?string $modelLabel = 'empleado';

    protected static ?string $pluralModelLabel = 'empleados';

    protected static string|\UnitEnum|null $navigationGroup = 'RRHH';

    protected static ?int $navigationSort = 10;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->visibleTo(auth()->user())
            ->with(['campaign', 'team', 'department', 'workRole', 'tierLevel', 'scheduleType']);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->isRrhh() ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->isRrhh() ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->isRrhh() ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->isRrhh() ?? false;
    }

    public static function canView(Model $record): bool
    {
        return auth()->user()?->isRrhh() ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->isRrhh() ?? false;
    }

    public static function canDeleteAny(): bool
    {
        return auth()->user()?->isRrhh() ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('dni')
                ->label('DNI')
                ->maxLength(255)
                ->afterStateHydrated(fn (TextInput $component, ?Employee $record) => $component->state($record?->dni))
                ->unique(ignoreRecord: true),
            TextInput::make('bank_account_number')
                ->label('Número de cuenta')
                ->maxLength(255)
                ->afterStateHydrated(fn (TextInput $component, ?Employee $record) => $component->state($record?->bank_account_number))
                ->unique(ignoreRecord: true),
            TextInput::make('name')->label('Nombre')->required()->maxLength(255),
            TextInput::make('hubstaff_name')
                ->label('Nombre Hubstaff')
                ->maxLength(255),
            Select::make('campaign_id')->label('Campaña')->relationship('campaign', 'name')->searchable()->preload()->required(),
            Select::make('team_id')->label('Team')->relationship('team', 'name')->searchable()->preload()->required(),
            Select::make('department_id')->label('Departamento')->relationship('department', 'name')->searchable()->preload()->required(),
            Select::make('work_role_id')->label('Rol laboral')->relationship('workRole', 'name')->searchable()->preload()->required(),
            Select::make('tier_level_id')
                ->label('Nivel salarial')
                ->relationship('tierLevel', 'name')
                ->searchable()
                ->preload()
                ->live()
                ->afterStateUpdated(function (?int $state, Set $set): void {
                    if (self::isTierOne($state)) {
                        $set('contract_type_id', self::trialContractTypeId());
                    }
                })
                ->required(),
            Select::make('schedule_type_id')
                ->label('Jornada')
                ->relationship('scheduleType', 'name')
                ->searchable()
                ->preload()
                ->live()
                ->afterStateUpdated(function (?int $state, Get $get, Set $set): void {
                    if (self::scheduleCode($state) === 'rotativa') {
                        $set('weekly_hours', 40);
                        $set('daily_hours', 10);
                        $set('overtime_hours', 4);
                        self::syncCalculatedPayFields($get, $set);
                    }
                })
                ->required(),
            DatePicker::make('schedule_cycle_anchor_date')
                ->label('Inicio del ciclo rotativo')
                ->helperText('Selecciona el primer día laborado de un bloque de cuatro días.')
                ->visible(fn (Get $get) => self::scheduleCode($get('schedule_type_id')) === 'rotativa')
                ->required(fn (Get $get) => self::scheduleCode($get('schedule_type_id')) === 'rotativa'),
            Select::make('contract_type_id')->label('Tipo de contrato')->relationship('contractType', 'name')->searchable()->preload()->required(),
            Select::make('supervisor_user_id')
                ->label('Supervisor')
                ->options(fn () => User::query()->where('profile', 'supervisor')->where('active', true)->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()
                ->preload()
                ->nullable(),
            Select::make('user_account_id')
                ->label('Usuario relacionado')
                ->options(fn (?Employee $record) => User::query()
                    ->where(fn ($query) => $query->whereNull('employee_id')->when($record, fn ($query) => $query->orWhere('employee_id', $record->id)))
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
                ->afterStateHydrated(fn (Select $component, ?Employee $record) => $component->state($record?->userAccount?->id))
                ->searchable()
                ->preload()
                ->nullable(),
            TextInput::make('weekly_hours')->label('Horas ordinarias semanales')->helperText('No incluir horas extra asignadas en este valor.')->numeric()->default(0),
            TextInput::make('daily_hours')
                ->label('Horas diarias')
                ->numeric()
                ->default(0)
                ->live(onBlur: true)
                ->afterStateUpdated(fn (Get $get, Set $set) => self::syncCalculatedPayFields($get, $set)),
            TextInput::make('calendar_days')->label('Días')->numeric()->default(30)->readOnly(),

            TextInput::make('monthly_salary')->label('Salario mensual')->numeric()->default(0)->readOnly(),
            TextInput::make('biweekly_salary')->label('Pago quincenal')->numeric()->default(0)->readOnly()->dehydrated(false)->afterStateHydrated(fn (TextInput $component, ?Employee $record) => $component->state($record ? round(((float) $record->monthly_salary) / 2, 2) : 0)),
            TextInput::make('daily_rate')->label('Pago por día')->numeric()->default(0)->readOnly(),
            TextInput::make('hourly_rate')
                ->label('Pago por hora')
                ->numeric()
                ->default(0)
                ->live(onBlur: true)
                ->afterStateUpdated(fn (Get $get, Set $set) => self::syncCalculatedPayFields($get, $set)),
            TextInput::make('overtime_hours')
                ->label('Horas extra asignadas')
                ->helperText(fn (Get $get) => self::assignedOvertimeHelp($get('schedule_type_id')))
                ->numeric()
                ->live(onBlur: true)
                ->minValue(0)
                ->default(0)
                ->afterStateUpdated(fn (Get $get, Set $set) => self::syncCalculatedPayFields($get, $set))
                ->rules([fn (Get $get): Closure => self::assignedOvertimeRule($get)]),
            TextInput::make('overtime_hourly_rate')->label('Valor hora extra')->helperText('Pago por hora x 1.25.')->numeric()->default(0)->readOnly(),
            Toggle::make('can_work_overtime')->label('Puede hacer horas extra')->default(true),
            Select::make('location')->label('Ubicación')->options(['on_site' => 'Presencial', 'remote' => 'Remoto'])->default('on_site')->required(),
            TextInput::make('internet_subsidy_amount')->label('Subsidio de internet')->numeric()->default(0),
            Toggle::make('applies_private_insurance')->label('Aplica Pan Ame Seguro')->default(false),
            Toggle::make('applies_ihss')->label('Aplica IHSS')->default(true),
            Toggle::make('applies_isr')->label('Aplica ISR')->default(false),
            Toggle::make('applies_rap')->label('Aplica RAP')->default(false),
            Toggle::make('active')->label('Activo')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('dni')
                    ->label('DNI')
                    ->state(fn (Employee $record): ?string => $record->dni)
                    ->searchable(),
                TextColumn::make('bank_account_number')
                    ->label('No. cuenta')
                    ->state(fn (Employee $record): ?string => $record->bank_account_number)
                    ->searchable(),
                TextColumn::make('name')->label('Nombre')->searchable()->sortable(),
                TextColumn::make('campaign.name')->label('Campaña')->sortable(),
                TextColumn::make('team.name')->label('Team')->sortable(),
                TextColumn::make('department.name')->label('Departamento')->toggleable(),
                TextColumn::make('workRole.name')->label('Rol')->toggleable(),
                TextColumn::make('tierLevel.name')->label('Tier')->toggleable(),
                TextColumn::make('daily_hours')->label('Horas diarias')->numeric()->sortable(),
                TextColumn::make('overtime_hours')->label('Horas extra asignadas')->numeric()->sortable(),
                TextColumn::make('hourly_rate')->label('Pago por hora')->money('HNL', locale: 'en-US')->sortable(),
                TextColumn::make('overtime_hourly_rate')->label('Valor hora extra')->money('HNL', locale: 'en-US')->toggleable(),
                IconColumn::make('active')->label('Activo')->boolean(),
            ])
            ->filters([
                SelectFilter::make('campaign_id')->label('Campaña')->relationship('campaign', 'name'),
                SelectFilter::make('team_id')->label('Team')->relationship('team', 'name'),
                SelectFilter::make('department_id')->label('Departamento')->relationship('department', 'name'),
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
            'index' => ListEmployees::route('/'),
            'create' => CreateEmployee::route('/create'),
            'edit' => EditEmployee::route('/{record}/edit'),
        ];
    }

    public static function syncUserAccount(Employee $employee, ?int $userId): void
    {
        User::query()->where('employee_id', $employee->id)->update(['employee_id' => null]);

        if ($userId) {
            User::query()->whereKey($userId)->update(['employee_id' => $employee->id]);
        }
    }

    public static function normalizeCompensation(array $data): array
    {
        $hourlyRate = (float) ($data['hourly_rate'] ?? 0);
        $dailyHours = (float) ($data['daily_hours'] ?? 0);
        $weeklyHours = (float) ($data['weekly_hours'] ?? 0);
        $compensationDailyHours = self::compensationDailyHours(
            (int) ($data['schedule_type_id'] ?? 0),
            $dailyHours,
            $weeklyHours,
        );
        $monthlySalary = $compensationDailyHours * $hourlyRate * 30;
        $overtimeHourlyRate = $hourlyRate * 1.25;

        $data['calendar_days'] = 30;
        $data['monthly_salary'] = round($monthlySalary, 2);
        $data['daily_rate'] = round($monthlySalary / 30, 4);
        $data['overtime_hourly_rate'] = round($overtimeHourlyRate, 4);
        unset($data['monthly_overtime_amount']);

        if (self::isTierOne((int) ($data['tier_level_id'] ?? 0))) {
            $data['contract_type_id'] = self::trialContractTypeId();
        }

        return $data;
    }

    private static function syncCalculatedPayFields(Get $get, Set $set): void
    {
        $hourlyRate = (float) ($get('hourly_rate') ?? 0);
        $compensationDailyHours = self::compensationDailyHours(
            (int) ($get('schedule_type_id') ?? 0),
            (float) ($get('daily_hours') ?? 0),
            (float) ($get('weekly_hours') ?? 0),
        );
        $monthlySalary = $compensationDailyHours * $hourlyRate * 30;
        $overtimeHourlyRate = $hourlyRate * 1.25;

        $set('calendar_days', 30);
        $set('monthly_salary', round($monthlySalary, 2));
        $set('biweekly_salary', round($monthlySalary / 2, 2));
        $set('daily_rate', round($monthlySalary / 30, 4));
        $set('overtime_hourly_rate', round($overtimeHourlyRate, 4));
    }

    private static function isTierOne(?int $tierLevelId): bool
    {
        return $tierLevelId
            ? TierLevel::query()->whereKey($tierLevelId)->where('name', 'Tier 1')->exists()
            : false;
    }

    private static function trialContractTypeId(): ?int
    {
        return ContractType::query()->where('code', 'trial_period')->value('id');
    }

    private static function scheduleCode(?int $scheduleTypeId): ?string
    {
        if (! $scheduleTypeId) {
            return null;
        }

        return ScheduleType::query()->whereKey($scheduleTypeId)->value('code');
    }

    private static function assignedOvertimeHelp(?int $scheduleTypeId): string
    {
        return match (self::scheduleCode($scheduleTypeId)) {
            'diurna' => 'Para jornada diurna, el máximo permitido es 8 horas extra asignadas.',
            'rotativa' => 'La jornada rotativa usa 40 horas ordinarias y 4 horas extra por cada bloque de cuatro días laborados.',
            default => 'Horas extra previamente asignadas al empleado; no son monto monetario.',
        };
    }

    private static function compensationDailyHours(
        ?int $scheduleTypeId,
        float $dailyHours,
        float $weeklyHours,
    ): float {
        if (self::scheduleCode($scheduleTypeId) === 'rotativa' && $weeklyHours > 0) {
            return $weeklyHours / 5;
        }

        return $dailyHours;
    }

    private static function assignedOvertimeRule(Get $get): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($get): void {
            $hours = (float) ($value ?? 0);
            $scheduleCode = self::scheduleCode($get('schedule_type_id'));

            if ($scheduleCode === 'diurna' && $hours > 8) {
                $fail('La jornada diurna permite máximo 8 horas extra asignadas.');
            }

            if ($scheduleCode === 'rotativa' && $hours !== 4.0) {
                $fail('La jornada rotativa debe tener exactamente 4 horas extra asignadas.');
            }

            if (! $get('can_work_overtime') && $hours > 0) {
                $fail('El empleado no puede tener horas extra asignadas si la opción Puede hacer horas extra está desactivada.');
            }
        };
    }
}
