# Horas verificadas de Elalf y Marco, período 9

El comando `payroll:correct-september-second-half-tracking` se limita al período
abierto 9 (2026-09-11 al 2026-09-25) y a Elalf Shamir Dominguez Pineda y
Marco Antonio Lara. Su primera ejecución es una vista previa.

Las horas verificadas se guardan en `daily_time_reviews.verified_tracked_seconds`
con fuente, nota y fecha. Las entradas Trackabi importadas y su `loggedTime`
permanecen intactos. El detalle diario muestra ambos valores. Recalcular el
período conserva las horas verificadas, justificaciones, estados, comentarios,
bonos y deducciones.

Elalf: los sábados 12 y 19 se consideran jornadas de 8 horas ordinarias y 1
hora extra preasignada. Las horas verificadas son 8:54 y 8:51. Los 6 y 9
minutos restantes reducen la hora extra pagada; el supervisor puede justificar
el tiempo en la revisión diaria.

Marco: se usan las 15 horas diarias verificadas. De lunes a viernes se esperan
8 horas ordinarias. Sábados y domingos solo generan horas extra, hasta 10
horas extra pagadas por semana. Se asigna primero el tiempo de fin de semana,
repartido entre sus fechas si rebasa el saldo, y después el excedente de las
8 horas ordinarias de los días laborables. Se consideran horas extra ya
pagadas en otro período durante la misma semana. En la copia local consultada,
la semana del 7 al 13 tenía 4:04 pagadas en el período anterior, por lo que
quedaban 5:56 disponibles en el período 9.

El tiempo ordinario no cubierto aparece como faltante en la revisión. Una
justificación agrega crédito según el cálculo de planilla existente. Los días
del fin de semana no se tratan como ausencias ordinarias ni generan deducción
por no completar una jornada de 8 horas.

Después de desplegar el código en producción:

```bash
./vendor/bin/sail artisan migrate --force
./vendor/bin/sail artisan payroll:correct-september-second-half-tracking --period=9
./vendor/bin/sail artisan payroll:correct-september-second-half-tracking --period=9 --apply
./vendor/bin/sail artisan view:clear
```

`--apply` recalcula solo a Elalf y Marco. Repetirlo no crea ajustes adicionales.
No es necesario reimportar Trackabi ni ejecutar otro comando de recálculo.
