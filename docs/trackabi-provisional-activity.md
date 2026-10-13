# Trackabi Insights CSV: Desktop Activity

La API oficial directa entrega `logged_time`, no Desktop Activity ni Productive/
Unproductive Activity. El reporte oficial `Insights > Staff > Summary > Export
to CSV` se importa **por un solo día**. No se usa MindCloud ni se reparte un
reporte de quincena entre fechas.

## Fuentes y fórmula

- `trackabi_api`: valor persistido en `source_provider` para time entries de la API directa; incluye timer original, proyecto, miembro, inicio/fin.
- `trackabi_insights_csv`: actividad productiva e improductiva diaria por empleado.
- El normalizador CSV implementa `TrackabiActivityProvider` y entrega
  `DailyTrackabiActivityData`; una futura API oficial de actividad podrá
  normalizar al mismo contrato sin cambiar las reglas de payroll.
- Desktop Activity (`measured_activity_seconds` en la tabla) = segundos enteros de
  actividad productiva + improductiva. Improductiva **no** es pérdida ni
  deducción por sí sola.
- Objetivo trackeable = `expected_hubstaff_seconds` de la revisión diaria;
  procede de `ScheduleExpectationService` y el overtime preasignado distribuido
  por `PayrollCalculationService`. Si hay almuerzo/break pagado no trackeado,
  payroll lo acredita por su configuración actual. La actividad no lo descuenta
  dos veces.
- Faltante provisional = `max(0, objetivo trackeable - actividad medida)`.
  Actividad por encima del objetivo se conserva para auditoría, sin generar OT.
- Para Direct, el reparto de overtime preasignado depende del horario del día,
  no de la duración del timer. Los días OFF o ausencias totalmente justificadas
  no reciben overtime por ese motivo.
- Faltante final = `max(0, faltante provisional + ajuste supervisor - justificado)`.
  Payroll sigue calculando salario y OT con sus reglas existentes; el CSV no
  equivale directamente a horas pagables.

Para días con actividad positiva, el crédito pagado no trackeado es
`min(max(horas esperadas pagadas - actividad medida, 0), lunch + breaks)`.
No se aplica crédito en días sin actividad, ni se genera overtime por exceder
la jornada. Los máximos por jornada son RRD 80 minutos (60+10+10), BDC/
AUTO FINANCE CENTER 90 (60+15+15), y Palmetto 70 (60+10). Victor Vasquez,
Marco Lara y Kelly Urquia tienen 90 minutos dentro de Palmetto. El crédito
es una política de pago, no una medición de pausas realmente tomadas.
Así, desaparece el antiguo salto de «sin crédito hasta superar 1:15»; solo
el faltante que excede el crédito requiere justificación.

Para un período Direct ya revisado, ejecutar primero
`./vendor/bin/sail artisan trackabi:preview-paid-time --period=ID`.
`--details` muestra los días afectados. Una vez verificado el resumen,
`--apply` ajusta los días pendientes y prorratea en cada revisión aplicada
la misma fracción de pérdida que el supervisor había justificado: si justificó
todo, el nuevo faltante queda totalmente justificado; si no justificó nada,
permanece íntegro como pérdida. No modifica actividad CSV, comentarios,
estados, bonos ni deducciones. Un día aplicado con datos inconsistentes o
modificados después de la revisión bloquea la aplicación completa y requiere
revisión manual. Repetir `--apply` no vuelve a prorratear un día ya ajustado.

La actividad se guarda sin capear en `trackabi_daily_activities`, con valores
originales del CSV y lote/hash. `logged_time` permanece independiente y solo
sirve para auditoría/anomalías, nunca como tiempo trabajado en períodos desde
el cutover. Para períodos que empiezan el **2026-09-26 o después**, Insights
es obligatorio para medir actividad: el estimador histórico de máximo 28 minutos
es **legacy** y no se usa como fallback. Una fila `00:00 + 00:00` es actividad
explícita cero; una fila ausente es `NULL`, genera `TRACKABI_ACTIVITY_MISSING`
y queda pendiente de revisión sin deducción automática. El resumen de cobertura
cuenta días esperados, disponibles, cero y faltantes; no se puede cerrar un
período con faltantes sin resolver ni con una propuesta histórica heredada sin
revisar. El faltante del CSV es provisional hasta la
revisión del supervisor. Los días OFF
no generan faltante ni OT automático. Empleados `paid_without_tracking` no
reciben descuento por un `00:00` del CSV.
Si cambia un horario y se recalcula el período, la propuesta de actividad de
los días **pendientes** se reconcilia con el nuevo objetivo. Las revisiones
aplicadas, justificaciones y ajustes humanos permanecen protegidos.

