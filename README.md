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
