<?php

namespace App\Filament\Resources\PayrollPeriods;

use App\Exports\DailyReviewsExport;
use App\Exports\PayrollResultsExport;
use App\Filament\Resources\Concerns\RrhhOnlyResource;
use App\Filament\Resources\PayrollPeriods\Pages\CreatePayrollPeriod;
use App\Filament\Resources\PayrollPeriods\Pages\EditPayrollPeriod;
use App\Filament\Resources\PayrollPeriods\Pages\ListPayrollPeriods;
use App\Imports\HubstaffTimeEntriesImport;
use App\Models\DeductionType;
use App\Models\Employee;
use App\Models\HubstaffImport;
use App\Models\HubstaffTimeEntry;
use App\Models\PayrollPeriod;
use App\Services\HubstaffEmployeeMappingService;
use App\Services\PayrollCalculationService;
use App\Services\OperationsFinalReviewService;
use App\Services\Trackabi\TrackabiActivityCoverageService;
use App\Services\Trackabi\TrackabiDirectImportService;
use App\Services\Trackabi\TrackabiInsightsCsvImportService;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

class PayrollPeriodResource extends Resource
{
    use RrhhOnlyResource;

    protected static ?string $model = PayrollPeriod::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $navigationLabel = 'Períodos de planilla';

    protected static ?string $modelLabel = 'período de planilla';

    protected static ?string $pluralModelLabel = 'períodos de planilla';

    protected static string|\UnitEnum|null $navigationGroup = 'Planilla';

