# Trackabi: API directa en paralelo con MindCloud

## Estado verificado

Verificado el 28 de septiembre de 2026 con la clave directa configurada localmente.
La fase 1 esta implementada y probada. No se modificaron el importador MindCloud,
sus variables, el calculo de payroll ni los datos/revisiones de la base local.

La API oficial y MindCloud son clientes independientes. La clave de MindCloud
NO sirve como clave directa. No se copian credenciales entre clientes.

`GET /api/v1/resources` devuelve 404. En la pagina de introduccion,
`/api/v1/resources` aparece como patron generico de rutas; no figura como un
endpoint concreto en el OpenAPI actual. No se usa su 404 para diagnosticar una
clave invalida. La autenticacion se confirma con `/api/v1/company/profile`.

Fuentes oficiales:

- https://trackabi.com/help/api
- https://trackabi.com/help/api-docs
- https://trackabi.com/dest/swagger.json (OpenAPI 3.0, version 1.0 Beta)
- https://mindcloud.co/docs/universal/rest/trackabi/latest

## Variables nuevas

Configuracion independiente en `config/trackabi_direct.php`:

```dotenv
TRACKABI_DIRECT_API_ENABLED=false
TRACKABI_DIRECT_API_BASE_URL=https://api.trackabi.com
TRACKABI_DIRECT_API_KEY=
TRACKABI_DIRECT_API_TIMEOUT=30
TRACKABI_DIRECT_API_VERIFY_SSL=true
TRACKABI_DIRECT_ACTIVITY_ENABLED=false
TRACKABI_DIRECT_ACTIVITY_IMPORT_FROM_DATE=
TRACKABI_DIRECT_ACTIVITY_LOOKBACK_DAYS=3
TRACKABI_DIRECT_STORE_RAW_RESPONSES=false
TRACKABI_DIRECT_FAIL_OPEN=true
TRACKABI_ACTIVITY_GAP_REVIEW_MINUTES=30
```

Para probar, habilitar `TRACKABI_DIRECT_API_ENABLED=true` y configurar la clave
en el `.env` del servidor. Mantener SSL habilitado en produccion. Nunca pegar la
clave en comandos, repositorio, frontend, tickets ni mensajes.

Los parametros de actividad, lookback, fail-open y umbral quedan reservados para
un provider confirmado. Actualmente ninguna importacion llama al cliente
directo: por aislamiento, una falla directa no detiene MindCloud ni payroll.
Los comandos de diagnostico SI devuelven codigo de error cuando fallan, incluso
con FAIL_OPEN=true; no ocultan fallas como sincronizaciones exitosas.

## Clave y permisos

En Trackabi: Personal > Settings > API keys > Add new key. Configurar nombre,
vigencia e IP permitida si se utiliza restriccion de IP. Guardar el valor en
`TRACKABI_DIRECT_API_KEY`; no reemplazar `MINDCLOUD_API_KEY` o `TRACKABI_API_TOKEN`.

El plan y rol deben permitir Access to Trackabi API. El usuario de la clave debe
poder consultar los empleados y timesheets necesarios. Para los datos de
Insights en la interfaz, revisar View insights. Un 403 requiere revisar permisos
del rol y restricciones; habilitarlos no crea un endpoint inexistente.

La clave local probada autentica y accede a los recursos listados abajo; no se
observo un 403 en ellos. No se puede atribuir la ausencia de Activity a permisos:
no hay endpoint publicado para esa informacion en la especificacion consultada.

## Comandos y produccion

Despues de desplegar el codigo y configurar el `.env`:

```bash
./vendor/bin/sail artisan config:clear
./vendor/bin/sail artisan trackabi:api-test
./vendor/bin/sail artisan trackabi:discover
```

Sin Sail, sustituir `./vendor/bin/sail artisan` por `php artisan`.
Esta fase no requiere migraciones, reimportar tiempos ni recalcular planillas.
No ejecuta cambios en Trackabi: solo peticiones GET. Son comandos CLI para
operadores con acceso al servidor, no rutas web expuestas a supervisores.

`api-test` valida configuracion, conectividad, autenticacion y disponibilidad de
resources. Un 404 en resources con perfil valido es un resultado soportado.

`discover` consulta resources, descarga el OpenAPI oficial sin Authorization y
prueba solo rutas GET publicadas sin IDs ni parametros obligatorios. Cuando la
operacion admite `page`/`page_size`, consulta la primera pagina con un registro.
No inventa valores para parametros requeridos ni prueba POST/PUT/DELETE.
Un 2xx vacio significa endpoint accesible, no datos inexistentes para toda la
organizacion. Muestra nombres de campos, no valores personales ni claves.

