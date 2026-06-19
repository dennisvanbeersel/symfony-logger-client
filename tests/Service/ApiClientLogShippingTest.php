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

    public function testEmptyStringLogEndpointOrTokenNoOps(): void
    {
        // Regression (CFG-01): env placeholders resolve to '' (not null) when unset, so an
        // empty endpoint/token must no-op exactly like null — NOT build a malformed URL and
        // penalise the breaker. The base config wires these to %env()% with '' defaults.
        $http = new MockHttpClient(function (): void {
            $this->fail('No HTTP request may be made when log aggregation is empty/unconfigured');
        });

        $emptyEndpoint = $this->client($http, $this->breaker(), logEndpoint: '', logToken: 'sk_log_abc123');
        $this->assertFalse($emptyEndpoint->sendLog(['message' => 'x', 'severity' => 'error']));
        $this->assertFalse($emptyEndpoint->sendLogs([['message' => 'a'], ['message' => 'b']]));

        $emptyToken = $this->client($http, $this->breaker(), logEndpoint: 'https://acme.logs.applogger.eu', logToken: '');
        $this->assertFalse($emptyToken->sendLog(['message' => 'y', 'severity' => 'error']));
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

    public function testLogPathUsesAnIndependentCircuitBreakerFromErrors(): void
    {
        // Regression (ASYNC-4): a failing log collector must trip its OWN breaker and
        // shed load, WITHOUT a healthy error/session endpoint resetting a shared breaker
        // (which previously masked the outage and kept paying the per-flush timeout).
        $errorBreaker = $this->breaker(enabled: true); // platform path
        $logBreaker = $this->breaker(enabled: true);   // collector path (threshold 3)

        // Errors succeed (202); log sends fail at the transport layer.
        $http = new MockHttpClient(static function (string $method, string $url): MockResponse {
            if (str_contains($url, '/v1/logs')) {
                return new MockResponse('', ['error' => 'Connection refused']);
            }

            return new MockResponse('', ['http_code' => 202]);
        });

        $client = new ApiClient(
            'https://api.applogger.eu/b6d8ed85-c0af-4c02-b6bb-bfb0f3609b37',
            'pk_error_key',
            2.0,
            0,
            true,
            $errorBreaker,
            null,
            false,
            $http,
            '/api/v1/errors',
            'https://acme.logs.applogger.eu',
            'sk_log_abc123',
            '/v1/logs',
            true,
            $logBreaker,
        );

        // Interleave healthy errors with failing logs. The error successes must NOT
        // keep the log breaker closed.
        for ($i = 0; $i < 3; ++$i) {
            $client->sendError(['message' => 'ok', 'type' => 'E', 'file' => 'f', 'line' => 1, 'stack_trace' => []]);
            $client->sendLog(['message' => 'fail', 'severity' => 'error']);
        }

        $this->assertTrue($logBreaker->isOpen(), 'Log breaker must trip on a failing collector');
        $this->assertFalse($errorBreaker->isOpen(), 'Error breaker must stay closed while the platform is healthy');
        $this->assertSame(0, $errorBreaker->getState()['failureCount'], 'Healthy error path must not accrue failures from the failing log path');
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