Si el CSV llega después de que el supervisor revisó un día, se conserva la
decisión y se crea `TRACKABI_ACTIVITY_DATA_AFTER_REVIEW`; no hay recálculo
silencioso. En la revisión diaria aparece «Revisar actividad nueva»: el
supervisor confirma el faltante propuesto, el tiempo justificado y el comentario.
Solo entonces se recalcula ese empleado y se resuelve la alerta. Períodos
cerrados y anteriores al 2026-09-26 rechazan esta
importación. Un reimport del mismo archivo/hash se omite; uno corregido puede
actualizar actividad, pero respeta las revisiones protegidas.

## Formato confirmado

Encabezados observados el 09/10/2026: `Member Name`, `Total logged`,
`Total billable`, `Productive activity time`, `Unproductive activity time`.
Duraciones de actividad `HH:MM` (también se acepta `HH:MM:SS`). El importador
tolera mayúsculas, espacios y guiones bajos en encabezados, pero exige las dos
columnas de actividad. Permite BOM UTF-8. Insights identifica por `Trackabi
Member ID` cuando existe, luego por correo único (`trackabi_email` o correo del
empleado), y finalmente por `trackabi_name` exacto. No usa `hubstaff_name` ni
los mappings de Hubstaff, y no hace fuzzy matching. Si falta o es ambigua
cualquier identidad, la confirmación completa queda bloqueada.

La API oficial `/api/v1/members` permite obtener `id`, `first_name`,
`last_name` y `email`. Para revisar y enlazar únicamente correos únicos:

```bash
./vendor/bin/sail artisan trackabi:sync-members --dry-run
./vendor/bin/sail artisan trackabi:sync-members --apply
```

`--apply` es explícito y conserva cualquier nombre Trackabi ya editado. Crea
inicialmente `Apellido, Nombre` desde la API; si el CSV muestra otro texto,
corrígelo en el perfil del empleado o con:

```bash
./vendor/bin/sail artisan trackabi:map-insights-member "Apellido, Nombre" --employee=ID
```

Repite la vista previa del CSV. No se confirma ningún lote mientras haya
empleados sin mapear o errores de parseo. La vista previa no escribe actividad
ni payroll.

Como el reporte Staff Summary no trae fecha por fila, `--date` es una
declaración operativa: exportar siempre con FROM y TO iguales a esa fecha.
Si un CSV futuro incluye columna `Date`, se valida contra `--date`.

## Flujo diario

Antes de importar Insights, `./vendor/bin/sail artisan trackabi:coverage --period=ID`
consulta Trackabi Direct y la base local sin escribir. Separa empleados que
requieren tracking de los exentos, días programados de OFF, entradas utilizables
de actividad Insights disponible/cero/faltante y muestra proyectos sin mapear
por ID/nombre y cantidad de entradas. Los días pagables mostrados son
equivalentes provisionales de resultados ya existentes; no son días con
actividad medida. Para períodos Direct, la columna de planilla usa esos
equivalentes en vez de llamar "trabajados" a los 15 días calendario.
Las alertas nuevas de proyecto sin mapeo se identifican por time entry; una
reimportación Direct idempotente puede enriquecer alertas antiguas sin crear
otro lote vacío.

1. Crear el período y configurar `TRACKABI_PROVIDER=direct`,
   `TRACKABI_MINDCLOUD_ENABLED=false`, `TRACKABI_DIRECT_CUTOVER_DATE=2026-09-26`.
2. Ejecutar migraciones y `config:clear` en el despliegue. Las variables
   `TRACKABI_ESTIMATED_LOSS_MAX_MINUTES` y
   `TRACKABI_HISTORICAL_LOSS_PERIOD_IDS` son solo para períodos legacy previos
   al cutover; no afectan a los períodos Direct nuevos.
3. Importar la API directa: `./vendor/bin/sail artisan trackabi:import
   --period=ID --dry-run`; revisar y luego `--commit`.
4. La vista previa indica cuántas revisiones diarias faltan. El commit crea
   solamente esas revisiones mediante el recálculo existente del empleado;
   esto permite importar actividad válida aun cuando el timer Direct haya sido
   rechazado por anomalía.
5. En Trackabi: `Insights > Staff > Summary`, seleccionar **un día** en FROM y
   TO, exportar CSV.
6. Ejecutar `./vendor/bin/sail artisan trackabi:import-insights archivo.csv
   --date=YYYY-MM-DD --period=ID --dry-run`; corregir errores y mappings.
7. Ejecutar el mismo comando sin `--dry-run` para guardar. También hay una
   acción RRHH «Importar actividad Trackabi» en el período: subir CSV, ver
   resumen y confirmar en una segunda acción.
8. Supervisores revisan el faltante, justifican o ajustan; RRHH comprueba
   alertas antes del cierre. No importar un CSV de varios días con una sola
   fecha ni cerrar un período con alertas pendientes de actividad revisada.

Ninguno de estos comandos llama a MindCloud. El CSV se guarda en el disco
`local` del servidor cuando se usa Filament; en Artisan puede leerse desde una
ruta local segura. La fecha laboral sigue el timezone de la aplicación
(`America/Tegucigalpa`).
