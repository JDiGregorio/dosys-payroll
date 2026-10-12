# Cutover a Trackabi API directa

## Estado y alcance

Fecha acordada: `2026-09-26`, primer día del período `2026-09-26` a
`2026-10-10`. El comando `trackabi:import` usa exclusivamente la API oficial
cuando `TRACKABI_PROVIDER=direct`. No existe fallback a MindCloud. Los períodos
anteriores, incluido el #9, permanecen cerrados e inmutables. No se migran ni
renombran entradas históricas `source_provider=trackabi`.

La tabla `hubstaff_time_entries` sigue siendo el contrato normalizado de
payroll. Las nuevas entradas tienen `source_provider=trackabi_api`, ID externo
y proyecto oficial. El nuevo índice único por período e ID directo evita
duplicados. `daily_time_reviews` y los resultados se recalculan solo para los
empleados afectados en días no revisados, dentro de una transacción.

No hay tarea programada de `trackabi:import` en el scheduler del proyecto.
La importación se ejecuta manualmente por CLI o desde **Importar Trackabi** en
la pantalla del período. El modal muestra la vista previa antes de confirmar.

## API confirmada

Base: `https://api.trackabi.com`; autenticación `Authorization: Bearer`.
Confirmado con la clave configurada localmente el 11 de octubre de 2026:

| Métrica | Endpoint | Campo | Estado |
| --- | --- | --- | --- |
| Tiempo registrado | `GET /api/v1/time-entries` | `date_logged`, `logged_time`, `member`, `project`, `id` | Confirmado |
| Empleados | `GET /api/v1/members` | `id`, `email`, `first_name`, `last_name` | Confirmado |
| Proyectos | `GET /api/v1/projects` | `id`, `name` | Confirmado |
| Desktop/User Activity | - | - | No expuesto en OpenAPI público |
| Productive/Unproductive | - | - | No expuesto en OpenAPI público |
| Activity Score | - | - | No expuesto en OpenAPI público |
| Apps, URLs, timeline, capturas | - | - | No expuesto en OpenAPI público |

`/api/v1/resources` responde 404, pero la autenticación y los tres endpoints
anteriores funcionan. Time entries admite `start_date`, `end_date`, `project_id`,
`email`, `page` y `page_size`. La respuesta es una lista JSON con nombres
`snake_case`; la paginación no se trunca silenciosamente. Fuente:
<https://trackabi.com/dest/swagger.json>.

`logged_time` no es Desktop Activity ni trabajo efectivo. Activity Score no se
convierte en horas ni salario. Los campos no expuestos quedan `NULL` en nuevas
entradas. No se hace scraping ni se usan endpoints privados.

## Variables de producción

```dotenv
TRACKABI_PROVIDER=direct
TRACKABI_MINDCLOUD_ENABLED=false
TRACKABI_HUBSTAFF_IMPORT_ENABLED=false
TRACKABI_ESTIMATED_LOSS_MAX_MINUTES=28
TRACKABI_HISTORICAL_LOSS_PERIOD_IDS=5,6,7
TRACKABI_DIRECT_API_ENABLED=true
TRACKABI_DIRECT_API_BASE_URL=https://api.trackabi.com
TRACKABI_DIRECT_API_KEY=<clave directa de Trackabi>
TRACKABI_DIRECT_CUTOVER_DATE=2026-09-26
```

Timeout (30 s), SSL verificado, paginación (100 por página, hasta 100 páginas)
y almacenamiento de diagnósticos desactivado tienen valores por defecto en
código. Activity no tiene fuente confirmada; no hay que añadir variables de
Activity. El bloqueo de períodos cerrados es obligatorio en código. Los IDs
de proyecto se guardan en `trackabi_project_mappings`, no en `.env`.

Obsoletos para nuevas importaciones: `TRACKABI_ENABLED`,
`TRACKABI_API_BASE_URL`, `TRACKABI_API_TOKEN`, `MINDCLOUD_API_KEY`,
`TRACKABI_CONNECTION_ID` y demás variables del conector MindCloud.
Conservarlas temporalmente solo si se necesita inspección legacy; no son
consultadas por el importador directo. `TRACKABI_DIRECT_FAIL_OPEN` también es
obsoleto: no hay fallback. No publicar ninguna clave en repositorio o logs.

## Mapeos de proyecto

El 11 de octubre la API mostró:

| ID | Proyecto Trackabi | Campaña interna sugerida |
| --- | --- | --- |
| 75415 | Palmetto A (equipo de Jasmin) | PALMETTO |
| 80297 | Palmetto B (equipo de Victor) | PALMETTO |
| 75416 | AUTO FINANCE CENTER | AUTO FINANCE CENTER |
| 75417 | RDD FINANCIAL | RRD FINANCIAL (confirmado) |

La tabla de mapeos debe completarse antes de importar. Un proyecto no mapeado
no se atribuye a otra campaña; aparece como alerta. El match de empleado usa
correo dentro de la campaña mapeada y, si no coincide, nombre único. No se
crean empleados automáticamente.

Palmetto A y B son proyectos Trackabi distintos de una sola campaña interna;
el ID del proyecto original se conserva en cada entrada. La pertenencia al
proyecto A o B **no restringe** la importación ni genera alerta por supervisor:
el acceso de cada supervisor a las revisiones depende del supervisor asignado
al empleado en payroll. Por ejemplo, se importan los registros de Marco Lara
en Palmetto A aunque su supervisor sea Victor, y los registros propios de
Victor en A. La asociación por empleado usa correo y campaña PALMETTO.

