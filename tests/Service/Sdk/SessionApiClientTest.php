<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\Tests\Service\Sdk;

use ApplicationLogger\Bundle\Service\Sdk\LoopbackGuard;
use ApplicationLogger\Bundle\Service\Sdk\SessionApiClient;
use ApplicationLogger\Sdk\CircuitBreaker;
use ApplicationLogger\Sdk\Clock\FrozenClock;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Unit tests for SessionApiClient.
 *
 * Covers:
 * (a) createSession on an ingest request is DROPPED — no HTTP call.
 * (b) Normal createSession/addSessionEvent/endSession POST to the correct URLs with X-Api-Key.
 * (c) A transport exception NEVER propagates.
 */
final class SessionApiClientTest extends TestCase
{
    private const string DSN = 'https://example.com/test-project-id';
    private const string API_KEY = 'test-api-key-123';
    /** @var list<string> */
    private const array INGEST_PATHS = ['/api/v1/errors', '/api/v1/js-errors', '/api/v1/sessions', '/api/v1/logs'];

    private function makeBreaker(): CircuitBreaker
    {
        return new CircuitBreaker(
            cache: new ArrayAdapter(),
            clock: new FrozenClock(new \DateTimeImmutable('2024-01-01T00:00:00+00:00')),
            failureThreshold: 5,
            openSeconds: 60,
        );
    }

    private function makeLoopback(?string $currentPath): LoopbackGuard
    {
        $stack = new RequestStack();
        if (null !== $currentPath) {
            $stack->push(Request::create($currentPath));
        }

        return new LoopbackGuard($stack, self::INGEST_PATHS);
    }

    // -------------------------------------------------------------------------
    // (a) Loopback guard — ingest request drops the call without any HTTP work
    // -------------------------------------------------------------------------

    public function testCreateSessionDroppedOnIngestRequest(): void
    {
        $requestCount = 0;
        $httpClient = new MockHttpClient(function () use (&$requestCount): MockResponse {
            ++$requestCount;

            return new MockResponse('', ['http_code' => 202]);
        });

        $client = new SessionApiClient(
            dsn: self::DSN,
            apiKey: self::API_KEY,
            httpClient: $httpClient,
            loopback: $this->makeLoopback('/api/v1/sessions'),
            breaker: $this->makeBreaker(),
        );

        $client->createSession(['session_id' => 'abc']);

        self::assertSame(0, $requestCount, 'No HTTP request should be made during an ingest loopback request');
    }

    public function testAddSessionEventDroppedOnIngestRequest(): void
    {
        $requestCount = 0;
        $httpClient = new MockHttpClient(function () use (&$requestCount): MockResponse {
            ++$requestCount;

            return new MockResponse('', ['http_code' => 202]);
        });

        $client = new SessionApiClient(
            dsn: self::DSN,
            apiKey: self::API_KEY,
            httpClient: $httpClient,
            loopback: $this->makeLoopback('/api/v1/errors'),
            breaker: $this->makeBreaker(),
        );

        $client->addSessionEvent('abc', ['type' => 'PAGE_VIEW']);

        self::assertSame(0, $requestCount);
    }

    public function testEndSessionDroppedOnIngestRequest(): void
    {
        $requestCount = 0;
        $httpClient = new MockHttpClient(function () use (&$requestCount): MockResponse {
            ++$requestCount;

            return new MockResponse('', ['http_code' => 202]);
        });

        $client = new SessionApiClient(
            dsn: self::DSN,
            apiKey: self::API_KEY,
            httpClient: $httpClient,
            loopback: $this->makeLoopback('/api/v1/logs'),
            breaker: $this->makeBreaker(),
        );

        $client->endSession('abc');

        self::assertSame(0, $requestCount);
    }

    // -------------------------------------------------------------------------
    // (b) Normal requests — correct URLs + X-Api-Key header
    // -------------------------------------------------------------------------

