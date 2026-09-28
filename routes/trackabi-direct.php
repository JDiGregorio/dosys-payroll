<?php

use App\Services\Trackabi\TrackabiDirectApiClient;
use App\Services\Trackabi\TrackabiDirectApiException;
use App\Services\Trackabi\TrackabiDirectDiscovery;
use Illuminate\Support\Facades\Artisan;

Artisan::command('trackabi:api-test', function (TrackabiDirectApiClient $client): int {
    try {
        $client->assertConfigured();
        $client->get('/api/v1/company/profile');
        $this->info('Trackabi API ........ OK');
        $this->info('Authentication ...... OK');
    } catch (TrackabiDirectApiException $exception) {
        $this->error($exception->getMessage());

        return self::FAILURE;
    }
    try {
        $resources = $client->get('/api/v1/resources');
        $this->info('Resources endpoint .. OK');
        $items = $resources['resources'] ?? $resources['data'] ?? $resources;
        $this->line('Resources found ..... '.(is_array($items) && array_is_list($items) ? count($items) : 'Formato no confirmado'));
    } catch (TrackabiDirectApiException $exception) {
        if ($exception->httpStatus !== 404) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->warn('Resources endpoint .. No disponible (404)');
        $this->line('Resources found ..... N/A. Usa trackabi:discover con el OpenAPI oficial.');
    }

    return self::SUCCESS;
})->purpose('Comprueba la API oficial de Trackabi sin usar MindCloud ni modificar datos.');

Artisan::command('trackabi:discover', function (TrackabiDirectDiscovery $discovery): int {
    try {
        $report = $discovery->discover();
    } catch (TrackabiDirectApiException $exception) {
        $this->error($exception->getMessage());

        return self::FAILURE;
    }
    $this->info('OpenAPI Trackabi: '.$report['version']);
    $this->table(['GET publicado / resources solicitado', 'Acceso con esta clave', 'Campos observados (sin valores)'],
        array_map(fn ($row) => array_values($row), $report['rows']));
    if ($report['activity_paths'] === []) {
        $this->warn('Activity / Insights / Productive / Unproductive / Apps: no hay rutas GET publicadas en este OpenAPI.');
    } else {
        $this->warn('Candidatos publicados; requieren confirmar respuesta y semantica antes de crear un provider:');
        foreach ($report['activity_paths'] as $path) {
            $this->line($path);
        }
    }
    if ($report['stored_path']) {
        $this->line('Diagnostico sanitizado guardado en disco local privado: '.$report['stored_path']);
    }
    $this->line('No se modificaron empleados, revisiones ni planillas.');

    return collect($report['rows'])->contains(fn ($row) => $row['status'] === 'Accesible (2xx)')
        ? self::SUCCESS : self::FAILURE;
})->purpose('Descubre y prueba rutas GET oficiales; nunca consulta endpoints de actividad inventados.');
