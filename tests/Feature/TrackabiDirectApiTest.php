<?php

namespace Tests\Feature;

use App\Services\Trackabi\TrackabiClient;
use App\Services\Trackabi\TrackabiDirectApiClient;
use App\Services\Trackabi\TrackabiDirectApiException;
use App\Services\Trackabi\TrackabiDirectDiscovery;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TrackabiDirectApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['trackabi_direct.enabled' => true, 'trackabi_direct.api_key' => 'direct-secret-key',
            'trackabi_direct.base_url' => 'https://api.trackabi.com', 'trackabi_direct.timeout' => 5,
            'trackabi_direct.verify_ssl' => true, 'trackabi_direct.store_raw_responses' => false]);
        Storage::fake('local');
    }

    public function test_direct_authentication_and_query_are_separate_from_mindcloud(): void
    {
        config(['trackabi.api_token' => 'mindcloud-secret']);
        Http::fake(['api.trackabi.com/*' => Http::response(['data' => [['id' => 1]]])]);
        $this->assertSame(['data' => [['id' => 1]]], app(TrackabiDirectApiClient::class)->get('/api/v1/members', ['page_size' => 1]));
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer direct-secret-key')
            && $request['page_size'] === 1 && ! isset($request['connectionId']));
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public static function errorStatuses(): array
    {
        return [[401, 1], [403, 1], [404, 1], [429, 3], [500, 3], [503, 3], [302, 1]];
    }

    #[DataProvider('errorStatuses')]
    public function test_errors_are_safe_and_only_transient_statuses_retry(int $status, int $attempts): void
    {
        Http::fake(['api.trackabi.com/*' => Http::response(['error' => 'direct-secret-key'], $status)]);
        try {
            app(TrackabiDirectApiClient::class)->get('/api/v1/members');
            $this->fail('Expected a safe API exception');
        } catch (TrackabiDirectApiException $exception) {
            $this->assertSame($status, $exception->httpStatus);
            $this->assertStringNotContainsString('direct-secret-key', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
        Http::assertSentCount($attempts);
    }

    public function test_transient_failure_can_recover(): void
    {
        Http::fake(['api.trackabi.com/*' => Http::sequence()->pushStatus(429)->push(['data' => []])]);
        $this->assertSame(['data' => []], app(TrackabiDirectApiClient::class)->get('/api/v1/members'));
        Http::assertSentCount(2);
    }

    public function test_connection_timeout_does_not_expose_request_exception(): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException('timeout Authorization: Bearer direct-secret-key');
        });
        try {
            app(TrackabiDirectApiClient::class)->get('/api/v1/members');
            $this->fail('Expected timeout exception');
        } catch (TrackabiDirectApiException $exception) {
            $this->assertStringContainsString('timeout', $exception->getMessage());
            $this->assertStringNotContainsString('direct-secret-key', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
        $this->assertSame(3, $attempts);
    }

    public function test_malformed_json_is_not_retried_or_printed(): void
    {
        Http::fake(['api.trackabi.com/*' => Http::response('<html>direct-secret-key</html>')]);
        try {
            app(TrackabiDirectApiClient::class)->get('/api/v1/members');
            $this->fail('Expected JSON exception');
        } catch (TrackabiDirectApiException $exception) {
            $this->assertStringContainsString('JSON malformada', $exception->getMessage());
            $this->assertStringNotContainsString('direct-secret-key', $exception->getMessage());
        }
        Http::assertSentCount(1);
    }

    public function test_disabled_or_missing_key_makes_no_request(): void
    {
        Http::fake();
        config(['trackabi_direct.enabled' => false]);
        $this->artisan('trackabi:api-test')->expectsOutputToContain('TRACKABI_DIRECT_API_ENABLED')->assertFailed();
        config(['trackabi_direct.enabled' => true, 'trackabi_direct.api_key' => '']);
        $this->artisan('trackabi:api-test')->expectsOutputToContain('TRACKABI_DIRECT_API_KEY')->assertFailed();
        Http::assertNothingSent();
    }

    public function test_api_test_validates_auth_even_when_resources_is_not_available(): void
    {
        Http::fake([
            'api.trackabi.com/api/v1/company/profile' => Http::response(['data' => ['name' => 'Example']]),
            'api.trackabi.com/api/v1/resources' => Http::response(['error' => 'Not found'], 404),
        ]);
        $this->artisan('trackabi:api-test')->expectsOutputToContain('Authentication ...... OK')
            ->expectsOutputToContain('No disponible (404)')->assertSuccessful();
    }

    public function test_discovery_only_probes_published_get_routes_without_required_parameters(): void
    {
        Http::fake([
            TrackabiDirectApiClient::OPENAPI_URL => Http::response(['openapi' => '3.0.0', 'paths' => [
                '/api/v1/members' => ['get' => ['parameters' => [['in' => 'query', 'name' => 'page_size']]]],
                '/api/v1/time-entry/{id}' => ['get' => []],
                '/api/v1/tasks' => ['post' => []],
                '/api/v1/leaves' => ['get' => ['parameters' => [['in' => 'query', 'name' => 'date', 'required' => true]]]],
            ]]),
            'api.trackabi.com/api/v1/resources' => Http::response([], 404),
            'api.trackabi.com/api/v1/members*' => Http::response(['data' => [['id' => 1, 'email' => 'private@example.com']]]),
        ]);
        $report = app(TrackabiDirectDiscovery::class)->discover();
        $this->assertSame([], $report['activity_paths']);
        $this->assertNull($report['stored_path']);
        $this->assertStringNotContainsString('private@example.com', json_encode($report));
        Http::assertSentCount(3);
        Http::assertSent(fn ($request) => $request->url() === TrackabiDirectApiClient::OPENAPI_URL && ! $request->hasHeader('Authorization'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/members') && $request['page_size'] === 1);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_optional_raw_storage_recursively_sanitizes_secrets(): void
    {
        config(['trackabi_direct.store_raw_responses' => true, 'trackabi.api_token' => 'mindcloud-secret']);
        $path = app(TrackabiDirectApiClient::class)->storeDiagnostic([
            'api_key' => 'other-secret', 'nested' => ['Authorization' => 'Bearer another-secret',
                'echo' => 'direct-secret-key mindcloud-secret', 'url' => 'https://example.test?token=third-secret'],
            'desktop_activity_seconds' => null,
        ]);
        $raw = Storage::disk('local')->get($path);
        foreach (['other-secret', 'another-secret', 'direct-secret-key', 'mindcloud-secret', 'third-secret'] as $secret) {
            $this->assertStringNotContainsString($secret, $raw);
        }
        $this->assertNull(json_decode($raw, true)['desktop_activity_seconds']);
    }

    public function test_direct_failure_does_not_break_existing_mindcloud_client(): void
    {
        config(['trackabi.enabled' => true, 'trackabi.api_base_url' => 'https://mindcloud.test',
            'trackabi.api_token' => 'mindcloud-secret', 'trackabi.import_limit' => 100]);
        Http::fake([
            'api.trackabi.com/*' => Http::response([], 403),
            'mindcloud.test/*' => Http::response(['success' => true, 'data' => [['id' => 1, 'loggedTime' => '08:00:00']]]),
        ]);
        $this->artisan('trackabi:api-test')->expectsOutputToContain('View insights')->assertFailed();
        $rows = app(TrackabiClient::class)->listTimeEntries(Carbon::parse('2026-09-11'), Carbon::parse('2026-09-25'));
        $this->assertSame('08:00:00', $rows[0]['loggedTime']);
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://mindcloud.test')
            && $request->hasHeader('Authorization', 'Bearer mindcloud-secret'));
    }
}
