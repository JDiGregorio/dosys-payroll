# Planilla Dosys

## Requisitos

- Docker Desktop
- Composer
- PHP compatible con Laravel 13 para instalar dependencias locales
- Laravel Sail
- MySQL via Sail

## Instalacion

```bash
composer install
cp .env.example .env
php artisan key:generate
sail up -d
sail artisan migrate --seed
```

## Jornadas y salarios

Los valores configurados directamente en el empleado tienen prioridad sobre el
Tier. El Tier se usa solamente como referencia para la acción **Sugerir
valores** o como fallback cuando un valor salarial está vacío o en cero.

No se recalculan ni sobrescriben automáticamente:

- salario mensual y quincenal;
- pago por día, hora y hora extra;
- horas ordinarias semanales y diarias;
- horas extra preasignadas semanales o del período.

### Jornada diurna

1. Selecciona la jornada **Diurna**.
2. Selecciona una plantilla de horario.
3. Configura manualmente las horas y los valores salariales del empleado.
4. Selecciona el método de cálculo salarial.

La plantilla **Diurna 40h - 5 días x 8h** espera ocho horas de lunes a viernes.
Si no hay plantilla, se usan `daily_hours` y, solo como último fallback,
`ordinary_weekly_hours / 5`.

### Patrón diurno de 36 horas

Usa la plantilla **Diurna 36h - 4 días 7h + 1 día 8h**. El cálculo toma el
patrón exacto:

- lunes a jueves: 7 horas;
- viernes: 8 horas.

El sistema no divide las 36 horas entre cinco días.

### Jornada rotativa 4x4

1. Selecciona la jornada **Rotativa** y la plantilla **Rotativa 4x4**.
2. Indica el primer día trabajado en **Inicio del ciclo rotativo**.
3. Configura cuatro días trabajados y cuatro días de descanso.
4. Configura las horas esperadas en Hubstaff y las horas pagadas por día.
5. Para salario fijo quincenal usa **Quincenal fijo con deducciones**.

En días de descanso programados la expectativa es cero. Para este método el
salario base es `semi_monthly_salary`; si está vacío, se usa
`monthly_salary / 2`. Hubstaff no sustituye el salario base configurado.

### Lunch pagado no trackeado

Si Hubstaff reporta 11 horas pero el día pagado equivale a 12 horas:

- horas esperadas Hubstaff: `11`;
- horas pagadas por día: `12`;
- lunch pagado no trackeado: `60` minutos;
- lunch incluido en Hubstaff: desactivado.

Si Hubstaff ya incluye lunch o breaks, deja activada la opción correspondiente
para evitar duplicar tiempo.

### Horas extra

Las horas extra preasignadas aceptan decimales: `0.25`, `0.5`, `1.5`, etc. Las
horas adicionales requieren un registro aprobado en **Horas extras
adicionales** y se limitan al excedente real importado desde Hubstaff.

## Recalcular un período editado

Desde la edición del período usa **Actualizar cálculos del período**. La acción
actualiza expectativas, horas pagables y resultados sin borrar ni sobrescribir
justificaciones, comentarios, bonos, deducciones, estados, aprobaciones o
registros Hubstaff.

El equivalente por consola es:

```bash
php artisan payroll:recalculate-period ID_PERIODO --preserve-manual
```

El comando rechaza períodos cerrados y exige `--preserve-manual`.

Para revisar la corrección específica de los cuatro empleados rotativos:

```bash
php artisan payroll:apply-period-corrections --period=ID_PERIODO
```

La ejecución anterior solo muestra una vista previa. Para aplicarla:

```bash
php artisan payroll:apply-period-corrections --period=ID_PERIODO --apply
```

La corrección conserva salarios manuales y toda la información manual del
período.

Para corregir empleados PALMETTO / DEBT COLLECTIONS de 36 horas con un día
semanal de 8 horas inferido desde Hubstaff:

```bash
php artisan payroll:apply-palmetto-36h-schedules --period=ID_PERIODO
```

La ejecución anterior solo muestra una vista previa. Para aplicar:

```bash
php artisan payroll:apply-palmetto-36h-schedules --period=ID_PERIODO --apply
```

Si algún empleado no tiene registros Hubstaff suficientes para inferir el día
de 8 horas, puedes aplicar solo los empleados inferidos y dejar los demás sin
cambios:

