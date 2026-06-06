<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\Tests\Service;

use ApplicationLogger\Bundle\Service\ContextCollector;
use ApplicationLogger\Bundle\Service\DataScrubber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class ContextCollectorTest extends TestCase
{
    private RequestStack $requestStack;

    protected function setUp(): void
    {
        $this->requestStack = new RequestStack();
    }

    /**
     * @param array<string> $scrubFields
     */
    private function createCollector(
        array $scrubFields = ['password', 'token', 'api_key', 'secret', 'authorization'],
        ?string $release = '1.0.0',
        string $environment = 'test'
    ): ContextCollector {
        return new ContextCollector(
            new DataScrubber($scrubFields),
            $release,
            $environment,
            $this->requestStack
        );
    }

    public function testCollectContextReturnsExpectedStructure(): void
    {
        $request = Request::create('/test', 'GET');
        $this->requestStack->push($request);

        $collector = $this->createCollector();
        $context = $collector->collectContext();

        $this->assertArrayHasKey('request', $context);
        $this->assertArrayHasKey('user', $context);
        $this->assertArrayHasKey('server', $context);
        $this->assertArrayHasKey('environment', $context);
        $this->assertArrayHasKey('release', $context);
    }

    public function testCollectRequestIncludesCorrectFields(): void
    {
        $request = Request::create(
            'https://example.com/test?foo=bar',
            'POST',
            ['data' => 'value'],
            ['cookie' => 'value'],
            [],
            ['HTTP_USER_AGENT' => 'Test Browser', 'REMOTE_ADDR' => '192.168.1.100']
        );
        $this->requestStack->push($request);

        $collector = $this->createCollector();
        $requestContext = $collector->collectRequest();

        $this->assertNotNull($requestContext);
        $this->assertArrayHasKey('url', $requestContext);
        $this->assertArrayHasKey('method', $requestContext);
        $this->assertArrayHasKey('query_string', $requestContext);
        $this->assertArrayHasKey('headers', $requestContext);
        $this->assertArrayHasKey('data', $requestContext);
        $this->assertArrayHasKey('env', $requestContext);

        $this->assertEquals('POST', $requestContext['method']);
        $this->assertEquals('foo=bar', $requestContext['query_string']);
    }

    public function testCollectRequestScrubsSensitiveQueryStringValues(): void
    {
        $request = Request::create('https://example.com/reset?password=hunter2&page=2', 'GET');
        $this->requestStack->push($request);

        $collector = $this->createCollector();
        $requestContext = $collector->collectRequest();

        $this->assertNotNull($requestContext);

        // url must keep host/path but redact the sensitive query value.
        // Note: Symfony's getUri()/getQueryString() normalise (sort) query pairs,
        // so 'page' precedes 'password' here; the scrubber preserves that order.
        $this->assertSame(
            'https://example.com/reset?page=2&password=[REDACTED]',
            $requestContext['url']
        );

        // query_string must be redacted independently.
        $this->assertSame('page=2&password=[REDACTED]', $requestContext['query_string']);

        // The raw secret must not appear anywhere in the request payload.
        $this->assertStringNotContainsString('hunter2', json_encode($requestContext) ?: '');
    }

    public function testCollectRequestReturnsNullWithoutRequest(): void
    {
        $collector = $this->createCollector();
        $requestContext = $collector->collectRequest();

        $this->assertNull($requestContext);
    }

    public function testScrubsSensitiveDataFromHeaders(): void
    {
        $request = Request::create('/test');
        $request->headers->set('Authorization', 'Bearer secret-token');
        $request->headers->set('X-Api-Key', 'my-api-key');
        $request->headers->set('Content-Type', 'application/json');
        $this->requestStack->push($request);

        // Add 'api-key' (with hyphen) to scrub fields since HTTP headers use hyphens
        $collector = $this->createCollector(
            scrubFields: ['password', 'token', 'api_key', 'api-key', 'secret', 'authorization']
        );
        $requestContext = $collector->collectRequest();

        $this->assertNotNull($requestContext);
        $this->assertEquals('[REDACTED]', $requestContext['headers']['authorization']);
        $this->assertEquals('[REDACTED]', $requestContext['headers']['x-api-key']);
        $this->assertNotEquals('[REDACTED]', $requestContext['headers']['content-type']);
    }

    public function testScrubsSensitiveDataFromPostData(): void
    {
        $request = Request::create('/test', 'POST', [
            'username' => 'john',
            'password' => 'secret123',
            'api_key' => 'my-key',
            'user_token' => 'token123',
        ]);
        $this->requestStack->push($request);

        $collector = $this->createCollector();
        $requestContext = $collector->collectRequest();

        $this->assertNotNull($requestContext);
        $this->assertEquals('john', $requestContext['data']['username']);
        $this->assertEquals('[REDACTED]', $requestContext['data']['password']);
        $this->assertEquals('[REDACTED]', $requestContext['data']['api_key']);
        $this->assertEquals('[REDACTED]', $requestContext['data']['user_token']);
    }

    public function testAnonymizesIpV4Address(): void
    {
        $request = Request::create('/test');
        $request->server->set('REMOTE_ADDR', '192.168.1.100');
        $this->requestStack->push($request);

        $collector = $this->createCollector();
        $requestContext = $collector->collectRequest();

        $this->assertNotNull($requestContext);
        // Last octet should be masked
        $this->assertEquals('192.168.1.0', $requestContext['env']['REMOTE_ADDR']);
    }

    public function testAnonymizesIpV6Address(): void
    {
        $request = Request::create('/test');
        $request->server->set('REMOTE_ADDR', '2001:0db8:85a3:0000:0000:8a2e:0370:7334');
        $this->requestStack->push($request);

        $collector = $this->createCollector();
        $requestContext = $collector->collectRequest();

        $this->assertNotNull($requestContext);
        // IPv6 should be anonymized (last 80 bits masked)
        $this->assertNotEquals('2001:0db8:85a3:0000:0000:8a2e:0370:7334', $requestContext['env']['REMOTE_ADDR']);
    }

    public function testCollectServerReturnsExpectedInfo(): void
    {
        $collector = $this->createCollector();
        $serverContext = $collector->collectServer();

        $this->assertArrayHasKey('php_version', $serverContext);
        $this->assertArrayHasKey('php_sapi', $serverContext);
        $this->assertArrayHasKey('symfony_version', $serverContext);
        $this->assertArrayHasKey('server_name', $serverContext);
        $this->assertArrayHasKey('os', $serverContext);

        $this->assertEquals(PHP_VERSION, $serverContext['php_version']);
        $this->assertEquals(PHP_SAPI, $serverContext['php_sapi']);
        $this->assertEquals(PHP_OS, $serverContext['os']);
    }

    public function testCollectUserWithSession(): void
    {
        $request = Request::create('/test');
        $session = new Session(new MockArraySessionStorage());
        $session->start();
        $request->setSession($session);
        $request->server->set('REMOTE_ADDR', '10.0.0.50');
        $this->requestStack->push($request);

        $collector = $this->createCollector();
        $userContext = $collector->collectUser();

        $this->assertNotNull($userContext);
        $this->assertArrayHasKey('session_id', $userContext);
        $this->assertArrayHasKey('ip_address', $userContext);
        // Note: 'id' field was removed as it was redundant (same value as session_id)

        // IP should be anonymized
        $this->assertEquals('10.0.0.0', $userContext['ip_address']);
    }

    public function testCollectUserDoesNotLeakRawSessionId(): void
    {
        $request = Request::create('/test');
        $session = new Session(new MockArraySessionStorage());
        $session->start();
        $request->setSession($session);
        $this->requestStack->push($request);

        $rawSessionId = $session->getId();
        $this->assertNotSame('', $rawSessionId, 'Precondition: session must have an id');

        $collector = $this->createCollector();
        $userContext = $collector->collectUser();

        $this->assertNotNull($userContext);
        // The raw, hijackable PHP session id must never be emitted verbatim.
        $this->assertNotEquals($rawSessionId, $userContext['session_id']);
        // It must be a SHA-256 hash of the raw id (64 hex chars).
        $this->assertSame(hash('sha256', $rawSessionId), $userContext['session_id']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $userContext['session_id']);
    }

    public function testCollectRequestRedactsCookieHeader(): void
    {
        $request = Request::create('/test');
        $request->headers->set('Cookie', 'PHPSESSID=abc123; csrf_token=topsecret; remember_me=longlivedsecret');
        $request->headers->set('Set-Cookie', 'PHPSESSID=newvalue; HttpOnly');
        $request->headers->set('Content-Type', 'application/json');
        $this->requestStack->push($request);

        $collector = $this->createCollector();
        $requestContext = $collector->collectRequest();

        $this->assertNotNull($requestContext);
        $this->assertEquals('[REDACTED]', $requestContext['headers']['cookie']);
        $this->assertEquals('[REDACTED]', $requestContext['headers']['set-cookie']);
        // Raw cookie material must not appear anywhere in the headers payload.
        $this->assertStringNotContainsString('PHPSESSID', json_encode($requestContext['headers']) ?: '');
        $this->assertStringNotContainsString('topsecret', json_encode($requestContext['headers']) ?: '');
        // Non-sensitive headers remain intact.
        $this->assertNotEquals('[REDACTED]', $requestContext['headers']['content-type']);
    }

    public function testCollectUserReturnsNullWithoutSession(): void
    {
        $request = Request::create('/test');
        $this->requestStack->push($request);

        $collector = $this->createCollector();
        $userContext = $collector->collectUser();

        $this->assertNull($userContext);
    }

    public function testEnvironmentAndReleaseAreIncluded(): void
    {
        $collector = $this->createCollector(
            release: '2.0.0',
            environment: 'production'
        );
        $context = $collector->collectContext();

        $this->assertEquals('production', $context['environment']);
        $this->assertEquals('2.0.0', $context['release']);
    }

    public function testCustomScrubFields(): void
    {
        $request = Request::create('/test', 'POST', [
            'credit_card' => '4111111111111111',
            'ssn' => '123-45-6789',
            'name' => 'John Doe',
        ]);
        $this->requestStack->push($request);

        $collector = $this->createCollector(
            scrubFields: ['credit_card', 'ssn']
        );
        $requestContext = $collector->collectRequest();

        $this->assertNotNull($requestContext);
        $this->assertEquals('[REDACTED]', $requestContext['data']['credit_card']);
        $this->assertEquals('[REDACTED]', $requestContext['data']['ssn']);
        $this->assertEquals('John Doe', $requestContext['data']['name']);
    }

    public function testScrubbingIsRecursive(): void
    {
        $request = Request::create('/test', 'POST', [
            'user' => [
                'name' => 'John',
                'password' => 'secret',
                'profile' => [
                    'api_token' => 'token123',
                ],
            ],
        ]);
        $this->requestStack->push($request);

        $collector = $this->createCollector();
        $requestContext = $collector->collectRequest();

        $this->assertNotNull($requestContext);
        $this->assertEquals('John', $requestContext['data']['user']['name']);
        $this->assertEquals('[REDACTED]', $requestContext['data']['user']['password']);
        $this->assertEquals('[REDACTED]', $requestContext['data']['user']['profile']['api_token']);
    }

    public function testResilienceOnErrors(): void
    {
        // Create collector without request (might cause issues in some edge cases)
        $collector = $this->createCollector();

        // Should not throw, even if something goes wrong internally
        $context = $collector->collectContext();

        // Verify context has expected keys (collectContext always returns array)
        $this->assertArrayHasKey('environment', $context);
    }
}
