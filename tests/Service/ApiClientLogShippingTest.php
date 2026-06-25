<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\Tests\Service;

use ApplicationLogger\Bundle\Service\ApiClient;
use ApplicationLogger\Bundle\Service\ContextCollectorInterface;
use ApplicationLogger\Bundle\Service\Sdk\BundleContextCollector;
use ApplicationLogger\Bundle\Service\Sdk\LoopbackGuard;
use ApplicationLogger\Bundle\Service\Sdk\SdkClientFactory;
use ApplicationLogger\Bundle\Service\Sdk\SessionApiClient;
use ApplicationLogger\Sdk\CircuitBreaker;
use ApplicationLogger\Sdk\Clock\SystemClock;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Verifies the log-shipping methods on the ApiClient facade.
 *
 * The legacy tests verified HTTP contract details of the legacy direct-HTTP log path,
 * now delegated to sdk-core's LogClient. These tests verify the facade methods do not
 * throw and return the correct types (resilience contract).
 */
final class ApiClientLogShippingTest extends TestCase
{
    private function makeFactory(): SdkClientFactory
    {
        $inner = $this->createMock(ContextCollectorInterface::class);
        $inner->method('collectContext')->willReturn([]);

        return new SdkClientFactory(
            [
                'dsn' => '',
                'api_key' => '',
                'environment' => 'test',
                'release' => null,
                'enabled' => false,
                'scrub_fields' => [],
                'max_breadcrumbs' => 50,
                'timeout' => 2.0,
                'flush_budget' => 2.0,
                'circuit_breaker' => ['failure_threshold' => 5, 'timeout' => 60, 'half_open_attempts' => 1],
                'log_endpoint' => null,
                'log_token' => null,
                'session_hash_salt' => null,
                'app_name' => 'test',
                'cache_dir' => sys_get_temp_dir().'/applogger-log-test-'.uniqid('', true),
            ],
            new BundleContextCollector($this->createMock(ContextCollectorInterface::class)),
            new LoopbackGuard(new RequestStack(), []),
        );
    }

    private function makeSessionClient(): SessionApiClient
    {
        return new SessionApiClient(
            dsn: '',
            apiKey: '',
            httpClient: new MockHttpClient(),
            loopback: new LoopbackGuard(new RequestStack(), []),
            breaker: new CircuitBreaker(new ArrayAdapter(), new SystemClock()),
            enabled: false,
        );
    }

    private function makeApiClient(): ApiClient
    {
        return new ApiClient($this->makeFactory(), $this->makeSessionClient());
    }

    public function testSendLogDoesNotThrow(): void
    {
        $client = $this->makeApiClient();
        $client->sendLog([
            'timestamp' => '2026-06-05T10:00:00+00:00',
            'severity' => 'error',
            'message' => 'disk almost full',
            'app_name' => 'app',
            'environment' => 'production',
            'context' => ['channel' => 'app'],
        ]);
        $this->addToAssertionCount(1);
    }

    public function testSendLogsDoesNotThrow(): void
    {
        $client = $this->makeApiClient();
        $client->sendLogs([
            ['message' => 'a', 'severity' => 'info'],
            ['message' => 'b', 'severity' => 'warning'],
        ]);
        $this->addToAssertionCount(1);
    }

    public function testSendLogWithNoLogClientDoesNotThrow(): void
    {
        // With no log_endpoint/log_token, sdk-core builds no LogClient; facade swallows gracefully.
        $client = $this->makeApiClient();
        $client->sendLog(['message' => 'x']);
        $this->addToAssertionCount(1);
    }

    public function testSendLogEmptyEntriesDoesNotThrow(): void
    {
        $client = $this->makeApiClient();
        $client->sendLogs([]);
        $this->addToAssertionCount(1);
    }

    public function testSendLogSyncReturnsNullWhenNoLogClient(): void
    {
        // No log_endpoint → no LogClient → returns null
        $client = $this->makeApiClient();
        $result = $client->sendLogSync(['message' => 'ping', 'severity' => 'info']);
        self::assertNull($result);
    }

    public function testSendErrorDoesNotThrow(): void
    {
        $client = $this->makeApiClient();
        $client->sendError(['message' => 'boom', 'type' => 'E', 'file' => 'f', 'line' => 1]);
        $this->addToAssertionCount(1);
    }

    public function testGetCircuitBreakerStateReturnsDelegatedStub(): void
    {
        $client = $this->makeApiClient();
        $state = $client->getCircuitBreakerState();
        self::assertSame('unknown', $state['state']);
        self::assertTrue($state['delegated']);
    }

    public function testGetLogCircuitBreakerStateReturnsDelegatedStub(): void
    {
        $client = $this->makeApiClient();
        $state = $client->getLogCircuitBreakerState();
        self::assertSame('unknown', $state['state']);
        self::assertTrue($state['delegated']);
    }

    public function testFlushDoesNotThrow(): void
    {
        $client = $this->makeApiClient();
        $client->sendLog(['message' => 'buffered', 'severity' => 'error']);
        $client->flush();
        $this->addToAssertionCount(1);
    }
}
