<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\Tests\Service;

use ApplicationLogger\Bundle\Service\ApiClient;
use ApplicationLogger\Bundle\Service\CircuitBreaker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Verifies the bundle ships logs to the Go log-collector with the EXACT HTTP contract
 * (URL/auth/payload) and that the circuit breaker records failures even in async mode.
 *
 * No network is hit: a MockHttpClient captures every request.
 */
final class ApiClientLogShippingTest extends TestCase
{
    private function breaker(bool $enabled = false): CircuitBreaker
    {
        return new CircuitBreaker(
            enabled: $enabled,
            failureThreshold: 3,
            timeout: 60,
            maxHalfOpenAttempts: 1,
            cache: new ArrayAdapter(),
        );
    }

    private function client(
        MockHttpClient $http,
        CircuitBreaker $breaker,
        bool $async = true,
        ?string $logEndpoint = 'https://acme.logs.applogger.eu',
        ?string $logToken = 'sk_log_abc123',
    ): ApiClient {
        return new ApiClient(
            'https://api.applogger.eu/b6d8ed85-c0af-4c02-b6bb-bfb0f3609b37',
            'pk_error_key',
            2.0,
            0,
            $async,
            $breaker,
            null,
            false,
            $http,
            '/api/v1/errors',
            $logEndpoint,
            $logToken,
            '/v1/logs',
        );
    }

    public function testSingleLogShipsToCollectorSingleEndpointWithContract(): void
    {
        $captured = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured) {
            $captured = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse('', ['http_code' => 202]);
        });

        $client = $this->client($http, $this->breaker());

        $sent = $client->sendLog([
            'timestamp' => '2026-06-05T10:00:00+00:00',
            'severity' => 'error',
            'message' => 'disk almost full',
            'app_name' => 'app',
            'environment' => 'production',
            'context' => ['channel' => 'app'],
        ]);

        $this->assertTrue($sent);
        $this->assertSame('POST', $captured['method']);
        // Contract: single log endpoint on the collector host.
        $this->assertSame('https://acme.logs.applogger.eu/v1/logs', $captured['url']);
        // Contract: log token via X-Api-Key (NOT the error public key).
        $this->assertContains('X-Api-Key: sk_log_abc123', $captured['options']['headers']);

        $body = json_decode($captured['options']['body'], true);
        $this->assertSame('disk almost full', $body['message']);
        $this->assertSame('error', $body['severity']);
    }

    public function testMultipleLogsShipToBatchEndpointWrapped(): void
    {
        $captured = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured) {
            $captured = ['url' => $url, 'options' => $options];

            return new MockResponse('', ['http_code' => 202]);
        });

        $client = $this->client($http, $this->breaker());

        $sent = $client->sendLogs([
            ['message' => 'a', 'severity' => 'info'],
            ['message' => 'b', 'severity' => 'warning'],
        ]);

        $this->assertTrue($sent);
        // Contract: batch endpoint is "<log_path>/batch".
        $this->assertSame('https://acme.logs.applogger.eu/v1/logs/batch', $captured['url']);

        $body = json_decode($captured['options']['body'], true);
        // Contract: batch body is {"logs": [...]}.
        $this->assertArrayHasKey('logs', $body);
        $this->assertCount(2, $body['logs']);
        $this->assertSame('a', $body['logs'][0]['message']);
    }

    public function testSendLogNoOpsWhenLogAggregationUnconfigured(): void
    {
        $http = new MockHttpClient(function (): void {
            $this->fail('No HTTP request should be made when log aggregation is unconfigured');
        });

        $client = $this->client($http, $this->breaker(), logEndpoint: null, logToken: null);

        $this->assertFalse($client->sendLog(['message' => 'x']));
    }

    public function testErrorsTargetVersionedApiV1ErrorsEndpoint(): void
    {
        $captured = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured) {
            $captured = ['url' => $url, 'options' => $options];

            return new MockResponse('', ['http_code' => 202]);
        });

        $client = $this->client($http, $this->breaker());
        $client->sendError(['message' => 'boom', 'type' => 'E', 'file' => 'f', 'line' => 1, 'stack_trace' => []]);

        // Canonical versioned route (NOT the deprecated /api/errors/ingest).
        $this->assertSame('https://api.applogger.eu/api/v1/errors', $captured['url']);
        $this->assertContains('X-Api-Key: pk_error_key', $captured['options']['headers']);
    }

    public function testCircuitBreakerTripsInAsyncModeOnConnectionFailure(): void
    {
        $breaker = $this->breaker(enabled: true);

        // Every request fails at the transport layer (e.g. connection refused).
        $http = new MockHttpClient(static function (): MockResponse {
            return new MockResponse('', ['error' => 'Connection refused']);
        });

        $client = $this->client($http, $breaker, async: true);

        $this->assertFalse($breaker->isOpen());

        // failureThreshold is 3; in async mode these failures MUST still be observed.
        $client->sendLog(['message' => '1', 'severity' => 'error']);
        $client->sendLog(['message' => '2', 'severity' => 'error']);
        $client->sendLog(['message' => '3', 'severity' => 'error']);

        $this->assertTrue(
            $breaker->isOpen(),
            'Circuit breaker must trip in async mode when the transport fails'
        );
    }

    public function testCircuitBreakerRecordsSuccessInAsyncModeOn202(): void
    {
        $breaker = $this->breaker(enabled: true);
        $http = new MockHttpClient(static fn () => new MockResponse('', ['http_code' => 202]));

        $client = $this->client($http, $breaker, async: true);
        $client->sendLog(['message' => 'ok', 'severity' => 'info']);

        $state = $breaker->getState();
        $this->assertSame('closed', $state['state']);
        $this->assertSame(0, $state['failureCount']);
    }

    public function testAsyncModeDoesNotBlockWithRetriesConfigured(): void
    {
        // retry_attempts > 0 must NOT cause synchronous usleep in async mode.
        $breaker = $this->breaker(enabled: true);
        $http = new MockHttpClient(static fn () => new MockResponse('', ['error' => 'Connection refused']));

        $client = new ApiClient(
            'https://api.applogger.eu/project-id',
            'pk',
            2.0,
            3,      // retry attempts
            true,   // async
            $breaker,
            null,
            false,
            $http,
        );

        $start = microtime(true);
        $client->sendError(['message' => 'x', 'type' => 'E', 'file' => 'f', 'line' => 1, 'stack_trace' => []]);
        $elapsed = microtime(true) - $start;

        // With sync backoff this would sleep seconds; async must return ~instantly.
        $this->assertLessThan(0.5, $elapsed, 'Async mode must never block on retry backoff');
    }
}
