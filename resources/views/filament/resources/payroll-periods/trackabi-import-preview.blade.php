<div class="space-y-3 text-sm">
    <p>Período #{{ $plan['period_id'] }} · {{ $plan['from'] }} al {{ $plan['to'] }}</p>
    <p>Registros API: {{ $plan['api_entries'] }} · Empleados: {{ $plan['employees_matched'] }}</p>
    <p>Por crear: {{ $plan['would_insert'] }} · Por actualizar: {{ $plan['would_update'] }} · Revisados que se omiten: {{ $plan['would_skip'] }}</p>
    <p>Proyectos sin mapeo: {{ count($plan['unmapped_projects']) }} · Empleados sin mapeo: {{ count($plan['unmapped_employees']) }}</p>
    <p>Tiempo sospechoso omitido: {{ count($plan['suspicious_entries']) }} registro(s).</p>
    @if ($plan['suspicious_entries'] !== [])
        <ul class="space-y-1">
            @foreach ($plan['suspicious_entries'] as $entry)
                <li>{{ $entry['employee'] }} · {{ $entry['date_logged'] }} · ID {{ $entry['entry_id'] }} · raw {{ is_scalar($entry['raw_logged_time']) ? $entry['raw_logged_time'] : 'N/D' }} · {{ $entry['reason'] }}</li>
            @endforeach
        </ul>
    @endif
    <p>Activity/Insights: no expuesto por la API pública. Solo se importará logged time.</p>
</div>