Dosys BPO no usa Trackabi y no debe recibir un mapeo de proyecto. El pago sin
tracking se configura **por empleado** mediante `paid_without_tracking`, no
automáticamente por campaña; en la copia local actual Orely ya lo tiene
activado. Al crear otro empleado de Dosys BPO hay que configurar esa opción.

## Despliegue

1. Desplegar el código y respaldar la base. Configurar las variables anteriores
   en `.env` de producción. La clave ya existente puede reutilizarse solo si
   es la clave **directa** de Trackabi.
2. Ejecutar:

```bash
./vendor/bin/sail artisan migrate --force
./vendor/bin/sail artisan config:cache
./vendor/bin/sail artisan view:clear
./vendor/bin/sail artisan trackabi:api-test
./vendor/bin/sail artisan trackabi:discover
```

3. Vincular proyectos confirmados:

```bash
./vendor/bin/sail artisan trackabi:map-project --project-id=75415 --campaign=PALMETTO
./vendor/bin/sail artisan trackabi:map-project --project-id=80297 --campaign=PALMETTO
./vendor/bin/sail artisan trackabi:map-project --project-id=75416 --campaign="AUTO FINANCE CENTER"
./vendor/bin/sail artisan trackabi:map-project --project-id=75417 --campaign="RRD FINANCIAL"
```

La equivalencia de RDD FINANCIAL con RRD FINANCIAL fue confirmada por negocio.
Revisar otros proyectos/campañas ausentes antes de cerrar la planilla.

4. Crear el período del 26/09 al 10/10 si aún no existe, anotar su ID y correr:

```bash
./vendor/bin/sail artisan trackabi:import --period=ID --dry-run
./vendor/bin/sail artisan trackabi:import --period=ID --commit
```

El primer comando no escribe nada. Confirmar empleados, proyectos y conteos;
si hay alertas de mapeo, resolverlas antes del cierre. `--commit` lee la API,
normaliza y persiste en una transacción. También se admite
`trackabi:import --period=ID` como operación de commit; se recomienda usar la
opción explícita. Una reimportación directa idéntica no crea otro lote ni
recalcula el empleado. Un entry corregido actualiza días pendientes; si el día
ya fue revisado, mantiene la decisión y crea
`TRACKABI_DIRECT_DATA_AFTER_REVIEW` para resolverla manualmente. No ejecutar
`payroll:recalculate-period` como parte de la importación.

El commit aplica el estimador histórico existente únicamente a los días
importados que continúan pendientes y reúnen suficientes antecedentes. La
propuesta queda identificada como `historical_estimate`; no cambia el tiempo
original de Trackabi y no sustituye justificaciones o correcciones humanas.

## Validación y auditoría

El importador conserva `raw_logged_time` tal como llega de Trackabi para
registros válidos. Acepta duraciones `HH:MM:SS` de hasta 24 horas por entrada;
un valor ausente, malformado o superior genera la alerta
`TRACKABI_SUSPICIOUS_LOGGED_TIME` con ID, empleado, fecha, valor original,
inicio, fin y proyecto. Esa entrada no se escribe en tiempo pagable ni se
reemplaza por `end_time - start_time`. El máximo puede reducirse con
`TRACKABI_DIRECT_MAX_ENTRY_HOURS` (nunca supera 24). Si una importación anterior
del mismo ID ya estaba activa, permanece intacta y la alerta indica
`previous_active_entry`; se debe revisar ese caso antes de cerrar la planilla.

Para inspeccionar o auditar sin modificar la base:

```bash
./vendor/bin/sail artisan trackabi:inspect-time-entry 123456
./vendor/bin/sail artisan trackabi:audit-times --from=2026-09-26 --to=2026-10-10
```

El segundo comando detecta duraciones mayores a 24 horas o malformadas,
empleados/proyectos ausentes, inconsistencias entre inicio/fin y duración, ID
duplicados y posibles duplicados por contenido. `possible_duplicate_content`
es una señal, no una eliminación automática. Corregir el registro en Trackabi,
verificarlo con `inspect-time-entry` y volver a importar el período. Una
reimportación válida marca la alerta como resuelta; las revisiones protegidas
siguen sin sobrescribirse.

```bash
./vendor/bin/sail artisan trackabi:inspect-activity --employee=correo@ejemplo.com --date=2026-09-26
./vendor/bin/sail artisan trackabi:compare-legacy --period=9
```

El primer comando lee solo la API directa y separa tiempo logged de métricas
no disponibles. El segundo compara API directa actual contra los registros
MindCloud **ya guardados** del período 9; nunca consulta MindCloud ni escribe
en ese período. Diferencias pueden deberse a correcciones posteriores en
Trackabi o a ajustes históricos de payroll.

Antes de cerrar un período nuevo, verificar que no tenga alertas de proyecto,
empleado o importación fallida, y contrastar muestras de empleados/días con la
interfaz Trackabi. Una falla directa aborta sin alterar entradas previas; no
hay fallback. Períodos `aprobado` y `cerrado` rechazan importación.

## Pendiente con Trackabi

Solicitar a soporte un endpoint **oficial y soportado** para Insights/Desktop
Activity por miembro y fecha, con permisos requeridos, filtros de proyecto,
timezone, unidades y semántica exacta de Desktop Activity, productive,
unproductive, score, aplicaciones, URLs y timeline. Pedir ejemplo JSON real y
política de retención/rate limit. Hasta recibirlo, esas métricas no se importan
ni se infieren desde `logged_time`; el supervisor conserva su revisión manual.