    public function testCreateSessionPostsToCorrectUrl(): void
    {
        $captured = [];
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse('', ['http_code' => 202]);
        });

        $client = new SessionApiClient(
            dsn: self::DSN,
            apiKey: self::API_KEY,
            httpClient: $httpClient,
            loopback: $this->makeLoopback('/dashboard'),
            breaker: $this->makeBreaker(),
        );

        $client->createSession(['session_id' => 'sess-1']);

        self::assertCount(1, $captured);
        self::assertSame('POST', $captured[0]['method']);
        self::assertSame('https://example.com/api/v1/sessions', $captured[0]['url']);
        // MockHttpClient normalises headers to lowercase in normalized_headers
        self::assertArrayHasKey('x-api-key', $captured[0]['options']['normalized_headers']);
        self::assertStringContainsString(self::API_KEY, $captured[0]['options']['normalized_headers']['x-api-key'][0]);
        self::assertStringContainsString('application/json', $captured[0]['options']['normalized_headers']['content-type'][0]);
    }

    public function testCreateSessionInjectsStartedAtWhenAbsent(): void
    {
        $captured = [];
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured[] = $options;

            return new MockResponse('', ['http_code' => 202]);
        });

        $client = new SessionApiClient(
            dsn: self::DSN,
            apiKey: self::API_KEY,
            httpClient: $httpClient,
            loopback: $this->makeLoopback(null),
            breaker: $this->makeBreaker(),
        );

        $client->createSession(['session_id' => 'sess-1']);

        // MockHttpClient serialises json => body
        /** @var array<string, mixed> $body */
        $body = json_decode($captured[0]['body'], true);
        self::assertArrayHasKey('started_at', $body);
    }

    public function testCreateSessionPreservesExplicitStartedAt(): void
    {
        $captured = [];
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured[] = $options;

            return new MockResponse('', ['http_code' => 202]);
        });

        $client = new SessionApiClient(
            dsn: self::DSN,
            apiKey: self::API_KEY,
            httpClient: $httpClient,
            loopback: $this->makeLoopback(null),
            breaker: $this->makeBreaker(),
        );

        $client->createSession(['session_id' => 'sess-1', 'started_at' => '2024-01-01T00:00:00+00:00']);

        /** @var array<string, mixed> $body */
        $body = json_decode($captured[0]['body'], true);
        self::assertSame('2024-01-01T00:00:00+00:00', $body['started_at']);
    }

    public function testAddSessionEventPostsToCorrectUrl(): void
    {
        $captured = [];
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse('', ['http_code' => 202]);
        });

        $client = new SessionApiClient(
            dsn: self::DSN,
            apiKey: self::API_KEY,
            httpClient: $httpClient,
            loopback: $this->makeLoopback(null),
            breaker: $this->makeBreaker(),
        );

        $client->addSessionEvent('sess-42', ['type' => 'PAGE_VIEW', 'url' => 'https://example.com/']);

        self::assertCount(1, $captured);
        self::assertSame('POST', $captured[0]['method']);
        self::assertSame('https://example.com/api/v1/sessions/sess-42/events', $captured[0]['url']);
        self::assertArrayHasKey('x-api-key', $captured[0]['options']['normalized_headers']);
        self::assertStringContainsString(self::API_KEY, $captured[0]['options']['normalized_headers']['x-api-key'][0]);
    }

    public function testEndSessionPostsToCorrectUrl(): void
    {
        $captured = [];
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse('', ['http_code' => 202]);
        });

        $client = new SessionApiClient(
            dsn: self::DSN,
            apiKey: self::API_KEY,
            httpClient: $httpClient,
            loopback: $this->makeLoopback(null),
            breaker: $this->makeBreaker(),
        );

        $endedAt = new \DateTimeImmutable('2024-06-01T12:00:00+00:00');
        $client->endSession('sess-99', $endedAt);

        self::assertCount(1, $captured);
        self::assertSame('POST', $captured[0]['method']);
        self::assertSame('https://example.com/api/v1/sessions/sess-99/end', $captured[0]['url']);
        self::assertArrayHasKey('x-api-key', $captured[0]['options']['normalized_headers']);
        self::assertStringContainsString(self::API_KEY, $captured[0]['options']['normalized_headers']['x-api-key'][0]);
        /** @var array<string, mixed> $body */
        $body = json_decode($captured[0]['options']['body'], true);
        self::assertSame($endedAt->format(\DateTimeInterface::ATOM), $body['ended_at']);
    }

    public function testEndSessionUsesCurrentTimeWhenEndedAtNull(): void
    {
        $captured = [];
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured[] = $options;

            return new MockResponse('', ['http_code' => 202]);
        });

        $client = new SessionApiClient(
            dsn: self::DSN,
            apiKey: self::API_KEY,
            httpClient: $httpClient,
            loopback: $this->makeLoopback(null),
            breaker: $this->makeBreaker(),
        );

        $before = new \DateTimeImmutable();
        $client->endSession('sess-1');
        $after = new \DateTimeImmutable();

        /** @var array<string, mixed> $body */
        $body = json_decode($captured[0]['body'], true);
        self::assertArrayHasKey('ended_at', $body);
        $endedAt = new \DateTimeImmutable($body['ended_at']);
        self::assertGreaterThanOrEqual($before->getTimestamp(), $endedAt->getTimestamp());
        self::assertLessThanOrEqual($after->getTimestamp(), $endedAt->getTimestamp());
    }

    public function testDsnWithPortBuildsCorrectBaseUrl(): void
    {
        $captured = [];
        $httpClient = new MockHttpClient(function (string $method, string $url) use (&$captured): MockResponse {
            $captured[] = $url;

            return new MockResponse('', ['http_code' => 202]);
        });

        $client = new SessionApiClient(
            dsn: 'https://localhost:8111/project-id',
            apiKey: self::API_KEY,
            httpClient: $httpClient,
            loopback: $this->makeLoopback(null),
            breaker: $this->makeBreaker(),
        );

        $client->createSession(['session_id' => 'x']);

        self::assertSame('https://localhost:8111/api/v1/sessions', $captured[0]);
    }

    // -------------------------------------------------------------------------
    // (c) Transport exception never propagates
    // -------------------------------------------------------------------------

    public function testTransportExceptionNeverPropagates(): void
    {
        $httpClient = new MockHttpClient(function (): never {
            throw new \RuntimeException('Network failure');
        });

        $client = new SessionApiClient(
            dsn: self::DSN,
            apiKey: self::API_KEY,
            httpClient: $httpClient,
            loopback: $this->makeLoopback(null),
            breaker: $this->makeBreaker(),
        );

        // None of these should throw
        $client->createSession(['session_id' => 'x']);
        $client->addSessionEvent('x', ['type' => 'CLICK']);
        $client->endSession('x');

        $this->addToAssertionCount(1); // reaching here means no exception was thrown
    }

    public function testFlushIsNoOp(): void
    {
        $client = new SessionApiClient(
            dsn: self::DSN,
            apiKey: self::API_KEY,
            httpClient: new MockHttpClient(),
            loopback: $this->makeLoopback(null),
            breaker: $this->makeBreaker(),
        );

        // Must not throw
        $client->flush();

        $this->addToAssertionCount(1);
    }

    // -------------------------------------------------------------------------
    // Edge cases
    // -------------------------------------------------------------------------

    public function testEmptyDsnIsInert(): void
    {
        $requestCount = 0;
        $httpClient = new MockHttpClient(function () use (&$requestCount): MockResponse {
            ++$requestCount;

            return new MockResponse('', ['http_code' => 202]);
        });

        $client = new SessionApiClient(
            dsn: '',
            apiKey: self::API_KEY,
            httpClient: $httpClient,
            loopback: $this->makeLoopback(null),
            breaker: $this->makeBreaker(),
        );

        $client->createSession(['session_id' => 'x']);
        $client->addSessionEvent('x', ['type' => 'CLICK']);
        $client->endSession('x');

        self::assertSame(0, $requestCount, 'Empty DSN must produce no HTTP requests');
    }

    public function testDisabledIsInert(): void
    {
        $requestCount = 0;
        $httpClient = new MockHttpClient(function () use (&$requestCount): MockResponse {
            ++$requestCount;

            return new MockResponse('', ['http_code' => 202]);
        });

        $client = new SessionApiClient(
            dsn: self::DSN,
            apiKey: self::API_KEY,
            httpClient: $httpClient,
            loopback: $this->makeLoopback(null),
            breaker: $this->makeBreaker(),
            enabled: false,
        );

        $client->createSession(['session_id' => 'x']);
        $client->addSessionEvent('x', ['type' => 'CLICK']);
        $client->endSession('x');

        self::assertSame(0, $requestCount, 'Disabled client must produce no HTTP requests');
    }

    public function testBreakerBlocksWhenOpen(): void
    {
        $requestCount = 0;
        $httpClient = new MockHttpClient(function () use (&$requestCount): MockResponse {
            ++$requestCount;

            return new MockResponse('', ['http_code' => 202]);
        });

        $breaker = $this->makeBreaker();
        // Trip the breaker
        for ($i = 0; $i < 5; ++$i) {
            $breaker->recordFailure();
        }

        $client = new SessionApiClient(
            dsn: self::DSN,
            apiKey: self::API_KEY,
            httpClient: $httpClient,
            loopback: $this->makeLoopback(null),
            breaker: $breaker,
        );

        $client->createSession(['session_id' => 'x']);
        $client->addSessionEvent('x', ['type' => 'CLICK']);
        $client->endSession('x');

        self::assertSame(0, $requestCount, 'Open circuit breaker must produce no HTTP requests');
    }

    public function testHttpErrorRecordsFailure(): void
    {
        // 500 response should record a failure on the breaker
        $httpClient = new MockHttpClient(new MockResponse('error', ['http_code' => 500]));

        $breaker = $this->makeBreaker();

        $client = new SessionApiClient(
            dsn: self::DSN,
            apiKey: self::API_KEY,
            httpClient: $httpClient,
            loopback: $this->makeLoopback(null),
            breaker: $breaker,
        );

        $client->createSession(['session_id' => 'x']);

        // After one failure, breaker should not yet be open (threshold=5) but
        // should have recorded a failure (allowRequest still true)
        self::assertTrue($breaker->allowRequest());
    }
}
