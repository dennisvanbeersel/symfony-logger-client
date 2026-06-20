<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\Tests\Service;

use ApplicationLogger\Bundle\Service\ApiClient;
use ApplicationLogger\Bundle\Service\CircuitBreaker;
use ApplicationLogger\Bundle\Service\Http\ResilientHttpDispatcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * BUNDLE-4 — both the error/session dispatcher and the dedicated log dispatcher must
 * share ONE underlying HttpClient (a single cURL connection pool) instead of each
 * calling HttpClient::create() and spinning up its own CurlHttpClient. The per-endpoint
 * circuit breakers stay independent; only the transport is shared.
 */
final class ApiClientSharedHttpClientTest extends TestCase
{
    private function breaker(string $key = 'cb'): CircuitBreaker
    {
        return new CircuitBreaker(
            enabled: true,
            failureThreshold: 3,
            timeout: 60,
            maxHalfOpenAttempts: 1,
            cache: new ArrayAdapter(),
            cacheKey: $key,
        );
    }

    private function dispatcherHttpClient(ApiClient $client, string $dispatcherProp): HttpClientInterface
    {
        $apiRef = new \ReflectionObject($client);
        $dispatcher = $apiRef->getProperty($dispatcherProp)->getValue($client);
        self::assertInstanceOf(ResilientHttpDispatcher::class, $dispatcher);

        $dispRef = new \ReflectionObject($dispatcher);
        $http = $dispRef->getProperty('httpClient')->getValue($dispatcher);
        self::assertInstanceOf(HttpClientInterface::class, $http);

        return $http;
    }

    public function testInjectedHttpClientIsSharedByBothDispatchers(): void
    {
        $injected = new MockHttpClient();

        $client = new ApiClient(
            dsn: 'https://api.applogger.eu/proj-id',
            apiKey: 'pk_error',
            timeout: 2.0,
            retryAttempts: 0,
            async: true,
            circuitBreaker: $this->breaker('error'),
            logger: null,
            debug: false,
            httpClient: $injected,
            errorPath: '/api/v1/errors',
            logEndpoint: 'https://logs.applogger.eu',
            logToken: 'sk_log_abc',
            logPath: '/v1/logs',
            enabled: true,
            logCircuitBreaker: $this->breaker('log'),
        );

        $errorHttp = $this->dispatcherHttpClient($client, 'dispatcher');
        $logHttp = $this->dispatcherHttpClient($client, 'logDispatcher');

        $this->assertSame($injected, $errorHttp, 'error dispatcher must use the injected client');
        $this->assertSame($injected, $logHttp, 'log dispatcher must use the injected client');
        $this->assertSame($errorHttp, $logHttp, 'both dispatchers must share ONE HttpClient');
    }

    public function testSelfCreatedHttpClientIsSharedByBothDispatchers(): void
    {
        // No client injected: ApiClient must create exactly ONE shared client and pass
        // it to both dispatchers (not one CurlHttpClient per dispatcher).
        $client = new ApiClient(
            dsn: 'https://api.applogger.eu/proj-id',
            apiKey: 'pk_error',
            timeout: 2.0,
            retryAttempts: 0,
            async: true,
            circuitBreaker: $this->breaker('error'),
            logger: null,
            debug: false,
            httpClient: null,
            errorPath: '/api/v1/errors',
            logEndpoint: 'https://logs.applogger.eu',
            logToken: 'sk_log_abc',
            logPath: '/v1/logs',
            enabled: true,
            logCircuitBreaker: $this->breaker('log'),
        );

        $errorHttp = $this->dispatcherHttpClient($client, 'dispatcher');
        $logHttp = $this->dispatcherHttpClient($client, 'logDispatcher');

        $this->assertSame(
            $errorHttp,
            $logHttp,
            'with no injected client, both dispatchers must still share ONE self-created HttpClient',
        );
    }

    public function testCircuitBreakersRemainIndependentDespiteSharedClient(): void
    {
        // Sharing the transport must NOT collapse the two breakers into one.
        $errorBreaker = $this->breaker('error');
        $logBreaker = $this->breaker('log');

        $client = new ApiClient(
            dsn: 'https://api.applogger.eu/proj-id',
            apiKey: 'pk_error',
            timeout: 2.0,
            retryAttempts: 0,
            async: true,
            circuitBreaker: $errorBreaker,
            logger: null,
            debug: false,
            httpClient: new MockHttpClient(),
            errorPath: '/api/v1/errors',
            logEndpoint: 'https://logs.applogger.eu',
            logToken: 'sk_log_abc',
            logPath: '/v1/logs',
            enabled: true,
            logCircuitBreaker: $logBreaker,
        );

        $apiRef = new \ReflectionObject($client);
        $errorDispatcher = $apiRef->getProperty('dispatcher')->getValue($client);
        $logDispatcher = $apiRef->getProperty('logDispatcher')->getValue($client);

        $this->assertNotSame(
            $errorDispatcher,
            $logDispatcher,
            'a wired log breaker must yield a SEPARATE log dispatcher (independent breaker)',
        );
    }
}