Con STORE_RAW_RESPONSES=true, discovery guarda respuestas completas sanitizadas
en el disco `local` privado, bajo `trackabi-direct/`. La ruta se muestra al final.
Por defecto no guarda respuestas. Elimina claves sensibles recursivamente y
redacta las credenciales conocidas incluso si el servidor las devuelve en otro
campo. Los archivos pueden contener informacion personal del proveedor: mantener
el disco privado y usar la politica de retencion del servidor.

Las fallas se registran con categoria/status, sin body, headers, token ni URL con
query. Los errores mostrados no encadenan excepciones HTTP con solicitudes.
Se hacen como maximo tres intentos, solo ante red/timeout, 429 y 5xx; las otras
respuestas y JSON invalido no se reintentan. Las redirecciones no se siguen.

## Endpoints y respuestas observadas

| GET | Resultado real local |
| --- | --- |
| `/api/v1/company/profile` | 200; nombre, alias y datos de contacto |
| `/api/v1/members` | 200; identificacion y campos del miembro |
| `/api/v1/projects` | 200; proyecto, miembros asignados y teams |
| `/api/v1/company/time-types` | 200; id, name, short_name |
| `/api/v1/time-entries` | 200; logged_time y datos de timesheet |
| `/api/v1/clients` | 200; lista vacia en la muestra |
| `/api/v1/leaves` | 200; lista vacia en la muestra |
| `/api/v1/tasks` | 404 con esta consulta |
| `/api/v1/resources` | 404 |

Las rutas con `{id}` estan publicadas, pero no se probaron sin un ID real.
Discovery informa por separado publicado, accesible y no probado.

Consulta adicional real: `/api/v1/time-entries` con `start_date=2026-09-11`,
`end_date=2026-09-11`, `page=1`, `page_size=1`. Devuelve un array de registros,
sin el envelope `success/data/meta` de MindCloud. Extracto observado, con
identidad y avatar redactados y otros campos omitidos:

```json
[
  {
    "id": "[REDACTED]",
    "member": {
      "id": "[REDACTED]",
      "avatar": "[REDACTED]",
      "email": "[REDACTED]",
      "first_name": "[REDACTED]",
      "last_name": "[REDACTED]"
    },
    "date_logged": "2026-09-11",
    "logged_time": "09:03:00",
    "billable": "09:03:00",
    "start_time": "05:56:05",
    "end_time": "15:00:01",
    "time_type": "Normal"
  }
]
```

La API directa usa snake_case, no los nombres camelCase normalizados por
MindCloud. Tambien se observaron client, project, task, description,
timer_started_at y timer_timezone. No convertir el ejemplo redactado en fixture
de produccion ni considerarlo respuesta con datos de actividad.

## Limite de las fases 2 y 3

No hay rutas publicadas ni campos observados para Desktop/User Activity Time,
productive/unproductive activity, activity score, apps, duracion por app,
browser activity, screenshots o timeline en esta API. Slack, Teams y RingCentral
no se pueden cuantificar con time entries.

No se crearon tablas de actividad/app usage, migraciones, provider, alertas ni
`trackabi:sync-activity`. No hay una respuesta confirmada que permita implementar
esas piezas ni probar un upsert real. Incluso habilitando ACTIVITY_ENABLED,
esta fase no inventa ni sincroniza metricas.

Para desbloquearlas, solicitar a soporte de Trackabi un endpoint soportado de
actividad diaria/Insights con documentacion, permisos, unidades, timezone y
semantica del score; despues verificar una respuesta real. Otra fuente posible
es el CSV de Insights, pero requiere un importador separado y datos por dia.

Cuando exista ese contrato:

- Mantener logged, desktop, productive y unproductive separados y nullable.
- Guardar NULL cuando un campo no exista, nunca cero para simularlo.
- Activity gap = max(0, logged - desktop) solo si ambos existen; no llamarlo idle.
- No convertir score/productividad/apps/gap en deducciones automaticas.
- Definir unicidad diaria segun la granularidad real de proyecto/miembro.
- Implementar upsert, lookback, timezone y dry-run sin escrituras.
- Entonces habilitar `trackabi:sync-activity --from=2026-09-11 --to=2026-09-25 --dry-run`.

## Pruebas

```bash
./vendor/bin/sail artisan test --filter=TrackabiDirectApiTest
./vendor/bin/sail artisan test --filter=HubstaffImportTest
```

Http::fake y preventStrayRequests evitan llamadas reales en las pruebas nuevas.
Cubren autenticacion separada, parametros, 401/403/404/429/5xx/redireccion,
timeout, recuperacion transitoria, JSON invalido, diagnostico seguro, discovery,
almacenamiento opcional sanitizado y continuidad de MindCloud ante falla directa.
Upsert/gap y enriquecimiento diario quedan pendientes junto con el provider,
no se agregan pruebas ficticias para funcionalidades aun no implementadas.
