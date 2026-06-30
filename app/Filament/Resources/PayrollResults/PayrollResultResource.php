<?php

namespace App\Filament\Resources\PayrollResults;

use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\PayrollResults\Pages\CreatePayrollResult;
use App\Filament\Resources\PayrollResults\Pages\EditPayrollResult;
use App\Filament\Resources\PayrollResults\Pages\ListPayrollResults;
use App\Filament\Resources\PayrollResults\Pages\ViewPayrollResult;
use App\Models\PayrollResult;
use App\Services\PayrollVoucherSender;
use App\Services\TimeParserService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PayrollResultResource extends Resource
{
    protected static ?string $model = PayrollResult::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $navigationLabel = 'Planilla calculada';

    protected static ?string $modelLabel = 'planilla calculada';

    protected static ?string $pluralModelLabel = 'planilla calculada';

    protected static string|\UnitEnum|null $navigationGroup = 'Planilla';

    protected static ?int $navigationSort = 70;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereHas('employee', fn (Builder $query) => $query
                ->visibleTo(auth()->user())
                ->where('employees.active', true))
            ->with(['employee.campaign', 'employee.team', 'employee.tierLevel', 'employee.scheduleType', 'payrollPeriod']);
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
        return (auth()->user()?->isRrhh() ?? false)
            && (bool) $record->employee?->active
            && $record->payrollPeriod?->status !== 'cerrado';
    }

    public static function canView(Model $record): bool
    {
        $user = auth()->user();

        return (bool) $user?->active
            && (bool) $record->employee?->active
            && ($user->isRrhh() || $record->employee?->supervisor_user_id === $user->id);
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
            Tabs::make('Planilla')
                ->columnSpanFull()
                ->tabs([
                    Tab::make(fn (): string => auth()->user()?->isRrhh() ? 'Editar planilla' : 'Detalle de planilla')
                        ->columns(2)
                        ->schema([
                            Placeholder::make('voucher_delivery_status')
                                ->label('Voucher')
                                ->content(fn (?PayrollResult $record): string => $record?->voucherDeliveryStatus() ?? 'Pendiente')
                                ->badge()
                                ->color(fn (?PayrollResult $record): string => $record?->voucher_sent_at ? 'success' : 'gray')
                                ->columnSpanFull(),
                            Select::make('payroll_period_id')->label('Período')->relationship('payrollPeriod', 'name')->disabled(),
                            Select::make('employee_id')->label('Empleado')->relationship('employee', 'name')->disabled(),
                            TextInput::make('schedule_name')->label('Jornada')->disabled()->dehydrated(false)->afterStateHydrated(fn (TextInput $component, ?PayrollResult $record) => $component->state($record?->employee?->scheduleType?->name)),
                            Select::make('salary_calculation_method')->label('Método de cálculo')->options(EmployeeResource::salaryCalculationMethodOptions())->disabled(),
                            TextInput::make('monthly_salary')->label('Salario mensual')->numeric(),
                            TextInput::make('biweekly_salary_amount')->label('Pago quincenal')->numeric(),
                            TextInput::make('daily_rate')->label('Pago por día')->numeric(),
                            TextInput::make('hourly_rate')->label('Pago por hora')->numeric(),
                            TextInput::make('overtime_hourly_rate')->label('Valor hora extra')->numeric(),
                            TextInput::make('display_worked_days')
                                ->label('Días trabajados')
                                ->disabled()
                                ->dehydrated(false)
                                ->afterStateHydrated(fn (TextInput $component, ?PayrollResult $record) => $component->state($record?->displayWorkedDays() ?? 0)),
                            TextInput::make('scheduled_days')->label('Días programados')->numeric(),
                            TextInput::make('expected_hubstaff_hours')->label('Horas esperadas Hubstaff')->disabled()->dehydrated(false)->afterStateHydrated(fn (TextInput $component, ?PayrollResult $record) => $component->state($record ? app(TimeParserService::class)->secondsToHourMinute((int) $record->expected_hubstaff_seconds) : '0:00')),
                            TextInput::make('expected_paid_hours')->label('Horas pagadas esperadas')->disabled()->dehydrated(false)->afterStateHydrated(fn (TextInput $component, ?PayrollResult $record) => $component->state($record ? app(TimeParserService::class)->secondsToHourMinute((int) $record->expected_paid_seconds) : '0:00')),
                            TextInput::make('hubstaff_hours')->label('Horas trabajadas Hubstaff')->disabled()->dehydrated(false)->afterStateHydrated(fn (TextInput $component, ?PayrollResult $record) => $component->state($record ? app(TimeParserService::class)->secondsToHourMinute((int) $record->hubstaff_total_seconds) : '0:00')),
                            TextInput::make('payable_hours')->label('Horas pagables')->disabled()->dehydrated(false)->afterStateHydrated(fn (TextInput $component, ?PayrollResult $record) => $component->state($record ? app(TimeParserService::class)->secondsToHourMinute((int) $record->payable_seconds) : '0:00')),
                            TextInput::make('worked_salary_amount')->label('Salario')->numeric(),
                            TextInput::make('lost_time_hours')
                                ->label('Tiempo perdido')
                                ->disabled()
                                ->dehydrated(false)
                                ->afterStateHydrated(fn (TextInput $component, ?PayrollResult $record) => $component->state(
                                    $record ? app(TimeParserService::class)->secondsToHourMinute((int) $record->lost_time_seconds) : '0:00',
                                )),
                            TextInput::make('lost_time_amount')->label('Impacto del tiempo perdido')->numeric()->readOnly(),
                            TextInput::make('extra_bonuses_amount')->label('Bonos extras')->numeric(),
                            TextInput::make('preassigned_overtime_hours')->label('Horas extra preasignadas')->disabled()->dehydrated(false)->afterStateHydrated(fn (TextInput $component, ?PayrollResult $record) => $component->state($record ? app(TimeParserService::class)->secondsToDecimalHours((int) $record->preassigned_overtime_seconds) : 0)),
                            TextInput::make('additional_overtime_hours')->label('Horas extra adicionales')->disabled()->dehydrated(false)->afterStateHydrated(fn (TextInput $component, ?PayrollResult $record) => $component->state($record ? app(TimeParserService::class)->secondsToDecimalHours((int) $record->additional_overtime_seconds) : 0)),
                            TextInput::make('overtime_amount')->label('Total pago horas extras')->numeric(),
                            TextInput::make('referred_bonus_amount')->label('Bono referido')->numeric(),
                            TextInput::make('tier_adjustment_bonus_amount')->label('Ajuste Cambio de Tier')->numeric(),
                            TextInput::make('vacation_bonus_amount')->label('Vacaciones')->numeric(),
                            TextInput::make('extras_total_amount')->label('Ingresos extra totales')->numeric(),
                            TextInput::make('gross_amount')->label('Total devengado')->numeric(),
                            TextInput::make('private_insurance_amount')->label('PAN AME Seguro')->numeric(),
                            TextInput::make('ihss_amount')->label('IHSS')->numeric(),
                            TextInput::make('tier_adjustment_deduction_amount')->label('Ajuste Cambio de Tier')->numeric(),
                            TextInput::make('other_deductions_amount')->label('Otras deducciones')->numeric(),
                            TextInput::make('total_deductions_amount')->label('Total deducciones')->numeric(),
                            TextInput::make('net_amount')->label('Total a pagar')->numeric(),
                            Select::make('status')->label('Estado')->options(self::statusOptions())->required(),
                        ]),
                    Tab::make('Calendario del empleado')
                        ->schema([
                            View::make('filament.resources.payroll-results.review-calendar'),
                        ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee.name')->label('Nombre empleado')->searchable()->sortable(),
                TextColumn::make('voucher_sent_at')
                    ->label('Voucher')
                    ->badge()
                    ->state(fn (PayrollResult $record): string => $record->voucher_sent_at ? 'Enviado' : 'Pendiente')
                    ->color(fn (PayrollResult $record): string => $record->voucher_sent_at ? 'success' : 'gray')
                    ->description(fn (PayrollResult $record): ?string => $record->voucher_sent_at
                        ? $record->voucherDeliveryStatus()
                        : null)
                    ->toggleable(),
                TextColumn::make('employee.scheduleType.name')->label('Jornada')->toggleable(),
                TextColumn::make('salary_calculation_method')->label('Método')->formatStateUsing(fn (?string $state) => EmployeeResource::salaryCalculationMethodOptions()[$state] ?? $state)->toggleable(),
                TextColumn::make('employee.campaign.name')->label('Campaña')->sortable(),
                TextColumn::make('employee.tierLevel.name')->label('Tier')->sortable(),
                TextColumn::make('monthly_salary')->label('Salario mensual')->money('HNL', locale: 'en-US')->sortable(),
                TextColumn::make('biweekly_salary_amount')->label('Pago quincenal')->money('HNL', locale: 'en-US')->sortable(),
                TextColumn::make('daily_rate')->label('Pago por día')->money('HNL', locale: 'en-US'),
                TextColumn::make('worked_days')->label('Días trabajados')->state(fn (PayrollResult $record): float => $record->displayWorkedDays()),
                TextColumn::make('worked_salary_amount')->label('Salario')->money('HNL', locale: 'en-US'),
                TextColumn::make('lost_time_seconds')
                    ->label('Tiempo perdido')
                    ->state(fn (PayrollResult $record) => app(TimeParserService::class)->secondsToHourMinute((int) $record->lost_time_seconds))
                    ->alignRight(),
                TextColumn::make('lost_time_amount')->label('Impacto tiempo perdido')->money('HNL', locale: 'en-US'),
                TextColumn::make('extra_bonuses_amount')->label('Bonos extras')->money('HNL', locale: 'en-US'),
                TextColumn::make('preassigned_overtime_seconds')->label('Extra preasignada')->state(fn (PayrollResult $record) => app(TimeParserService::class)->secondsToDecimalHours((int) $record->preassigned_overtime_seconds))->toggleable(),
                TextColumn::make('additional_overtime_seconds')->label('Extra adicional')->state(fn (PayrollResult $record) => app(TimeParserService::class)->secondsToDecimalHours((int) $record->additional_overtime_seconds))->toggleable(),
                TextColumn::make('overtime_amount')->label('Pago horas extras')->money('HNL', locale: 'en-US'),
                TextColumn::make('referred_bonus_amount')->label('Bono referido')->money('HNL', locale: 'en-US'),
                TextColumn::make('tier_adjustment_bonus_amount')->label('Ajuste Cambio de Tier')->money('HNL', locale: 'en-US'),
                TextColumn::make('vacation_bonus_amount')->label('Vacaciones')->money('HNL', locale: 'en-US')->toggleable(),
                TextColumn::make('extras_total_amount')->label('Ingresos extra totales')->money('HNL', locale: 'en-US'),
                TextColumn::make('gross_amount')->label('Total devengado')->money('HNL', locale: 'en-US')->sortable(),
                TextColumn::make('private_insurance_amount')->label('PAN AME Seguro')->money('HNL', locale: 'en-US')->toggleable(),
                TextColumn::make('ihss_amount')->label('IHSS')->money('HNL', locale: 'en-US')->toggleable(),
                TextColumn::make('tier_adjustment_deduction_amount')->label('Ajuste Cambio de Tier deducción')->money('HNL', locale: 'en-US')->toggleable(),
                TextColumn::make('other_deductions_amount')->label('Otras deducciones')->money('HNL', locale: 'en-US')->toggleable(),
                TextColumn::make('total_deductions_amount')->label('Total deducciones')->money('HNL', locale: 'en-US')->sortable(),
                TextColumn::make('net_amount')->label('Total a pagar')->money('HNL', locale: 'en-US')->sortable(),
                TextColumn::make('status')->label('Estado')->badge()->formatStateUsing(fn (string $state) => self::statusOptions()[$state] ?? $state),
            ])
            ->filters([
                SelectFilter::make('payroll_period_id')->relationship('payrollPeriod', 'name')->label('Período'),
                SelectFilter::make('status')->label('Estado')->options(self::statusOptions()),
                SelectFilter::make('campaign_id')->relationship('employee.campaign', 'name')->label('Campaña'),
                SelectFilter::make('team_id')->relationship('employee.team', 'name')->label('Team'),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label('Ver detalle')
                    ->visible(fn (PayrollResult $record): bool => self::canView($record)),
                EditAction::make()
                    ->label('Editar')
                    ->visible(fn (PayrollResult $record): bool => self::canEdit($record)),
            ]);
    }

    public static function statusOptions(): array
    {
        return [
            'borrador' => 'Borrador',
            'en_revision' => 'En revisión',
            'aprobado' => 'Aprobado',
            'cerrado' => 'Cerrado',
        ];
    }

    public static function sendVoucherAction(): Action
    {
        return Action::make('sendVoucher')
            ->label('Enviar voucher')
            ->icon('heroicon-o-envelope')
            ->color('primary')
            ->visible(fn (): bool => auth()->user()?->isRrhh() ?? false)
            ->requiresConfirmation()
            ->modalHeading('Enviar voucher de planilla')
            ->modalDescription(fn (PayrollResult $record): string => filled($record->employee?->email)
                ? 'Se enviará el voucher al correo '.$record->employee?->email.'.'
                : 'Este empleado no tiene correo configurado. Agrega el correo en la ficha del empleado antes de enviar el voucher.')
            ->form([
                Textarea::make('comment')
                    ->label('Comentario opcional')
                    ->rows(3)
                    ->maxLength(1000)
                    ->nullable(),
            ])
            ->action(function (PayrollResult $record, array $data, PayrollVoucherSender $sender): void {
                try {
                    $sender->send($record, $data['comment'] ?? null);
                } catch (\RuntimeException $exception) {
                    Notification::make()
                        ->title('No se pudo enviar el voucher')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Voucher enviado')
                    ->body('El voucher fue enviado al correo del empleado.')
                    ->success()
                    ->send();
            });
    }

    public static function previewVoucherAction(): Action
    {
        return Action::make('previewVoucher')
            ->label('Vista previa voucher')
            ->icon('heroicon-o-eye')
            ->color('gray')
            ->visible(fn (): bool => auth()->user()?->isRrhh() ?? false)
            ->modalHeading('Vista previa del voucher')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Cerrar')
            ->modalWidth('5xl')
            ->modalContent(fn (PayrollResult $record) => view('emails.payroll-voucher', [
                'result' => $record->loadMissing(['employee.campaign', 'employee.team', 'employee.workRole', 'employee.tierLevel', 'payrollPeriod']),
                'comment' => null,
                'periodName' => $record->payrollPeriod?->name ?? 'Período de planilla',
                'logoPath' => public_path('images/dosys-logo.jpg'),
            ]));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPayrollResults::route('/'),
            'create' => CreatePayrollResult::route('/create'),
            'view' => ViewPayrollResult::route('/{record}'),
            'edit' => EditPayrollResult::route('/{record}/edit'),
        ];
    }
}
