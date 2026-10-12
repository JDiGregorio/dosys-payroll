<div class="space-y-3 text-sm">
    @if ($plan)
        <p>Fecha: {{ $plan['date'] }} · Archivo: {{ $plan['filename'] }}</p>
        <p>Filas: {{ $plan['rows_count'] }} · Empleados: {{ $plan['employees_matched'] }} · Sin mapeo: {{ count($plan['employees_unmatched']) }}</p>
        <p>Crear: {{ $plan['would_insert'] }} · Actualizar: {{ $plan['would_update'] }} · Omitir: {{ $plan['would_skip'] }}</p>
        <p>Revisiones diarias por crear: {{ $plan['would_create_reviews'] }}</p>
        <p>Productiva: {{ app(\App\Services\TimeParserService::class)->secondsToHourMinuteSecond($plan['productive_total_seconds']) }} · Improductiva: {{ app(\App\Services\TimeParserService::class)->secondsToHourMinuteSecond($plan['unproductive_total_seconds']) }} · Medida: {{ app(\App\Services\TimeParserService::class)->secondsToHourMinuteSecond($plan['measured_total_seconds']) }}</p>
        @foreach ($plan['errors'] as $error)
            <p class="text-danger-600">{{ $error }}</p>
        @endforeach
        @foreach ($plan['employees_unmatched'] as $row)
            <p class="text-warning-600">Sin mapeo, fila {{ $row['row'] }}: {{ $row['member'] }}</p>
        @endforeach
        @if (count($plan['employees_unmatched']) > 0)
            <p class="text-danger-600">{{ count($plan['employees_unmatched']) }} empleados sin mapear. Resuelve sus nombres Trackabi antes de confirmar; no se importará parcialmente.</p>
        @endif
        <p>Las revisiones ya aplicadas no se cambian; se genera una alerta si llega actividad nueva.</p>
    @else
        <p>Primero selecciona la fecha y el CSV para generar la vista previa.</p>
    @endif
</div>
