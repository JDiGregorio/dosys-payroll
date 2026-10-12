<x-filament-panels::page>
    <div class="space-y-8">
        <select wire:model.live="periodId" class="fi-input block w-full max-w-md rounded-lg border-gray-300 mb-6">
            @foreach ($this->periods() as $period)
                <option value="{{ $period->id }}">{{ $period->name }}</option>
            @endforeach
        </select>

        <div class="grid gap-8 md:grid-cols-2 xl:gap-10">
            @if (auth()->user()?->isRrhh() && config('trackabi.hubstaff_import_enabled'))
                <x-filament::section heading="Empleados Hubstaff sin mapeo">
                    <ul class="space-y-2 text-sm leading-6">
                        @forelse ($this->unmappedMembers() as $member)
                            <li>{{ $member }}</li>
                        @empty
                            <li>Sin alertas.</li>
                        @endforelse
                    </ul>
                </x-filament::section>
            @endif

            @if (auth()->user()?->isRrhh() && config('trackabi.provider') === 'direct')
                <x-filament::section heading="Alertas de Trackabi API">
                    <ul class="space-y-2 text-sm leading-6">
                        @forelse ($this->directImportAlerts() as $alert)
                            <li>{{ match ($alert->code) {
                                'unmapped_project' => 'Proyecto sin mapeo',
                                'unmapped_employee' => 'Empleado sin mapeo',
                                'import_failed' => 'Importación fallida',
                                'TRACKABI_SUSPICIOUS_LOGGED_TIME' => 'Tiempo Trackabi sospechoso',
                                'TRACKABI_INSIGHTS_EMPLOYEE_UNMATCHED' => 'Empleado Insights sin mapeo',
                                'TRACKABI_ACTIVITY_DATA_AFTER_REVIEW' => 'Actividad recibida después de la revisión',
                                'TRACKABI_ACTIVITY_MISSING' => 'Falta actividad Trackabi Insights',
                                'TRACKABI_DIRECT_DATA_AFTER_REVIEW' => 'Registro Trackabi corregido después de la revisión',
                                default => $alert->code,
                            } }} · {{ $alert->context['date_logged'] ?? $alert->context['date'] ?? $alert->created_at?->format('Y-m-d H:i') }}
                            @if ($alert->code === 'TRACKABI_SUSPICIOUS_LOGGED_TIME')
                                · {{ $alert->context['employee'] ?? 'Sin mapeo' }}
                                @if (isset($alert->context['employee_id'])) · Empleado #{{ $alert->context['employee_id'] }} @endif
                                @if (isset($alert->context['member_id'])) · Miembro #{{ $alert->context['member_id'] }} @endif
                                · ID {{ $alert->context['entry_id'] ?? 'N/D' }}
                                · Motivo {{ $alert->context['reason_code'] ?? $alert->context['reason'] ?? 'OTHER' }}
                                · raw {{ is_scalar($alert->context['raw_logged_time'] ?? null) ? $alert->context['raw_logged_time'] : 'N/D' }}
                                · inicio {{ $alert->context['start_time'] ?? 'N/D' }}
                                · fin {{ $alert->context['end_time'] ?? 'N/D' }}
                                · Proyecto: {{ $alert->context['project'] ?? 'NULL / Sin proyecto' }}
                                @if (isset($alert->context['project_id'])) (#{{ $alert->context['project_id'] }}) @endif
                                @if ($alert->context['previous_active_entry'] ?? false)
                                    · Existe una importación previa activa de este ID; revisar antes de cerrar
                                @endif
                            @elseif ($alert->code === 'unmapped_project')
                                · {{ $alert->context['employee'] ?? 'Sin mapeo' }}
                                @if (isset($alert->context['employee_id'])) · Empleado #{{ $alert->context['employee_id'] }} @endif
                                @if (isset($alert->context['member_id'])) · Miembro #{{ $alert->context['member_id'] }} @endif
                                · Entry ID {{ $alert->context['entry_id'] ?? 'N/D' }}
                                · Proyecto: {{ $alert->context['project'] ?? 'NULL / Sin proyecto' }}
                                @if (isset($alert->context['project_id'])) (#{{ $alert->context['project_id'] }}) @endif
                            @endif
                            @if ($alert->code === 'TRACKABI_INSIGHTS_EMPLOYEE_UNMATCHED')
                                · Fila {{ $alert->context['row'] ?? 'N/D' }}
                                · {{ $alert->context['member'] ?? 'Sin nombre' }}
                            @elseif ($alert->code === 'TRACKABI_ACTIVITY_DATA_AFTER_REVIEW')
                                · {{ $alert->context['employee'] ?? 'Sin nombre' }}
                                · volver a revisar explícitamente
                            @elseif ($alert->code === 'TRACKABI_ACTIVITY_MISSING')
                                · {{ $alert->context['employee'] ?? 'Sin nombre' }}
                                · requiere CSV Insights o resolución manual
                            @elseif ($alert->code === 'TRACKABI_DIRECT_DATA_AFTER_REVIEW')
                                · {{ $alert->context['employee'] ?? 'Sin nombre' }}
                                · ID {{ $alert->context['entry_id'] ?? 'N/D' }}
                                · anterior {{ $alert->context['previous_raw_logged_time'] ?? 'N/D' }}
                                · nuevo {{ $alert->context['incoming_raw_logged_time'] ?? 'N/D' }}
                                · requiere revisión manual
                            @endif
                            @if (isset($alert->context['project_id']) && ! in_array($alert->code, ['unmapped_project', 'TRACKABI_SUSPICIOUS_LOGGED_TIME'], true))
                                · Proyecto #{{ $alert->context['project_id'] }}
                            @elseif (isset($alert->context['member_id']))
                                · Miembro #{{ $alert->context['member_id'] }}
                            @endif
                            </li>
                        @empty
                            <li>Sin alertas.</li>
                        @endforelse
                    </ul>
                </x-filament::section>
            @endif

            <x-filament::section heading="Días con horas pagables menores a las esperadas">
                <ul class="space-y-2 text-sm leading-6">
                    @forelse ($this->shortPayableDays() as $review)
                        <li>
                            <a class="text-primary-600 hover:underline" href="{{ $this->reviewUrl($review) }}">
                                {{ $review->date->toDateString() }} · {{ $review->employee?->name }} · {{ round($review->payable_seconds / 3600, 2) }}/{{ round(($review->expected_seconds + $review->assigned_overtime_seconds) / 3600, 2) }} h
                            </a>
                        </li>
                    @empty
                        <li>Sin alertas.</li>
                    @endforelse
                </ul>
            </x-filament::section>

            @if (config('trackabi.hubstaff_import_enabled'))
            <x-filament::section heading="Días con idle mayor a 30 minutos">
                <ul class="space-y-2 text-sm leading-6">
                    @forelse ($this->highIdleDays() as $review)
                        <li>
                            <a class="text-primary-600 hover:underline" href="{{ $this->reviewUrl($review) }}">
                                {{ $review->date->toDateString() }} · {{ $review->employee?->name }} · {{ round($review->hubstaff_idle_seconds / 3600, 2) }} h
                            </a>
                        </li>
                    @empty
                        <li>Sin alertas.</li>
                    @endforelse
                </ul>
            </x-filament::section>
            @endif
        </div>
    </div>
</x-filament-panels::page>
