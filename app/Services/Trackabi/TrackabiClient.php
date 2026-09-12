<?php

namespace App\Services\Trackabi;

use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class TrackabiClient
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function listTimeEntries(CarbonInterface $startDate, CarbonInterface $endDate, array $filters = []): array
    {
        if (! config('trackabi.enabled')) {
            throw new RuntimeException('La integración Trackabi no está habilitada. Configura TRACKABI_ENABLED=true.');
        }

        $limit = max((int) config('trackabi.import_limit', 100), 1);
        $maxPages = max((int) config('trackabi.import_max_pages', 100), 1);
        $offset = 0;
        $page = 0;
        $entries = [];

        do {
            $query = array_filter([
                'projectId' => $filters['projectId'] ?? $this->defaultProjectId(),
                'limit' => $limit,
                'offset' => $offset,
                'fields' => config('trackabi.fields'),
                ...$filters,
            ], fn ($value): bool => $value !== null && $value !== '');

            if (! config('trackabi.filter_dates_locally', true)) {
                $query['startDate'] = $startDate->toDateString();
                $query['endDate'] = $endDate->toDateString();
            }

            $query = $this->withConnectionId($query);
            $response = $this->request()->get($this->listTimeEntriesPath(), $query);

            if (! $response->successful()) {
                Log::warning('Trackabi API request failed', [
                    'status' => $response->status(),
                    'path' => $this->listTimeEntriesPath(),
                    'query' => $this->safeQuery($query),
                    'body' => mb_substr($response->body(), 0, 500),
                ]);

                throw new RuntimeException("MindCloud Trackabi respondió HTTP {$response->status()}.");
            }

            $payload = $response->json();

            if (is_array($payload) && array_key_exists('success', $payload) && $payload['success'] === false) {
                $message = $payload['message'] ?? $payload['error'] ?? 'respuesta success=false';

                throw new RuntimeException('MindCloud Trackabi falló: '.(is_string($message) ? $message : 'respuesta inválida.'));
            }

            $pageEntries = $this->extractEntries($payload);
            $entries = array_merge($entries, $pageEntries);
            $offset += $limit;
            $page++;
        } while (count($pageEntries) === $limit && $page < $maxPages);

        return $entries;
    }

    private function request(): PendingRequest
    {
        $request = Http::baseUrl(rtrim((string) config('trackabi.api_base_url'), '/'))
            ->acceptJson()
            ->timeout((int) config('trackabi.timeout', 30))
            ->retry(
                (int) config('trackabi.retry_times', 2),
                (int) config('trackabi.retry_sleep_ms', 500),
                throw: false,
            );
        $connectionId = config('trackabi.connection_id');

        if ($connectionId && config('trackabi.connection_id_location') === 'header') {
            $request = $request->withHeader(
                (string) config('trackabi.connection_id_header', 'X-Connection-Id'),
                (string) $connectionId,
            );
        }

        $token = (string) config('trackabi.api_token');

        if ($token === '') {
            throw new RuntimeException('MINDCLOUD_API_KEY o TRACKABI_API_TOKEN no está configurado.');
        }

        $scheme = (string) config('trackabi.auth_scheme', 'bearer');

        return match ($scheme) {
            'header' => $request->withHeader(
                (string) config('trackabi.auth_header', 'Authorization'),
                $token,
            ),
            'none' => $request,
            default => $request->withToken($token),
        };
    }

    private function listTimeEntriesPath(): string
    {
        return '/'.ltrim((string) config('trackabi.list_time_entries_path', '/actions/list-time-entries'), '/');
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function withConnectionId(array $query): array
    {
        $connectionId = config('trackabi.connection_id');

        if (! $connectionId || config('trackabi.connection_id_location') !== 'query') {
            return $query;
        }

        return ['connectionId' => $connectionId, ...$query];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractEntries(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        $entries = $payload['data']
            ?? $payload['items']
            ?? $payload['timeEntries']
            ?? $payload['entries']
            ?? null;

        if ($entries === null && array_is_list($payload)) {
            $entries = $payload;
        }

        if (! is_array($entries)) {
            return [];
        }

        return collect($entries)
            ->filter(fn ($entry): bool => is_array($entry))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function safeQuery(array $query): array
    {
        return array_diff_key($query, array_flip([
            'token',
            'apiKey',
            'apikey',
            'api_key',
            'Authorization',
            'authorization',
        ]));
    }

    private function defaultProjectId(): ?int
    {
        return strtolower((string) config('trackabi.default_project_name', 'Palmetto')) === 'palmetto'
            ? (int) config('trackabi.palmetto_project_id', 75415)
            : null;
    }
}
