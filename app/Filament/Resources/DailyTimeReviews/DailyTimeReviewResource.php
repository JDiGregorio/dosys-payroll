<?php

namespace App\Filament\Resources\DailyTimeReviews;

use App\Filament\Resources\DailyTimeReviews\Pages\CreateDailyTimeReview;
use App\Filament\Resources\DailyTimeReviews\Pages\EditDailyTimeReview;
use App\Filament\Resources\DailyTimeReviews\Pages\ListDailyTimeReviews;
use App\Models\DailyTimeReview;
use App\Services\PayrollCalculationService;
use App\Services\TimeParserService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class DailyTimeReviewResource extends Resource
{
    protected static ?string $model = DailyTimeReview::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $navigationLabel = 'Revisión diaria';

    protected static ?string $modelLabel = 'revisión diaria';

    protected static ?string $pluralModelLabel = 'revisión diaria';

    protected static string|\UnitEnum|null $navigationGroup = 'Planilla';

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereHas('employee', fn (Builder $query) => $query->visibleTo(auth()->user()))
            ->with(['employee.campaign', 'employee.team', 'payrollPeriod']);
    }

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->active;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        $user = auth()->user();

        return $user?->isRrhh()
            || ($user?->isSupervisor() && $record->employee?->supervisor_user_id === $user->id && $record->payrollPeriod?->status !== 'cerrado');
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('Revisión diaria')
                ->columnSpanFull()
                ->tabs([
                    Tab::make('Revisión y justificación')
                        ->columns(2)
                        ->schema([
                            Select::make('payroll_period_id')->label('Período')->relationship('payrollPeriod', 'name')->required()->disabled(),
                            Select::make('employee_id')
                                ->label('Empleado')
                                ->relationship('employee', 'name', modifyQueryUsing: fn (Builder $query) => $query->visibleTo(auth()->user()))
                                ->searchable()
                                ->preload()
                                ->required()
                                ->disabled(),
                            DatePicker::make('date')->label('Fecha')->required()->disabled(),
                            TextInput::make('expected_hours')->label('Horas ordinarias esperadas')->disabled()->dehydrated(false)->afterStateHydrated(fn (TextInput $component, ?DailyTimeReview $record) => $component->state($record ? app(TimeParserService::class)->secondsToHourMinuteSecond($record->expected_ordinary_seconds) : '0:00:00')),
                            TextInput::make('expected_hubstaff_hours')->label('Horas esperadas Hubstaff')->disabled()->dehydrated(false)->afterStateHydrated(fn (TextInput $component, ?DailyTimeReview $record) => $component->state($record ? app(TimeParserService::class)->secondsToHourMinuteSecond($record->expected_hubstaff_seconds) : '0:00:00')),
                            TextInput::make('expected_paid_hours')->label('Horas pagadas esperadas')->disabled()->dehydrated(false)->afterStateHydrated(fn (TextInput $component, ?DailyTimeReview $record) => $component->state($record ? app(TimeParserService::class)->secondsToHourMinuteSecond($record->expected_paid_seconds) : '0:00:00')),
                            TextInput::make('paid_time_not_tracked_hours')->label('Tiempo pagado no trackeado')->disabled()->dehydrated(false)->afterStateHydrated(fn (TextInput $component, ?DailyTimeReview $record) => $component->state($record ? app(TimeParserService::class)->secondsToHourMinuteSecond($record->paid_time_not_tracked_seconds) : '0:00:00')),
                            TextInput::make('assigned_overtime_hours')->label('Horas extra preasignadas')->disabled()->dehydrated(false)->afterStateHydrated(fn (TextInput $component, ?DailyTimeReview $record) => $component->state($record ? app(TimeParserService::class)->secondsToHourMinuteSecond((int) $record->preassigned_overtime_seconds) : '0:00:00')),
                            TextInput::make('additional_overtime_hours')->label('Horas extra adicionales')->disabled()->dehydrated(false)->afterStateHydrated(fn (TextInput $component, ?DailyTimeReview $record) => $component->state($record ? app(TimeParserService::class)->secondsToHourMinuteSecond((int) $record->additional_overtime_seconds) : '0:00:00')),
                            TextInput::make('required_hours')->label('Total requerido')->disabled()->dehydrated(false)->afterStateHydrated(fn (TextInput $component, ?DailyTimeReview $record) => $component->state($record ? app(TimeParserService::class)->secondsToHourMinuteSecond(self::requiredSeconds($record)) : '0:00:00')),
                            TextInput::make('hubstaff_total_hours')->label('Total horas Hubstaff')->disabled()->dehydrated(false)->visible(fn (?DailyTimeReview $record) => self::hasHubstaffTime($record))->afterStateHydrated(fn (TextInput $component, ?DailyTimeReview $record) => $component->state($record ? app(TimeParserService::class)->secondsToHourMinuteSecond($record->hubstaff_total_seconds) : '0:00:00')),
                            TextInput::make('hubstaff_idle_hours')->label('Idle reportado por Hubstaff')->helperText('Es un dato independiente enviado por Hubstaff; no representa necesariamente la diferencia contra las horas requeridas.')->disabled()->dehydrated(false)->visible(fn (?DailyTimeReview $record) => self::hasHubstaffTime($record))->afterStateHydrated(fn (TextInput $component, ?DailyTimeReview $record) => $component->state($record ? app(TimeParserService::class)->secondsToHourMinuteSecond($record->hubstaff_idle_seconds) : '0:00:00')),
                            TextInput::make('lost_time_hours')->label('Tiempo no trabajado')->disabled()->dehydrated(false)->visible(fn (?DailyTimeReview $record) => self::hasHubstaffTime($record))->afterStateHydrated(fn (TextInput $component, ?DailyTimeReview $record) => $component->state($record ? app(TimeParserService::class)->secondsToHourMinuteSecond(self::lostTimeSeconds($record)) : '0:00:00')),
                            TextInput::make('justified_lost_time_hours')
                                ->label('Tiempo justificado')
                                ->helperText('Formato HH:MM. Se suma al tiempo de Hubstaff hasta completar el total requerido.')
                                ->placeholder('00:00')
                                ->rules(['regex:/^\d{1,3}:[0-5]\d$/'])
                                ->validationMessages(['regex' => 'Ingresa el tiempo con formato HH:MM, por ejemplo 1:05.'])
                                ->visible(fn (?DailyTimeReview $record) => self::hasHubstaffTime($record))
                                ->afterStateHydrated(fn (TextInput $component, ?DailyTimeReview $record) => $component->state($record ? app(TimeParserService::class)->secondsToHourMinute($record->justified_absence_seconds) : '0:00')),
                            Toggle::make('assigned_overtime_fulfilled')
                                ->label('Cumplió la hora extra asignada')
                                ->helperText('Actívalo cuando el supervisor confirma que la hora extra se cumplió; cualquier faltante no justificado se descontará como tiempo normal. Si queda inactivo, se paga proporcionalmente el tiempo extra trabajado o justificado después de completar las horas normales.')
                                ->visible(fn (?DailyTimeReview $record) => self::hasHubstaffTime($record)
                                    && ((float) $record?->employee?->preassigned_overtime_weekly_hours > 0
                                        || (float) $record?->employee?->overtime_hours > 0)),
                            Toggle::make('paid_day_off')->label('Día libre (OFF)')->helperText('Marca este día cuando no hubo registro porque era día libre y debe pagarse completo.')->visible(fn (?DailyTimeReview $record) => ! self::hasHubstaffTime($record)),
                            Toggle::make('absence_justified')->label('Ausencia justificada')->helperText('Marca si no hubo registro, pero el día debe pagarse por permiso o constancia.')->visible(fn (?DailyTimeReview $record) => ! self::hasHubstaffTime($record))->afterStateHydrated(fn (Toggle $component, ?DailyTimeReview $record) => $component->state($record ? self::isFullyJustifiedAbsence($record) : false)),
                            Textarea::make('supervisor_comment')->label('Comentario supervisor')->columnSpanFull(),
                            Textarea::make('rrhh_comment')->label('Comentario RRHH')->visible(fn () => auth()->user()?->isRrhh())->columnSpanFull(),
                        ]),
                    Tab::make('Registros de Hubstaff')
                        ->columns(1)
                        ->schema([
                            View::make('filament.resources.daily-time-reviews.hubstaff-entries')
                                ->columnSpanFull(),
                        ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('payrollPeriod.name')->label('Período')->sortable(),
                TextColumn::make('date')->label('Fecha')->date()->sortable(),
                TextColumn::make('employee.name')->label('Empleado')->searchable()->sortable(),
                TextColumn::make('employee.campaign.name')->label('Campaña')->toggleable(),
                TextColumn::make('employee.team.name')->label('Team')->toggleable(),
                self::hoursColumn('expected_hubstaff_seconds', 'Esperadas Hubstaff'),
                self::hoursColumn('expected_paid_seconds', 'Pagadas esperadas'),
                self::hoursColumn('paid_time_not_tracked_seconds', 'Pagado no trackeado'),
                self::hoursColumn('preassigned_overtime_seconds', 'Extra preasignada'),
                self::hoursColumn('hubstaff_total_seconds', 'Horas Hubstaff'),
                self::hoursColumn('hubstaff_idle_seconds', 'Idle reportado'),
                self::hoursColumn('justified_idle_seconds', 'Idle justificado'),
                self::hoursColumn('justified_absence_seconds', 'Ausencia justificada'),
                self::hoursColumn('payable_seconds', 'Horas pagables'),
                self::hoursColumn('difference_seconds', 'Diferencia'),
                IconColumn::make('assigned_overtime_fulfilled')->label('Hora extra cumplida')->boolean()->toggleable(),
                TextColumn::make('status')->label('Estado')->badge()->state(fn (DailyTimeReview $record): string => self::displayStatusLabel($record)),
                TextColumn::make('supervisor_comment')->label('Comentario supervisor')->limit(30)->toggleable(),
                TextColumn::make('rrhh_comment')->label('Comentario RRHH')->limit(30)->toggleable(),
            ])
            ->filters([
                SelectFilter::make('payroll_period_id')->relationship('payrollPeriod', 'name')->label('Período'),
                SelectFilter::make('status')->label('Estado')->options(self::statusOptions()),
            ])
            ->recordActions([
                EditAction::make()
                    ->label('Editar revisión')
                    ->mutateRecordDataUsing(fn (array $data, DailyTimeReview $record): array => $data + self::hourStates($record))
                    ->mutateFormDataUsing(fn (array $data, DailyTimeReview $record): array => self::secondsFromHourStates($data, $record))
                    ->after(fn (DailyTimeReview $record, PayrollCalculationService $service) => self::recalculateReviewAndPayroll($record, $service)),
                Action::make('reviewed')
                    ->label('Marcar aplicado')
                    ->icon('heroicon-o-check')
                    ->action(function (DailyTimeReview $record, PayrollCalculationService $service): void {
                        $record->update(['status' => 'revisado_supervisor', 'reviewed_by' => auth()->id()]);
                        self::recalculateReviewAndPayroll($record, $service);
                    }),
                Action::make('paidDayOff')
                    ->label('Marcar off pagado')
                    ->icon('heroicon-o-calendar-days')
                    ->visible(fn (DailyTimeReview $record) => ! $record->paid_day_off)
                    ->action(function (DailyTimeReview $record, PayrollCalculationService $service): void {
                        $record->update([
                            'paid_day_off' => true,
                            'status' => 'revisado_supervisor',
                            'reviewed_by' => auth()->id(),
                        ]);
                        self::recalculateReviewAndPayroll($record, $service);
                    }),
                Action::make('recalculate')
                    ->label('Actualizar cálculo')
                    ->icon('heroicon-o-arrow-path')
                    ->action(fn (DailyTimeReview $record, PayrollCalculationService $service) => self::recalculateReviewAndPayroll($record, $service)),
            ]);
    }

    private static function hoursColumn(string $name, string $label): TextColumn
    {
        return TextColumn::make($name)
            ->label($label)
            ->state(fn (DailyTimeReview $record) => app(TimeParserService::class)->secondsToDecimalHours($record->{$name}))
            ->alignRight();
    }

    public static function hourStates(DailyTimeReview $record): array
    {
        $parser = app(TimeParserService::class);

        return [
            'justified_lost_time_hours' => $parser->secondsToHourMinute($record->justified_absence_seconds),
            'absence_justified' => self::isFullyJustifiedAbsence($record),
        ];
    }

    public static function secondsFromHourStates(array $data, DailyTimeReview $record): array
    {
        $parser = app(TimeParserService::class);

        if (self::hasHubstaffTime($record)) {
            $lostTimeSeconds = self::lostTimeSeconds($record);
            $justifiedSeconds = min($parser->parseToSeconds($data['justified_lost_time_hours'] ?? 0), $lostTimeSeconds);

            $data['paid_day_off'] = false;
            $data['justified_absence_seconds'] = $justifiedSeconds;
            $data['unjustified_absence_seconds'] = max($lostTimeSeconds - $justifiedSeconds, 0);
        } else {
            $isOff = (bool) ($data['paid_day_off'] ?? false);
            $isJustifiedAbsence = (bool) ($data['absence_justified'] ?? false);

            $data['justified_absence_seconds'] = $isOff ? 0 : ($isJustifiedAbsence ? $record->expected_ordinary_seconds : 0);
            $data['unjustified_absence_seconds'] = $isOff || $isJustifiedAbsence ? 0 : $record->expected_ordinary_seconds;
        }

        $data['status'] = 'revisado_supervisor';

        unset($data['justified_lost_time_hours'], $data['absence_justified']);

        return $data;
    }

    private static function hasHubstaffTime(?DailyTimeReview $record): bool
    {
        return (int) ($record?->hubstaff_total_seconds ?? 0) > 0;
    }

    private static function lostTimeSeconds(DailyTimeReview $record): int
    {
        return max(
            self::requiredSeconds($record)
                - (int) $record->hubstaff_total_seconds
                - (int) $record->paid_time_not_tracked_seconds,
            0,
        );
    }

    private static function requiredSeconds(DailyTimeReview $record): int
    {
        return (int) $record->expected_paid_seconds
            ?: (int) $record->expected_seconds + (int) $record->assigned_overtime_seconds;
    }

    private static function isFullyJustifiedAbsence(DailyTimeReview $record): bool
    {
        return ! self::hasHubstaffTime($record)
            && ! $record->paid_day_off
            && (int) $record->expected_ordinary_seconds > 0
            && (int) $record->justified_absence_seconds >= (int) $record->expected_ordinary_seconds;
    }

    public static function statusOptions(): array
    {
        return [
            'pendiente' => 'Pendiente',
            'revisado_supervisor' => 'Aplicado',
            'aprobado_rrhh' => 'Aplicado',
        ];
    }

    public static function displayStatusLabel(?DailyTimeReview $record): string
    {
        if (self::isCorrectPendingReview($record)) {
            return 'Correcto';
        }

        return self::statusOptions()[$record?->status] ?? 'Sin revisión';
    }

    public static function isCorrectPendingReview(?DailyTimeReview $record): bool
    {
        return $record !== null
            && $record->status === 'pendiente'
            && (int) $record->hubstaff_total_seconds > 0
            && (int) $record->difference_seconds >= 0;
    }

    private static function recalculateReviewAndPayroll(DailyTimeReview $record, PayrollCalculationService $service): void
    {
        $service->recalculateDailyReview($record);
        $service->recalculateEmployeePayrollResult($record->payrollPeriod, $record->employee);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDailyTimeReviews::route('/'),
            'create' => CreateDailyTimeReview::route('/create'),
            'edit' => EditDailyTimeReview::route('/{record}/edit'),
        ];
    }
}