```bash
php artisan payroll:apply-palmetto-36h-schedules --period=ID_PERIODO --apply --skip-uninferred
```

El comando excluye empleados de 40 horas, asigna la plantilla 36h correcta por
empleado y recalcula preservando justificaciones, comentarios, bonos,
deducciones, estados y aprobaciones.

## Importar tiempos desde Trackabi

La integración inicial de Trackabi está limitada a la campaña **Palmetto** y a
los 14 correos configurados en `config/trackabi.php`. El importador usa email
como llave principal y, si el email no coincide, intenta resolver el empleado
por nombre dentro de Palmetto.

Variables mínimas:

```env
TRACKABI_ENABLED=true
TRACKABI_PROVIDER=mindcloud
TRACKABI_API_BASE_URL=https://connect.mindcloud.co/v1/universal/trackabi/latest
MINDCLOUD_API_KEY=
TRACKABI_CONNECTION_ID=
TRACKABI_LIST_TIME_ENTRIES_PATH=/actions/list-time-entries
TRACKABI_DEFAULT_PROJECT_NAME=Palmetto
TRACKABI_PALMETTO_PROJECT_ID=75415
TRACKABI_FILTER_BY_PROJECT_ID=false
TRACKABI_IMPORT_FROM_DATE=2026-08-26
TRACKABI_IMPORT_TO_DATE=2026-09-10
TRACKABI_FILTER_DATES_LOCALLY=true
TRACKABI_IMPORT_LIMIT=100
TRACKABI_IMPORT_MAX_PAGES=100
TRACKABI_CONFLICT_STRATEGY=manual_review_on_overlap
```

`MINDCLOUD_API_KEY` es el nombre preferido para la llave de MindCloud. Si no
existe, el sistema usa `TRACKABI_API_TOKEN` como fallback.

MindCloud v1 para Trackabi no acepta correctamente `startDate`/`endDate` en
`list-time-entries` según las pruebas realizadas. Además, algunos registros de
Palmetto llegan sin `project.id`. Por eso el importador trae los registros sin
filtrar por proyecto de forma predeterminada y filtra localmente usando la lista
blanca de correos Palmetto y `dateLogged`. Si se desea forzar el filtro por
proyecto, activa `TRACKABI_FILTER_BY_PROJECT_ID=true`.

Ejemplo de `curl` funcional:

```bash
curl -G "https://connect.mindcloud.co/v1/universal/trackabi/latest/actions/list-time-entries" \
  -H "Authorization: Bearer $MINDCLOUD_API_KEY" \
  --data-urlencode "connectionId=$TRACKABI_CONNECTION_ID" \
  --data-urlencode "limit=10" \
  --data-urlencode "offset=0" \
  --data-urlencode "fields=id,dateLogged,loggedTime,member.email,member.firstName,member.lastName,project.id,project.name,startTime,endTime,timeType"
```

Vista previa para la quincena del 26 de agosto al 10 de septiembre:

```bash
./vendor/bin/sail artisan trackabi:import --campaign=Palmetto --from=2026-08-26 --to=2026-09-10 --dry-run
```

Aplicar importación:

```bash
./vendor/bin/sail artisan trackabi:import --campaign=Palmetto --from=2026-08-26 --to=2026-09-10 --commit
```

El importador no modifica revisiones ya marcadas como revisadas, aprobadas o
bloqueadas. Si hay Trackabi y Hubstaff para el mismo empleado/fecha, no se suma
doble: Trackabi se guarda inactivo para auditoría y el conflicto queda para
revisión manual. Los datos de actividad/productividad no se aplican al cálculo
porque el endpoint actual no los devuelve.

## Validación de planilla

Antes de cerrar un período:

1. Confirma que no existan registros Hubstaff sin mapeo.
2. Confirma que no existan revisiones diarias pendientes.
3. Revisa horas esperadas Hubstaff, horas pagadas esperadas y tiempo pagado no
   trackeado.
4. Compara salario mensual, pago quincenal y tarifas con la ficha del empleado.
5. Verifica horas extra preasignadas, adicionales, bonos y deducciones.
6. Confirma total devengado, total deducciones y total a pagar.

El sistema no permite cerrar el período mientras existan empleados sin mapeo o
revisiones pendientes.
