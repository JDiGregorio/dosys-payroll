# Dosys Payroll

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