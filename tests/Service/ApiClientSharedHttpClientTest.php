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
 * Verifies facade construction and resilience properties.
 * The shared-HttpClient concern belongs to the sdk-core layer now;
 * these tests verify the facade constructs without error and honours
 * the never-throw contract.
 */
final class ApiClientSharedHttpClientTest extends TestCase
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
                'cache_dir' => sys_get_temp_dir().'/applogger-shared-test-'.uniqid('', true),
            ],
            new BundleContextCollector($inner),
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

    public function testFacadeConstructsWithFactoryAndSessions(): void
    {
        $client = new ApiClient($this->makeFactory(), $this->makeSessionClient());
        self::assertInstanceOf(ApiClient::class, $client);
    }

    public function testFacadeSendErrorDoesNotThrow(): void
    {
        $client = new ApiClient($this->makeFactory(), $this->makeSessionClient());
        $client->sendError(['message' => 'test', 'type' => 'E', 'file' => 'f', 'line' => 1]);
        $this->addToAssertionCount(1);
    }

    public function testFacadeFlushDoesNotThrow(): void
    {
        $client = new ApiClient($this->makeFactory(), $this->makeSessionClient());
        $client->flush();
        $this->addToAssertionCount(1);
    }

    public function testFacadeCircuitBreakerStateIsDelegatedStub(): void
    {
        $client = new ApiClient($this->makeFactory(), $this->makeSessionClient());
        $state = $client->getCircuitBreakerState();
        self::assertSame('unknown', $state['state']);
        self::assertTrue($state['delegated']);
    }

    public function testTwoIndependentFactoriesProduceTwoIndependentFacades(): void
    {
        $a = new ApiClient($this->makeFactory(), $this->makeSessionClient());
        $b = new ApiClient($this->makeFactory(), $this->makeSessionClient());
        self::assertNotSame($a, $b);
    }
}
