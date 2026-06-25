<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\Tests\Service;

use ApplicationLogger\Bundle\Service\ApiClient;
use ApplicationLogger\Bundle\Service\ContextCollectorInterface;
use ApplicationLogger\Bundle\Service\Sdk\BundleContextCollector;
use ApplicationLogger\Bundle\Service\Sdk\LoopbackGuard;
use ApplicationLogger\Bundle\Service\Sdk\SdkClientFactory;
use ApplicationLogger\Bundle\Service\Sdk\SessionApiClient;
use ApplicationLogger\Bundle\Service\Sdk\SessionClientInterface;
use ApplicationLogger\Sdk\CircuitBreaker;
use ApplicationLogger\Sdk\Clock\SystemClock;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpFoundation\RequestStack;

final class ApiClientTest extends TestCase
{
    private function makeFactory(): SdkClientFactory
    {
        $inner = $this->createMock(ContextCollectorInterface::class);
        $inner->method('collectContext')->willReturn([]);
        $ctx = new BundleContextCollector($inner);

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
                'cache_dir' => sys_get_temp_dir().'/applogger-test-'.uniqid('', true),
            ],
            $ctx,
            new LoopbackGuard(new RequestStack(), []),
        );
    }

    private function makeBreaker(): CircuitBreaker
    {
        return new CircuitBreaker(new ArrayAdapter(), new SystemClock());
    }

    private function makeSessionClient(): SessionApiClient
    {
        return new SessionApiClient(
            dsn: '',
            apiKey: '',
            httpClient: new MockHttpClient(),
            loopback: new LoopbackGuard(new RequestStack(), []),
            breaker: $this->makeBreaker(),
            enabled: false,
        );
    }

    private function makeApiClient(): ApiClient
    {
        return new ApiClient($this->makeFactory(), $this->makeSessionClient());
    }

    public function testConstructsWithoutThrowing(): void
    {
        $client = $this->makeApiClient();
        self::assertInstanceOf(ApiClient::class, $client);
    }

    public function testSendErrorDoesNotThrow(): void
    {
        $client = $this->makeApiClient();
        $client->sendError(['message' => 'test', 'type' => 'ErrorTest']);
        $this->addToAssertionCount(1);
    }

    public function testSendErrorWithMinimalPayload(): void
    {
        $client = $this->makeApiClient();
        $client->sendError([]);
        $this->addToAssertionCount(1);
    }

    public function testSendErrorWithAllFields(): void
    {
        $client = $this->makeApiClient();
        $client->sendError([
            'type' => 'RuntimeException',
            'message' => 'Something went wrong',
            'file' => __FILE__,
            'line' => 42,
            'level' => 'error',
            'timestamp' => new \DateTimeImmutable(),
        ]);
        $this->addToAssertionCount(1);
    }

    public function testSendErrorWithTimestampDateTimeImmutable(): void
    {
        $client = $this->makeApiClient();
        $client->sendError([
            'message' => 'with ts',
            'timestamp' => new \DateTimeImmutable(),
            'level' => 'error',
            'file' => __FILE__,
            'line' => __LINE__,
        ]);
        $this->addToAssertionCount(1);
    }

    public function testSendLogDoesNotThrow(): void
    {
        $client = $this->makeApiClient();
        $client->sendLog(['message' => 'hello', 'level' => 'info']);
        $this->addToAssertionCount(1);
    }

    public function testSendLogWithSeverityField(): void
    {
        $client = $this->makeApiClient();
        $client->sendLog(['severity' => 'warning', 'message' => 'msg']);
        $this->addToAssertionCount(1);
    }

    public function testSendLogsDoesNotThrow(): void
    {
        $client = $this->makeApiClient();
        $client->sendLogs([['message' => 'a'], ['message' => 'b']]);
        $this->addToAssertionCount(1);
    }

    public function testSendLogsEmptyArrayReturnsFalse(): void
    {
        $client = $this->makeApiClient();
        self::assertFalse($client->sendLogs([]));
    }

    public function testSendLogsNonEmptyReturnsTrue(): void
    {
        $client = $this->makeApiClient();
        self::assertTrue($client->sendLogs([['message' => 'x']]));
    }

    public function testCreateSessionDoesNotThrowWhenSessionClientThrows(): void
    {
        $sessions = $this->createMock(SessionClientInterface::class);
        $sessions->method('createSession')->willThrowException(new \RuntimeException('boom'));
        $client = new ApiClient($this->makeFactory(), $sessions);
        $client->createSession(['session_id' => 'x']);
        $this->addToAssertionCount(1);
    }

    public function testAddSessionEventDoesNotThrowWhenSessionClientThrows(): void
    {
        $sessions = $this->createMock(SessionClientInterface::class);
        $sessions->method('addSessionEvent')->willThrowException(new \RuntimeException('boom'));
        $client = new ApiClient($this->makeFactory(), $sessions);
        $client->addSessionEvent('x', ['type' => 'click']);
        $this->addToAssertionCount(1);
    }

    public function testEndSessionDoesNotThrowWhenSessionClientThrows(): void
    {
        $sessions = $this->createMock(SessionClientInterface::class);
        $sessions->method('endSession')->willThrowException(new \RuntimeException('boom'));
        $client = new ApiClient($this->makeFactory(), $sessions);
        $client->endSession('x');
        $this->addToAssertionCount(1);
    }

    public function testSendLogSyncReturnsNullWhenNoLogClient(): void
    {
        // Factory built with no log_endpoint/log_token → no LogClient → returns null
        $client = $this->makeApiClient();
        $result = $client->sendLogSync(['message' => 'sync', 'level' => 'warning']);
        self::assertNull($result);
    }

    public function testFlushDoesNotThrow(): void
    {
        $client = $this->makeApiClient();
        $client->flush();
        $this->addToAssertionCount(1);
    }

    public function testGetCircuitBreakerStateReturnsDeprecatedStub(): void
    {
        $client = $this->makeApiClient();
        $state = $client->getCircuitBreakerState();
        self::assertSame('unknown', $state['state']);
        self::assertTrue($state['delegated']);
    }

    public function testGetLogCircuitBreakerStateReturnsDeprecatedStub(): void
    {
        $client = $this->makeApiClient();
        $state = $client->getLogCircuitBreakerState();
        self::assertSame('unknown', $state['state']);
        self::assertTrue($state['delegated']);
    }

    public function testFlushCeilingForTestingReturnsZeroWhenNotHalfOpen(): void
    {
        $client = $this->makeApiClient();
        self::assertSame(0.0, $client->flushCeilingForTesting(false));
    }

    public function testFlushCeilingForTestingReturnsZeroWhenHalfOpen(): void
    {
        $client = $this->makeApiClient();
        self::assertSame(0.0, $client->flushCeilingForTesting(true));
    }

    public function testCreateSessionDoesNotThrow(): void
    {
        $client = $this->makeApiClient();
        $client->createSession(['session_id' => 'abc123']);
        $this->addToAssertionCount(1);
    }

    public function testAddSessionEventDoesNotThrow(): void
    {
        $client = $this->makeApiClient();
        $client->addSessionEvent('abc123', ['type' => 'click']);
        $this->addToAssertionCount(1);
    }

    public function testEndSessionDoesNotThrow(): void
    {
        $client = $this->makeApiClient();
        $client->endSession('abc123');
        $this->addToAssertionCount(1);
    }

    public function testEndSessionWithEndedAt(): void
    {
        $client = $this->makeApiClient();
        $client->endSession('abc123', new \DateTimeImmutable());
        $this->addToAssertionCount(1);
    }

    public function testSendErrorNeverThrowsOnBadPayload(): void
    {
        $client = $this->makeApiClient();
        // Non-string/non-int values for typed fields — facade must swallow all
        $client->sendError(['type' => 123, 'message' => null, 'line' => 'not-an-int']);
        $this->addToAssertionCount(1);
    }
}
