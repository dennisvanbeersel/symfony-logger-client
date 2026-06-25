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
 * Unit tests for ApiClient session tracking methods (facade delegation).
 *
 * Session methods delegate to SessionApiClient. With an empty DSN + disabled
 * client all calls are inert — the methods must never throw (resilience contract).
 */
final class ApiClientSessionTest extends TestCase
{
    private ApiClient $apiClient;

    protected function setUp(): void
    {
        $inner = $this->createMock(ContextCollectorInterface::class);
        $inner->method('collectContext')->willReturn([]);

        $factory = new SdkClientFactory(
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
                'cache_dir' => sys_get_temp_dir().'/applogger-session-test-'.uniqid('', true),
            ],
            new BundleContextCollector($inner),
            new LoopbackGuard(new RequestStack(), []),
        );

        $sessions = new SessionApiClient(
            dsn: '',
            apiKey: '',
            httpClient: new MockHttpClient(),
            loopback: new LoopbackGuard(new RequestStack(), []),
            breaker: new CircuitBreaker(new ArrayAdapter(), new SystemClock()),
            enabled: false,
        );

        $this->apiClient = new ApiClient($factory, $sessions);
    }

    public function testCreateSessionDoesNotThrow(): void
    {
        $this->apiClient->createSession([
            'session_id' => 'test-session-123',
            'started_at' => '2024-10-26T12:00:00+00:00',
            'platform' => 'web',
        ]);
        $this->addToAssertionCount(1);
    }

    public function testAddSessionEventDoesNotThrow(): void
    {
        $this->apiClient->addSessionEvent('test-session-123', [
            'type' => 'PAGE_VIEW',
            'url' => 'https://example.com/page',
        ]);
        $this->addToAssertionCount(1);
    }

    public function testEndSessionDoesNotThrow(): void
    {
        $this->apiClient->endSession('test-session-123', new \DateTimeImmutable('2024-10-26T13:00:00+00:00'));
        $this->addToAssertionCount(1);
    }

    public function testEndSessionWithoutTimestampDoesNotThrow(): void
    {
        $this->apiClient->endSession('test-session-123');
        $this->addToAssertionCount(1);
    }

    public function testCreateSessionAddsDefaultTimestamp(): void
    {
        $this->apiClient->createSession(['session_id' => 'test-session-123', 'platform' => 'web']);
        $this->addToAssertionCount(1);
    }

    public function testAddSessionEventAddsDefaultTimestamp(): void
    {
        $this->apiClient->addSessionEvent('test-session-123', [
            'type' => 'PAGE_VIEW',
            'url' => 'https://example.com',
        ]);
        $this->addToAssertionCount(1);
    }

    public function testMultipleSessionCallsDoNotThrow(): void
    {
        $sessionId = 'multi-test-session';

        $this->apiClient->createSession([
            'session_id' => $sessionId,
            'started_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);

        for ($i = 0; $i < 5; ++$i) {
            $this->apiClient->addSessionEvent($sessionId, [
                'type' => 'PAGE_VIEW',
                'url' => "https://example.com/page-{$i}",
            ]);
        }

        $this->apiClient->endSession($sessionId);
        $this->addToAssertionCount(1);
    }

    public function testCircuitBreakerPreventsCallsWithoutThrowing(): void
    {
        // Even with a real tripped breaker, facade session calls must not throw.
        $breaker = new CircuitBreaker(
            cache: new ArrayAdapter(),
            clock: new SystemClock(),
            failureThreshold: 1,
        );
        $breaker->recordFailure(); // trip to OPEN

        $sessions = new SessionApiClient(
            dsn: 'https://localhost:9999/proj',
            apiKey: 'pk',
            httpClient: new MockHttpClient(),
            loopback: new LoopbackGuard(new RequestStack(), []),
            breaker: $breaker,
            enabled: true,
        );

        $inner = $this->createMock(ContextCollectorInterface::class);
        $inner->method('collectContext')->willReturn([]);
        $factory = new SdkClientFactory(
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
                'cache_dir' => sys_get_temp_dir().'/applogger-session-breaker-'.uniqid('', true),
            ],
            new BundleContextCollector($inner),
            new LoopbackGuard(new RequestStack(), []),
        );

        $client = new ApiClient($factory, $sessions);
        $client->createSession(['session_id' => 'test-session']);
        $client->addSessionEvent('test', ['type' => 'CLICK']);
        $client->endSession('test');

        $this->addToAssertionCount(1);
    }
}
