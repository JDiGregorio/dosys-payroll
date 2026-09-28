<?php

namespace App\Services\Trackabi;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use JsonException;

class TrackabiDirectApiClient
{
    public const OPENAPI_URL = 'https://trackabi.com/dest/swagger.json';

    public function assertConfigured(): void
    {
        if (! config('trackabi_direct.enabled')) {
            throw new TrackabiDirectApiException('Configura TRACKABI_DIRECT_API_ENABLED=true.');
        }
        if (trim((string) config('trackabi_direct.api_key')) === '') {
            throw new TrackabiDirectApiException('Falta TRACKABI_DIRECT_API_KEY (clave directa de Trackabi).');
        }
        $url = parse_url((string) config('trackabi_direct.base_url'));
        if (! is_array($url) || ($url['scheme'] ?? '') !== 'https' || empty($url['host'])
            || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])) {
            throw new TrackabiDirectApiException('TRACKABI_DIRECT_API_BASE_URL debe ser una URL HTTPS sin credenciales ni query.');
        }
    }

    public function get(string $path, array $query = []): array
    {
        $this->assertConfigured();
        if (! preg_match('#^/api/v1/[a-zA-Z0-9_/-]+$#D', $path)) {
            throw new TrackabiDirectApiException('Ruta directa invalida. Solo se permiten rutas relativas /api/v1/.');
        }

        return $this->request(rtrim((string) config('trackabi_direct.base_url'), '/').$path, $query, true);
    }

    public function specification(): array
    {
        // Public documentation receives no Authorization header.
        return $this->request(self::OPENAPI_URL, [], false);
    }

    private function request(string $url, array $query, bool $authenticated): array
    {
        $request = Http::acceptJson()
            ->timeout(max(1, (int) config('trackabi_direct.timeout', 30)))
            ->connectTimeout(min(10, max(1, (int) config('trackabi_direct.timeout', 30))))
            ->withOptions(['verify' => (bool) config('trackabi_direct.verify_ssl', true), 'allow_redirects' => false])
            ->retry(3, 300, fn ($exception): bool => $exception instanceof ConnectionException
                || ($exception instanceof RequestException
                    && ($exception->response->status() === 429 || $exception->response->serverError())), throw: false);
        if ($authenticated) {
            $request = $request->withToken((string) config('trackabi_direct.api_key'));
        }
        try {
            $response = $request->get($url, $query);
        } catch (ConnectionException) {
            Log::warning('Trackabi direct API: connection failure');
            throw new TrackabiDirectApiException('Trackabi: fallo de red, TLS o timeout. Comprueba conectividad y certificado.');
        } catch (RequestException) {
            throw new TrackabiDirectApiException('Trackabi: fallo HTTP al realizar la consulta.');
        }
        $status = $response->status();
        if (! $response->successful()) {
            Log::warning('Trackabi direct API: HTTP failure', ['status' => $status]);
            $message = match (true) {
                $status === 401 => 'HTTP 401: autenticacion rechazada. Revisa la API key directa y su vigencia.',
                $status === 403 => 'HTTP 403: acceso denegado. Revisa Access to Trackabi API, View insights y los permisos del recurso en el rol.',
                $status === 404 => 'HTTP 404: endpoint o recurso no disponible; esto no confirma autenticacion.',
                $status === 429 => 'HTTP 429: limite de peticiones. Reintenta mas tarde.',
                $status >= 500 => 'Trackabi: error del servidor (HTTP '.$status.').',
                default => 'Trackabi: respuesta HTTP '.$status.'.',
            };
            throw new TrackabiDirectApiException($message, $status);
        }
        try {
            $payload = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            Log::warning('Trackabi direct API: invalid JSON');
            throw new TrackabiDirectApiException('Trackabi: respuesta JSON malformada.', $status);
        }
        if (! is_array($payload)) {
            throw new TrackabiDirectApiException('Trackabi: se esperaba un objeto o lista JSON.', $status);
        }
        if (($payload['success'] ?? null) === false || ! empty($payload['error'])) {
            throw new TrackabiDirectApiException('Trackabi: la respuesta JSON indica un error del proveedor.', $status);
        }

        return $payload;
    }

    public function sanitize(mixed $value): mixed
    {
        if (is_array($value)) {
            $clean = [];
            foreach ($value as $key => $item) {
                $cleanKey = is_string($key) ? $this->sanitize($key) : $key;
                $clean[$cleanKey] = is_string($key) && preg_match('/token|secret|password|authorization|cookie|api.?key|credential/i', $key)
                    ? '[REDACTED]' : $this->sanitize($item);
            }

            return $clean;
        }
        if (! is_string($value)) {
            return $value;
        }
        foreach ([config('trackabi_direct.api_key'), config('trackabi.api_token')] as $key) {
            if (is_string($key) && $key !== '') {
                $value = str_replace([$key, rawurlencode($key)], '[REDACTED]', $value);
            }
        }
        $value = preg_replace('/Bearer\s+[^\s"<>]+/i', 'Bearer [REDACTED]', $value);

        return preg_replace('/([?&](?:token|api[_-]?key|access_token|secret|password)=)[^&\s]+/i', '$1[REDACTED]', $value);
    }

    public function storeDiagnostic(array $payload): ?string
    {
        if (! config('trackabi_direct.store_raw_responses', false)) {
            return null;
        }
        $path = 'trackabi-direct/discovery-'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(4)).'.json';
        if (! Storage::disk('local')->put($path, json_encode($this->sanitize($payload), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR))) {
            throw new TrackabiDirectApiException('No se pudo guardar el diagnostico privado.');
        }

        return $path;
    }
}
