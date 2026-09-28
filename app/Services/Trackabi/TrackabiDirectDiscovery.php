<?php

namespace App\Services\Trackabi;

class TrackabiDirectDiscovery
{
    public function __construct(private readonly TrackabiDirectApiClient $client) {}

    public function discover(): array
    {
        $this->client->assertConfigured();
        $rows = [];
        $raw = [];
        $this->probe('/api/v1/resources', [], $rows, $raw);
        $spec = $this->client->specification();
        if (! is_array($spec['paths'] ?? null) || ! isset($spec['openapi'])) {
            throw new TrackabiDirectApiException('La documentacion recibida no contiene un esquema OpenAPI valido.');
        }
        $activityPaths = [];
        foreach ($spec['paths'] as $path => $definition) {
            if (! is_array($definition) || ! isset($definition['get']) || $path === '/api/v1/resources') {
                continue;
            }
            if (preg_match('/activit|insight|productiv|application|screenshot|timeline|\/apps/i', $path)) {
                $activityPaths[] = $path;
            }
            if (! preg_match('#^/api/v1/[a-zA-Z0-9_/-]+$#D', $path)) {
                $rows[] = ['path' => $this->client->sanitize($path), 'status' => 'No probado (requiere ID)', 'fields' => ''];

                continue;
            }
            $query = [];
            $required = false;
            foreach (array_merge($definition['parameters'] ?? [], $definition['get']['parameters'] ?? []) as $parameter) {
                if (isset($parameter['$ref'])) {
                    $name = basename($parameter['$ref']);
                    $parameter = $spec['components']['parameters'][$name] ?? ['required' => true];
                }
                $required = $required || ($parameter['required'] ?? false);
                if (($parameter['in'] ?? '') === 'query' && in_array($parameter['name'] ?? '', ['page', 'page_size'], true)) {
                    $query[$parameter['name']] = 1;
                }
            }
            if ($required) {
                $rows[] = ['path' => $path, 'status' => 'No probado (parametros requeridos)', 'fields' => ''];

                continue;
            }
            $this->probe($path, $query, $rows, $raw);
        }

        return [
            'version' => $this->client->sanitize($spec['info']['version'] ?? 'desconocida'),
            'rows' => $rows,
            'activity_paths' => $this->client->sanitize($activityPaths),
            'stored_path' => $this->client->storeDiagnostic(['responses' => $raw, 'openapi' => $spec]),
        ];
    }

    private function probe(string $path, array $query, array &$rows, array &$raw): void
    {
        try {
            $payload = $this->client->get($path, $query);
            $raw[$path] = $payload;
            $sample = $payload['data'] ?? $payload;
            if (is_array($sample) && array_is_list($sample)) {
                $sample = $sample[0] ?? [];
            }
            $fields = is_array($sample) ? array_keys($sample) : [];
            $rows[] = ['path' => $path, 'status' => 'Accesible (2xx)',
                'fields' => implode(', ', array_slice($this->client->sanitize($fields), 0, 18))];
        } catch (TrackabiDirectApiException $exception) {
            $rows[] = ['path' => $path, 'status' => $exception->getMessage(), 'fields' => ''];
            $raw[$path] = ['http_status' => $exception->httpStatus, 'error' => $exception->getMessage()];
        }
    }
}