    protected static ?int $navigationSort = 10;

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->isRrhh() ?? false;
    }

    public static function canCreate(): bool
    {
        return (auth()->user()?->isRrhh() ?? false)
            && ! PayrollPeriod::hasOpenPeriod();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Nombre')->required()->maxLength(255),
            Select::make('fortnight')->label('Quincena')->options([
                'first' => 'Primera quincena',
                'second' => 'Segunda quincena',
            ])->required(),
            DatePicker::make('starts_at')->label('Fecha inicial')->required(),
            DatePicker::make('ends_at')->label('Fecha final')->required()->afterOrEqual('starts_at'),
            Select::make('status')->label('Estado')
                ->options(self::statusOptions())
                ->default('borrador')
                ->required(),
            Toggle::make('apply_deductions')->label('Cobrar deducciones en este período')->default(false)->live(),
            Select::make('deduction_type_ids')
                ->label('Deducciones a cobrar')
                ->options(fn () => DeductionType::query()->where('active', true)->orderBy('name')->pluck('name', 'id')->all())
                ->multiple()
                ->preload()
                ->searchable()
                ->visible(fn ($get) => (bool) $get('apply_deductions'))
                ->afterStateHydrated(fn (Select $component, ?PayrollPeriod $record) => $component->state($record?->deductionTypes()->pluck('deduction_types.id')->all() ?? [])),
            Textarea::make('notes')->label('Notas')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount([
                'hubstaffTimeEntries',
                'dailyTimeReviews',
                'payrollResults',
            ]))
            ->columns([
                TextColumn::make('name')->label('Período')->searchable()->sortable(),
                TextColumn::make('fortnight')->label('Quincena')->badge()->formatStateUsing(fn (?string $state) => ['first' => 'Primera', 'second' => 'Segunda'][$state] ?? 'No definida'),
                TextColumn::make('starts_at')->label('Desde')->date()->sortable(),
                TextColumn::make('ends_at')->label('Hasta')->date()->sortable(),
                TextColumn::make('status')->label('Estado')->badge()->formatStateUsing(fn (string $state) => self::statusOptions()[$state] ?? $state),
                TextColumn::make('operations_review')->label('Revisión Operaciones')->badge()
                    ->state(function (PayrollPeriod $record): string {
                        $service = app(OperationsFinalReviewService::class);
                        if (! $service->required($record)) {
                            return 'No aplica';
                        }

                        return $service->isCurrent($record) ? 'Aprobada'
                            : ($record->operations_final_reviewed_by ? 'Desactualizada' : 'Pendiente');
                    }),
                TextColumn::make('employees_count')->label('Empleados')->state(fn () => Employee::query()->count()),
                TextColumn::make('payroll_results_count')->label('Planillas calculadas'),
                TextColumn::make('unmapped')->label('Sin mapeo')->state(fn (PayrollPeriod $record) => HubstaffTimeEntry::query()->where('payroll_period_id', $record->id)->where('active', true)->whereNull('employee_id')->distinct('hubstaff_member')->count('hubstaff_member')),
            ])
            ->recordActions([
                self::exportPayrollAction(),
                self::operationsFinalReviewAction(),
                self::closePeriodAction(),
                EditAction::make()->label('Editar'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->label('Eliminar'),
                ]),
            ]);
    }

    public static function importHubstaffAction(): Action
    {
        return Action::make('importHubstaff')
            ->label(fn (PayrollPeriod $record) => $record->hubstaffTimeEntries()->exists()
                ? 'Reemplazar CSV de Hubstaff'
                : 'Importar CSV de Hubstaff')
            ->icon('heroicon-o-arrow-up-tray')
            ->requiresConfirmation()
            ->modalDescription(fn (PayrollPeriod $record) => $record->hubstaffTimeEntries()->exists()
                ? 'Se importará una nueva versión. Los registros anteriores quedarán como historial inactivo y se conservarán revisiones, comentarios, bonos, deducciones y aprobaciones.'
                : 'El archivo debe contener únicamente fechas dentro del período seleccionado.')
            ->form([
                FileUpload::make('file')->label('Archivo CSV')->required()->acceptedFileTypes(['text/csv', 'text/plain']),
            ])
            ->action(function (PayrollPeriod $record, array $data, PayrollCalculationService $service): void {
                if (! config('trackabi.hubstaff_import_enabled')
                    || ! in_array($record->fresh()->status, ['borrador', 'importado', 'en_revision'], true)
                    || (config('trackabi_direct.cutover_date')
                        && $record->ends_at->toDateString() >= config('trackabi_direct.cutover_date'))
                    || $record->hubstaffTimeEntries()->where('active', true)
                        ->where(fn ($query) => $query->whereNull('source_provider')
                            ->orWhere('source_provider', '!=', 'hubstaff_csv'))->exists()) {
                    throw new \RuntimeException('La importación CSV de Hubstaff está deshabilitada para este período.');
                }

                $import = HubstaffImport::query()->create([
                    'payroll_period_id' => $record->id,
                    'original_filename' => basename((string) $data['file']),
                    'imported_by' => auth()->user()?->email,
                    'status' => 'pending',
                ]);

                try {
                    DB::transaction(function () use ($record, $data, $import, $service): void {
                        $previousEntryIds = $record->hubstaffTimeEntries()
                            ->where('active', true)
                            ->where('source_provider', 'hubstaff_csv')
                            ->pluck('id');

                        Excel::import(
                            new HubstaffTimeEntriesImport($record, $import),
                            Storage::disk('local')->path($data['file']),
                        );

                        if ($previousEntryIds->isNotEmpty()) {
                            HubstaffTimeEntry::query()
                                ->whereKey($previousEntryIds)
                                ->update(['active' => false]);
                        }

                        $service->recalculatePeriodPreservingManual($record);
                    });

                    Notification::make()->title('CSV importado y cálculos actualizados sin borrar revisiones manuales')->success()->send();
                } catch (Throwable $exception) {
                    $import->update(['status' => 'failed', 'error_message' => $exception->getMessage()]);
                    throw $exception;
                }
            })
            ->visible(fn (PayrollPeriod $record) => (auth()->user()?->isRrhh() ?? false)
                && config('trackabi.hubstaff_import_enabled')
                && in_array($record->status, ['borrador', 'importado', 'en_revision'], true)
                && (! config('trackabi_direct.cutover_date')
                    || $record->ends_at->toDateString() < config('trackabi_direct.cutover_date')));
    }

    public static function importTrackabiAction(): Action
    {
        return Action::make('importTrackabi')
            ->label('Importar Trackabi')
            ->icon('heroicon-o-arrow-down-tray')
            ->requiresConfirmation()
            ->modalHeading('Importar Trackabi API directa')
            ->modalSubmitActionLabel('Confirmar importación')
            ->modalWidth('4xl')
            ->modalContent(fn (PayrollPeriod $record) => view('filament.resources.payroll-periods.trackabi-import-preview', [
                'plan' => app(TrackabiDirectImportService::class)->preview($record),
            ]))
            ->action(function (PayrollPeriod $record): void {
                $plan = app(TrackabiDirectImportService::class)->import($record);
                Notification::make()
                    ->title('Trackabi importado')
                    ->body("{$plan['written']} registros, {$plan['affected_employees']} empleados recalculados.")
                    ->success()->send();
            })
            ->visible(fn (PayrollPeriod $record) => (auth()->user()?->isRrhh() ?? false)
                && config('trackabi.provider') === 'direct'
                && in_array($record->status, ['borrador', 'importado', 'en_revision'], true)
                && config('trackabi_direct.cutover_date')
                && $record->starts_at->toDateString() >= config('trackabi_direct.cutover_date'));
    }

    public static function previewTrackabiInsightsAction(): Action
    {
        return Action::make('previewTrackabiInsights')
            ->label('Importar actividad Trackabi')
            ->icon('heroicon-o-document-arrow-up')
            ->form([
                DatePicker::make('activity_date')->label('Fecha del reporte (un solo día)')->required(),
                FileUpload::make('file')->label('CSV Insights Staff Summary')->disk('local')
                    ->directory('trackabi-insights')->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel'])
                    ->maxSize(10240)->required(),
            ])
            ->modalSubmitActionLabel('Ver vista previa')
            ->action(function (PayrollPeriod $record, array $data): void {
                $path = Storage::disk('local')->path($data['file']);
                $plan = app(TrackabiInsightsCsvImportService::class)->preview($record, Carbon::parse($data['activity_date']), $path);
                session()->put('trackabi_insights_preview_'.$record->id, [
                    'date' => $plan['date'], 'file' => $data['file'], 'hash' => $plan['file_hash'],
                ]);
                Notification::make()->title('Vista previa lista')
                    ->body("{$plan['employees_matched']} empleados, ".count($plan['employees_unmatched']).' sin mapeo, '.count($plan['errors']).' errores. Revisa y confirma con la acción siguiente.')
                    ->warning()->send();
            })
            ->visible(fn (PayrollPeriod $record) => (auth()->user()?->isRrhh() ?? false)
                && config('trackabi.provider') === 'direct'
                && in_array($record->status, ['borrador', 'importado', 'en_revision'], true)
                && $record->starts_at->toDateString() >= (string) config('trackabi_direct.cutover_date'));
    }

    public static function confirmTrackabiInsightsAction(): Action
    {
        return Action::make('confirmTrackabiInsights')
            ->label('Confirmar actividad Trackabi')
            ->icon('heroicon-o-check')
            ->requiresConfirmation()
            ->modalWidth('4xl')
            ->modalSubmitAction(function (Action $action, PayrollPeriod $record): Action {
                $preview = session('trackabi_insights_preview_'.$record->id);
                if (! is_array($preview)) {
                    return $action->disabled();
                }
                $plan = app(TrackabiInsightsCsvImportService::class)->preview(
                    $record, Carbon::parse($preview['date']), Storage::disk('local')->path($preview['file']),
                );

                return $action->disabled($plan['employees_unmatched'] !== [] || $plan['errors'] !== [] || $plan['rows_count'] === 0);
            })
            ->modalContent(function (PayrollPeriod $record) {
                $preview = session('trackabi_insights_preview_'.$record->id);
                if (! is_array($preview)) {
                    return view('filament.resources.payroll-periods.trackabi-insights-preview', ['plan' => null]);
                }
                $plan = app(TrackabiInsightsCsvImportService::class)->preview(
                    $record, Carbon::parse($preview['date']), Storage::disk('local')->path($preview['file']),
                );

                return view('filament.resources.payroll-periods.trackabi-insights-preview', ['plan' => $plan]);
            })
            ->action(function (PayrollPeriod $record): void {
                $preview = session('trackabi_insights_preview_'.$record->id);
                if (! is_array($preview)) {
                    throw new \RuntimeException('Primero genera la vista previa del CSV.');
                }
                $path = Storage::disk('local')->path($preview['file']);
                if (hash_file('sha256', $path) !== $preview['hash']) {
                    throw new \RuntimeException('El archivo cambió después de la vista previa.');
                }
                $result = app(TrackabiInsightsCsvImportService::class)->import($record, Carbon::parse($preview['date']), $path);
                session()->forget('trackabi_insights_preview_'.$record->id);
                Notification::make()->title('Actividad Trackabi importada')
                    ->body("{$result['written']} filas; {$result['reviews_updated']} revisiones pendientes actualizadas.")
                    ->success()->send();
            })
            ->visible(fn (PayrollPeriod $record) => (auth()->user()?->isRrhh() ?? false)
                && in_array($record->status, ['borrador', 'importado', 'en_revision'], true)
                && is_array(session('trackabi_insights_preview_'.$record->id)));
    }

    private static function generateReviewsAction(): Action
    {
        return Action::make('generateReviews')
            ->label('Generar revisión diaria')
            ->icon('heroicon-o-clipboard-document-check')
            ->requiresConfirmation()
            ->action(function (PayrollPeriod $record, PayrollCalculationService $service): void {
                $service->generateDailyReviews($record);
                $record->update(['status' => 'en_revision']);
                Notification::make()->title('Revisión diaria generada')->success()->send();
            })
            ->visible(fn () => auth()->user()?->isRrhh());
    }

    public static function recalculatePayrollAction(): Action
    {
        return Action::make('recalculatePayroll')
            ->label('Actualizar cálculos del período')
            ->icon('heroicon-o-arrow-path')
            ->requiresConfirmation()
            ->modalDescription('Se actualizarán horas esperadas, horas pagables y planilla calculada. Se preservarán justificaciones, comentarios, bonos, deducciones, estados y aprobaciones manuales.')
            ->action(function (PayrollPeriod $record, PayrollCalculationService $service): void {
                $service->recalculatePeriodPreservingManual($record);
                Notification::make()
                    ->title('Cálculos del período actualizados')
                    ->body('Se preservaron las justificaciones y demás datos manuales.')
                    ->success()
                    ->send();
            })
            ->visible(fn (PayrollPeriod $record) => (auth()->user()?->isRrhh() ?? false) && $record->status !== 'cerrado');
    }

    public static function operationsFinalReviewAction(): Action
    {
        return Action::make('operationsFinalReview')
            ->label('Revisión final Operaciones')
            ->icon('heroicon-o-check-badge')
            ->requiresConfirmation()
            ->modalDescription('Confirma que terminaron las revisiones de supervisores. Quedará registrada tu identidad y la aprobación se invalidará si cambian los datos.')
            ->action(function (PayrollPeriod $record, OperationsFinalReviewService $service): void {
                try {
                    $service->approve($record, auth()->user());
                    Notification::make()->title('Revisión final de Operaciones aprobada')->success()->send();
                } catch (\RuntimeException $exception) {
                    Notification::make()->title('No se pudo aprobar la revisión final')
                        ->body($exception->getMessage())->danger()->send();
                }
            })
            ->visible(fn (PayrollPeriod $record): bool => (bool) auth()->user()?->can_final_review_operations
                && $record->status !== 'cerrado'
                && app(OperationsFinalReviewService::class)->required($record));
    }

    public static function mapHubstaffEmployeeAction(): Action
    {
        return Action::make('mapHubstaffEmployee')
            ->label('Mapear empleado Hubstaff')
            ->icon('heroicon-o-link')
            ->modalHeading('Mapear empleado de Hubstaff')
            ->modalDescription('La relación se aplicará al período actual y se reutilizará en futuras importaciones.')
            ->modalSubmitActionLabel('Mapear empleado')
            ->form([
                Select::make('hubstaff_member')
                    ->label('Empleado sin mapeo')
                    ->options(fn (PayrollPeriod $record) => HubstaffTimeEntry::query()
                        ->where('payroll_period_id', $record->id)
                        ->where('active', true)
                        ->whereNull('employee_id')
                        ->whereNotNull('hubstaff_member')
                        ->distinct()
                        ->orderBy('hubstaff_member')
                        ->pluck('hubstaff_member', 'hubstaff_member')
                        ->all())
                    ->searchable()
                    ->required(),
                Select::make('employee_id')
                    ->label('Empleado de planilla')
                    ->options(fn () => Employee::query()
                        ->where('active', true)
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->preload()
                    ->required(),
            ])
            ->action(function (
                PayrollPeriod $record,
                array $data,
                HubstaffEmployeeMappingService $mappingService,
            ): void {
                $updatedEntries = $mappingService->map(
                    $record,
                    (string) $data['hubstaff_member'],
                    (int) $data['employee_id'],
                );

                Notification::make()
                    ->title('Empleado mapeado y período recalculado')
                    ->body("Se actualizaron {$updatedEntries} registros de Hubstaff.")
                    ->success()
                    ->send();
            })
            ->visible(fn (PayrollPeriod $record) => (auth()->user()?->isRrhh() ?? false)
                && config('trackabi.hubstaff_import_enabled')
                && $record->status !== 'cerrado'
                && HubstaffTimeEntry::query()
                    ->where('payroll_period_id', $record->id)
                    ->where('active', true)
                    ->whereNull('employee_id')
                    ->exists());
    }

    private static function exportPayrollAction(): Action
    {
        return Action::make('exportPayroll')
            ->label('Exportar planilla')
            ->icon('heroicon-o-document-arrow-down')
            ->action(function (PayrollPeriod $record) {
                if (! $record->payrollResults()->exists()) {
                    Notification::make()->title('Primero calcula la planilla del período')->danger()->send();

                    return null;
                }

                return Excel::download(new PayrollResultsExport($record), 'planilla_'.str($record->name)->slug('_').'.xlsx');
            })
            ->visible(fn () => auth()->user()?->isRrhh());
    }

    private static function exportDailyAction(): Action
    {
        return Action::make('exportDaily')
            ->label('Exportar detalle de revisión')
            ->icon('heroicon-o-table-cells')
            ->action(fn (PayrollPeriod $record) => Excel::download(new DailyReviewsExport($record), 'revision_diaria_'.str($record->name)->slug('_').'.xlsx'))
            ->visible(fn () => auth()->user()?->isRrhh());
    }

    private static function closePeriodAction(): Action
    {
        return Action::make('closePeriod')
            ->label('Cerrar período')
            ->icon('heroicon-o-lock-closed')
            ->requiresConfirmation()
            ->action(function (PayrollPeriod $record, PayrollCalculationService $service): void {
                if (HubstaffTimeEntry::query()->where('payroll_period_id', $record->id)->where('active', true)->whereNull('employee_id')->exists()) {
                    Notification::make()->title('No se puede cerrar: hay registros Hubstaff sin mapeo')->danger()->send();

                    return;
                }

                $service->recalculatePeriodPreservingManual($record);

                $summary = app(TrackabiActivityCoverageService::class)->summary($record);
                if ($summary['missing'] > 0 || $summary['legacy_historical'] > 0) {
                    Notification::make()->title('No se puede cerrar: actividad Trackabi pendiente de resolver')
                        ->body('Esperados: '.$summary['expected'].'; disponibles: '.$summary['available'].'; actividad cero: '.$summary['zero'].'; faltantes: '.$summary['missing'].'; revisiones históricas pendientes de reemplazo: '.$summary['legacy_historical'].'.')
                        ->danger()->send();

                    return;
                }

                $operations = app(OperationsFinalReviewService::class);
                if ($operations->required($record) && ! $operations->isCurrent($record)) {
                    Notification::make()->title('No se puede cerrar: falta revisión final vigente de Operaciones')
                        ->danger()->send();

                    return;
                }

                $record->update(['status' => 'cerrado']);
                Notification::make()->title('Período cerrado')->success()->send();
            })
            ->visible(fn (PayrollPeriod $record) => (auth()->user()?->isRrhh() ?? false) && $record->status !== 'cerrado');
    }

    public static function statusOptions(): array
    {
        return [
            'borrador' => 'Borrador',
            'importado' => 'Importado',
            'en_revision' => 'En revisión',
            'aprobado' => 'Aprobado',
            'cerrado' => 'Cerrado',
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPayrollPeriods::route('/'),
            'create' => CreatePayrollPeriod::route('/create'),
            'edit' => EditPayrollPeriod::route('/{record}/edit'),
        ];
    }
}
