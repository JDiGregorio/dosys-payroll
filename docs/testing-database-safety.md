# Aislamiento de la base de pruebas

La base restaurada de desarrollo es `planilla_dosys`. La suite de PHPUnit usa
exclusivamente MySQL `planilla_dosys_testing` mediante `phpunit.xml`; toma el
usuario y la contraseña existentes de `.env` sin guardar secretos adicionales.
No crear `.env.testing` con credenciales de producción.

`tests/bootstrap.php` rechaza una cache antes de cualquier test.
`tests/TestCase.php` detiene las pruebas **antes de `RefreshDatabase`** si existe
`bootstrap/cache/config.php`, si el entorno no es `testing`, si la conexión no
es `mysql` o si la base efectiva no es exactamente `planilla_dosys_testing`.
`RefreshDatabase` puede ejecutar `migrate:fresh` en la base de pruebas; nunca
debe ejecutarse con una configuración cacheada de desarrollo o producción.

Para preparar una instalación de Sail donde la base de pruebas no existe:

```bash
docker compose exec mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -u root'
```

Dentro de MySQL:

```sql
CREATE DATABASE IF NOT EXISTS planilla_dosys_testing;
GRANT ALL PRIVILEGES ON planilla_dosys_testing.* TO 'sail'@'%';
```

Antes de la suite local:

```bash
./vendor/bin/sail artisan config:clear
./vendor/bin/sail artisan test --compact
```

No ejecutar `config:cache` antes de la suite. Ese comando sigue siendo apropiado
para producción, pero una cache existente hace que las pruebas fallen de forma
deliberada. Verificar también `APP_ENV=testing` y
`DB_DATABASE=planilla_dosys_testing` antes de ejecutar tests. Un backup reciente
de la base local sigue siendo necesario; el guard no recupera datos perdidos.
