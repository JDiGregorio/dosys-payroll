<?php

namespace App\Filament\Resources\PayrollResults;

use App\Filament\Resources\PayrollResults\Pages\CreatePayrollResult;
use App\Filament\Resources\PayrollResults\Pages\EditPayrollResult;
use App\Filament\Resources\PayrollResults\Pages\ListPayrollResults;
use App\Models\PayrollResult;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
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
        $query = parent::getEloquentQuery()->with(['employee.campaign', 'employee.team', 'employee.tierLevel', 'payrollPeriod']);
        $user = auth()->user();

        if ($user?->isSupervisor()) {
            $query->whereHas('employee', fn (Builder $query) => $query->where('supervisor_user_id', $user->id));
        }

        return $query;
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
        return auth()->user()?->isRrhh() ?? false;
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
                    Tab::make('Editar planilla')
                        ->schema([
                            Select::make('payroll_period_id')->label('Período')->relationship('payrollPeriod', 'name')->disabled(),
                            Select::make('employee_id')->label('Empleado')->relationship('employee', 'name')->disabled(),
                            TextInput::make('monthly_salary')->label('Salario mensual')->numeric(),
                            TextInput::make('biweekly_salary_amount')->label('Pago quincenal')->numeric(),
                            TextInput::make('daily_rate')->label('Pago por día')->numeric(),
                            TextInput::make('hourly_rate')->label('Pago por hora')->numeric(),
                            TextInput::make('overtime_hourly_rate')->label('Valor hora extra')->numeric(),
                            TextInput::make('worked_days')->label('Días trabajados')->numeric(),
                            TextInput::make('worked_salary_amount')->label('Salario')->numeric(),
                            TextInput::make('extra_bonuses_amount')->label('Bonos extras')->numeric(),
                            TextInput::make('overtime_amount')->label('Horas extras')->numeric(),
                            TextInput::make('referred_bonus_amount')->label('Bono referido')->numeric(),
                            TextInput::make('adjustment_bonus_amount')->label('Ajuste')->numeric(),
                            TextInput::make('extras_total_amount')->label('Ingresos extra totales')->numeric(),
                            TextInput::make('gross_amount')->label('Total devengado')->numeric(),
                            TextInput::make('ihss_amount')->label('IHSS')->numeric(),
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
                TextColumn::make('employee.campaign.name')->label('Campaña')->sortable(),
                TextColumn::make('employee.tierLevel.name')->label('Tier')->sortable(),
                TextColumn::make('monthly_salary')->label('Salario mensual')->money('HNL')->sortable(),
                TextColumn::make('biweekly_salary_amount')->label('Pago quincenal')->money('HNL')->sortable(),
                TextColumn::make('daily_rate')->label('Pago por día')->money('HNL'),
                TextColumn::make('worked_days')->label('Días trabajados'),
                TextColumn::make('worked_salary_amount')->label('Salario')->money('HNL'),
                TextColumn::make('extra_bonuses_amount')->label('Bonos extras')->money('HNL'),
                TextColumn::make('overtime_amount')->label('Horas extras')->money('HNL'),
                TextColumn::make('referred_bonus_amount')->label('Bono referido')->money('HNL'),
                TextColumn::make('adjustment_bonus_amount')->label('Ajuste')->money('HNL'),
                TextColumn::make('extras_total_amount')->label('Ingresos extra totales')->money('HNL'),
                TextColumn::make('gross_amount')->label('Total devengado')->money('HNL')->sortable(),
                TextColumn::make('ihss_amount')->label('IHSS')->money('HNL')->toggleable(),
                TextColumn::make('total_deductions_amount')->label('Total deducciones')->money('HNL')->sortable(),
                TextColumn::make('net_amount')->label('Total a pagar')->money('HNL')->sortable(),
                TextColumn::make('status')->label('Estado')->badge()->formatStateUsing(fn (string $state) => self::statusOptions()[$state] ?? $state),
            ])
            ->filters([
                SelectFilter::make('payroll_period_id')->relationship('payrollPeriod', 'name')->label('Período'),
                SelectFilter::make('status')->label('Estado')->options(self::statusOptions()),
                SelectFilter::make('campaign_id')->relationship('employee.campaign', 'name')->label('Campaña'),
                SelectFilter::make('team_id')->relationship('employee.team', 'name')->label('Team'),
            ])
            ->recordActions([
                EditAction::make()->label('Editar'),
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

    public static function getPages(): array
    {
        return [
            'index' => ListPayrollResults::route('/'),
            'create' => CreatePayrollResult::route('/create'),
            'edit' => EditPayrollResult::route('/{record}/edit'),
        ];
    }
}
