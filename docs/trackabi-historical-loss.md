# Estimacion provisional de tiempo Trackabi (periodo 9)

La estimacion no es actividad de escritorio medida. Se conserva el timer
Trackabi en `hubstaff_time_entries` y el total importado en
`daily_time_reviews.hubstaff_total_seconds`. El origen y la muestra historica
quedan en la revision diaria; los cambios de estimacion, ajuste y justificacion
quedan en `daily_review_lost_time_events`.

## Datos y calculo

- Historico por empleado: periodos configurados en
  `TRACKABI_HISTORICAL_LOSS_PERIOD_IDS` (por defecto 5,6,7), anteriores al
  periodo destino. Solo registros Hubstaff activos de dias programados con
  tracker positivo. Se excluyen dias mixtos/Trackabi y faltantes mayores a dos
  horas. El promedio incluye los dias sin faltante, como en la logica anterior.
- Se requieren al menos diez dias de muestra. La proporcion de dias con
  faltante elige hasta 70% de los dias candidatos. La eleccion por empleado y
  fecha es determinista para que repetir el comando produzca el mismo plan.
  Los minutos varian entre 55% y 100% del promedio historico limitado a 28
  minutos, redondeados al minuto. Asi un promedio alto no asigna 28 minutos
  identicos a todas las fechas elegidas.
- Solo se estima en dias Trackabi pendientes, programados y con timer igual o
  superior a la expectativa del tracker. Esta expectativa incluye las horas
  extra preasignadas del dia. Los registros inferiores conservan su faltante
  observado; dias OFF, administrativos y revisiones aplicadas se omiten.
- `propuesto = max(0, estimado + ajuste_supervisor)`.
  `remanente_estimado = max(0, propuesto - justificado)`.
  Para esos dias, `tracker_para_calculo = max(0, esperado_tracker - propuesto)`.
  El servicio de planilla suma la justificacion al credito y limita el resultado
  al tiempo requerido. Unicamente ese calculo existente determina horas
  ordinarias pagables y extras proporcionales. No hay una segunda deduccion.
- El periodo cerrado impide editar revisiones. No se introduce otro cutoff.
  Los importes del periodo abierto ya reflejan el remanente provisional;
  el supervisor puede corregirlo antes del cierre.

El formulario de revision muestra timer original, horas computables, perdida
estimada, valor propuesto por supervisor, tiempo justificado, remanente, origen,
revisor y fecha. Al guardar, `reviewed_by` y `reviewed_at` identifican al
supervisor. El historial de eventos conserva los valores anteriores y nuevos.
La fuente `historical_estimate` se cambia a `supervisor_adjustment` cuando el
supervisor modifica el valor. La columna de fuente permite otras fuentes de
tiempo en una integracion posterior sin reinterpretar este historico.

## Produccion con Sail

```bash
./vendor/bin/sail artisan migrate --force
./vendor/bin/sail artisan config:clear
./vendor/bin/sail artisan payroll:estimate-trackabi-loss --period=9 --details
./vendor/bin/sail artisan payroll:estimate-trackabi-loss --period=9 --apply
./vendor/bin/sail artisan view:clear
```

La primera ejecucion del comando es solo vista previa. `--apply` guarda y
recalcula unicamente a empleados afectados. Repetirlo no agrega estimaciones
en otras fechas. No hace falta reimportar Trackabi ni recalcular todo el
periodo. Si se recalcula despues con `payroll:recalculate-period 9
--preserve-manual`, estimaciones y justificaciones se conservan.
Solo para actualizar propuestas pendientes despues de cambiar el algoritmo,
usar `--refresh-pending` en vista previa y luego con `--apply`. Omite dias
revisados, justificados o ajustados por supervisores.

Ejemplo: jornada 8:00, extra preasignada 1:00, timer 10:15, estimacion 0:12.
El supervisor justifica 0:07; quedan 0:05 sin justificar. El timer de 10:15
sigue disponible para auditoria. Segun el cumplimiento de extras, los 0:05
afectan salario ordinario o el pago proporcional de la extra, sin restarse dos
veces.
