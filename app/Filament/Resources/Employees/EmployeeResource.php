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
            TextInput::make('email')
                ->label('Correo para voucher')
                ->email()
                ->maxLength(255)
                ->nullable(),
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
                ->required(),
            Select::make('work_schedule_template_id')
                ->label('Plantilla de horario')
                ->relationship('workScheduleTemplate', 'name')
                ->searchable()
                ->preload()
                ->nullable(),
            DatePicker::make('schedule_cycle_anchor_date')
                ->label('Inicio del ciclo rotativo')
                ->helperText('Selecciona el primer día laborado del ciclo.')
                ->visible(fn (Get $get) => self::scheduleCode($get('schedule_type_id')) === 'rotativa')
                ->required(fn (Get $get) => self::scheduleCode($get('schedule_type_id')) === 'rotativa'),
            TextInput::make('rotation_work_days')->label('Días trabajados del ciclo')->numeric()->minValue(1)->default(4)->visible(fn (Get $get) => self::scheduleCode($get('schedule_type_id')) === 'rotativa'),
            TextInput::make('rotation_rest_days')->label('Días de descanso del ciclo')->numeric()->minValue(0)->default(4)->visible(fn (Get $get) => self::scheduleCode($get('schedule_type_id')) === 'rotativa'),
            Select::make('contract_type_id')->label('Tipo de contrato')->relationship('contractType', 'name')->searchable()->preload()->required(),
            Select::make('supervisor_user_id')
                ->label('Supervisor')
                ->options(fn (?Employee $record) => User::query()
                    ->whereIn('profile', ['supervisor', 'rrhh'])
                    ->where(fn ($query) => $query
                        ->where('active', true)
                        ->when($record?->supervisor_user_id, fn ($query, int $supervisorId) => $query->orWhere('id', $supervisorId)))
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
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
            Select::make('salary_calculation_method')->label('Método de cálculo salarial')->options(self::salaryCalculationMethodOptions())->default('hourly_actual_hours')->required(),
            Toggle::make('salary_values_are_manual')->label('Valores salariales manuales')->helperText('Los valores de esta ficha tienen prioridad sobre Tier y fórmulas sugeridas.')->default(true),
            TextInput::make('ordinary_weekly_hours')->label('Horas ordinarias semanales')->helperText('No incluir horas extra preasignadas.')->numeric()->minValue(0)->default(0),
            TextInput::make('daily_hours')->label('Horas diarias')->numeric()->minValue(0)->default(0),
            TextInput::make('calendar_days')->label('Días')->numeric()->default(30)->readOnly(),

            TextInput::make('monthly_salary')->label('Salario mensual')->numeric()->minValue(0)->default(0),
            TextInput::make('semi_monthly_salary')->label('Pago quincenal')->numeric()->minValue(0)->default(0),
            TextInput::make('daily_rate')->label('Pago por día')->numeric()->minValue(0)->default(0),
            TextInput::make('hourly_rate')->label('Pago por hora')->numeric()->minValue(0)->default(0),
            TextInput::make('preassigned_overtime_weekly_hours')
                ->label('Horas extra preasignadas por semana')
                ->helperText(fn (Get $get) => self::assignedOvertimeHelp($get('schedule_type_id')))
                ->numeric()
                ->minValue(0)
                ->default(0)
                ->rules([fn (Get $get): Closure => self::assignedOvertimeRule($get)]),
            TextInput::make('preassigned_overtime_period_hours')->label('Horas extra preasignadas del período')->helperText('Opcional. En cero se calcula desde la configuración semanal y los días programados.')->numeric()->minValue(0)->default(0),
            TextInput::make('overtime_hourly_rate')->label('Pago hora extra')->numeric()->minValue(0)->default(0),
            TextInput::make('hubstaff_expected_hours_per_workday')->label('Horas esperadas Hubstaff por día trabajado')->numeric()->minValue(0)->nullable()->visible(fn (Get $get) => self::scheduleCode($get('schedule_type_id')) === 'rotativa'),
            TextInput::make('paid_hours_per_workday')->label('Horas pagadas por día trabajado')->numeric()->minValue(0)->nullable()->visible(fn (Get $get) => self::scheduleCode($get('schedule_type_id')) === 'rotativa'),
            TextInput::make('paid_lunch_minutes_per_workday')->label('Lunch pagado no trackeado (minutos)')->numeric()->minValue(0)->default(0)->visible(fn (Get $get) => self::scheduleCode($get('schedule_type_id')) === 'rotativa'),
            Toggle::make('lunch_included_in_hubstaff_total')->label('Lunch incluido en el total de Hubstaff')->default(true)->visible(fn (Get $get) => self::scheduleCode($get('schedule_type_id')) === 'rotativa'),
            TextInput::make('paid_break_minutes_per_workday')->label('Breaks pagados por día (minutos)')->numeric()->minValue(0)->default(0)->visible(fn (Get $get) => self::scheduleCode($get('schedule_type_id')) === 'rotativa'),
            Toggle::make('breaks_included_in_hubstaff_total')->label('Breaks incluidos en el total de Hubstaff')->default(true)->visible(fn (Get $get) => self::scheduleCode($get('schedule_type_id')) === 'rotativa'),
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
                TextColumn::make('email')->label('Correo')->searchable()->toggleable(),
                TextColumn::make('name')->label('Nombre')->searchable()->sortable(),
                TextColumn::make('campaign.name')->label('Campaña')->sortable(),
                TextColumn::make('team.name')->label('Team')->sortable(),
                TextColumn::make('department.name')->label('Departamento')->toggleable(),
                TextColumn::make('workRole.name')->label('Rol')->toggleable(),
                TextColumn::make('tierLevel.name')->label('Tier')->toggleable(),
                TextColumn::make('daily_hours')->label('Horas diarias')->numeric()->sortable(),
                TextColumn::make('preassigned_overtime_weekly_hours')->label('Horas extra preasignadas')->numeric()->sortable(),
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
        $data['calendar_days'] = max((float) ($data['calendar_days'] ?? 30), 1);
        $data['weekly_hours'] = max((float) ($data['ordinary_weekly_hours'] ?? 0), 0);
        $data['overtime_hours'] = max((float) ($data['preassigned_overtime_weekly_hours'] ?? 0), 0);
        $data['salary_values_are_manual'] = (bool) ($data['salary_values_are_manual'] ?? true);
        unset($data['monthly_overtime_amount']);

        if (self::isTierOne((int) ($data['tier_level_id'] ?? 0))) {
            $data['contract_type_id'] = self::trialContractTypeId();
        }

        return $data;
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
            'diurna' => 'Horas extra preasignadas por semana. Se permiten fracciones como 0.5 o 1.25.',
            'rotativa' => 'Configura las horas extra preasignadas reales del ciclo semanal del empleado.',
            default => 'Horas extra previamente asignadas al empleado; no son monto monetario.',
        };
    }

    private static function assignedOvertimeRule(Get $get): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($get): void {
            $hours = (float) ($value ?? 0);
            if (! $get('can_work_overtime') && $hours > 0) {
                $fail('El empleado no puede tener horas extra asignadas si la opción Puede hacer horas extra está desactivada.');
            }
        };
    }

    public static function salaryCalculationMethodOptions(): array
    {
        return [
            'hourly_actual_hours' => 'Horas reales pagables',
            'semi_monthly_fixed_with_deductions' => 'Quincenal fijo con deducciones',
            'monthly_calendar_prorated' => 'Mensual prorrateado por días',
            'scheduled_shift_prorated' => 'Prorrateado por jornada programada',
        ];
    }

    /**
     * @return array<string, float>
     */
    public static function suggestedCompensation(Employee $employee): array
    {
        $hourlyRate = (float) $employee->tierLevel?->hourly_rate
            ?: (float) $employee->hourly_rate;
        $ordinaryWeeklyHours = (float) $employee->ordinary_weekly_hours
            ?: (float) $employee->weekly_hours
            ?: (float) $employee->tierLevel?->weekly_hours;
        $dailyHours = (float) $employee->daily_hours
            ?: ($ordinaryWeeklyHours > 0 ? $ordinaryWeeklyHours / 5 : 0);
        $calendarDays = max((float) $employee->calendar_days, 30);
        $monthlySalary = (float) $employee->tierLevel?->monthly_salary
            ?: ($dailyHours * $hourlyRate * $calendarDays);

        return [
            'ordinary_weekly_hours' => round($ordinaryWeeklyHours, 2),
            'weekly_hours' => round($ordinaryWeeklyHours, 2),
            'daily_hours' => round($dailyHours, 2),
            'monthly_salary' => round($monthlySalary, 2),
            'semi_monthly_salary' => round($monthlySalary / 2, 2),
            'daily_rate' => round($monthlySalary / $calendarDays, 4),
            'hourly_rate' => round($hourlyRate, 4),
            'overtime_hourly_rate' => round($hourlyRate * 1.25, 4),
        ];
    }
}
