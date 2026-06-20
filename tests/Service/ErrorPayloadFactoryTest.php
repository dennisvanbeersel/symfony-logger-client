<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\Tests\Service;

use ApplicationLogger\Bundle\Service\BreadcrumbCollector;
use ApplicationLogger\Bundle\Service\ContextCollectorInterface;
use ApplicationLogger\Bundle\Service\ErrorPayloadFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ErrorPayloadFactoryTest extends TestCase
{
    private MockObject&ContextCollectorInterface $contextCollector;
    private MockObject&BreadcrumbCollector $breadcrumbCollector;
    private ErrorPayloadFactory $factory;

    protected function setUp(): void
    {
        // Mock the interface seam (the concrete ContextCollector is now final).
        $this->contextCollector = $this->createMock(ContextCollectorInterface::class);
        $this->breadcrumbCollector = $this->createMock(BreadcrumbCollector::class);

        $this->breadcrumbCollector->method('get')->willReturn([
            ['type' => 'navigation', 'message' => 'x'],
        ]);
        $this->contextCollector->method('getSessionHash')->willReturn(hash('sha256', 'sid'));

        $this->factory = new ErrorPayloadFactory($this->contextCollector, $this->breadcrumbCollector);
    }

    /**
     * @return array<string, mixed>
     */
    private function sampleContext(): array
    {
        return [
            'environment' => 'test',
            'release' => '1.0.0',
            'request' => [
                'url' => 'https://example.com/test',
                'method' => 'GET',
                'env' => [
                    'REMOTE_ADDR' => '192.168.1.0',
                    'HTTP_USER_AGENT' => 'Test Browser',
                ],
            ],
            'server' => [
                'server_name' => 'test-server',
            ],
        ];
    }

    public function testFromThrowableBuildsCommonFields(): void
    {
        $exception = new \RuntimeException('boom', 7);
        $payload = $this->factory->fromThrowable($exception, $this->sampleContext());

        $this->assertSame('RuntimeException', $payload['type']);
        $this->assertSame('boom', $payload['message']);
        $this->assertSame($exception->getFile(), $payload['file']);
        $this->assertSame($exception->getLine(), $payload['line']);
        $this->assertIsArray($payload['stack_trace']);

        $this->assertSame('error', $payload['level']);
        $this->assertSame('backend', $payload['source']);
        $this->assertSame('test', $payload['environment']);
        $this->assertSame('1.0.0', $payload['release']);
        $this->assertSame(hash('sha256', 'sid'), $payload['session_hash']);
        $this->assertArrayHasKey('timestamp', $payload);
        $this->assertSame('test-server', $payload['server_name']);
        $this->assertSame('https://example.com/test', $payload['url']);
        $this->assertSame('GET', $payload['http_method']);
        $this->assertSame('192.168.1.0', $payload['ip_address']);
        $this->assertSame('Test Browser', $payload['user_agent']);
        $this->assertSame('PHP '.\PHP_VERSION, $payload['runtime']);
        $this->assertSame(['server_name' => 'test-server'], $payload['context']);
        $this->assertSame([], $payload['tags']);
        $this->assertCount(1, $payload['breadcrumbs']);
    }

    public function testSessionHashIsReusedFromContextWithoutExtraLookup(): void
    {
        // BUNDLE-6: when the context already carries a precomputed session_hash (as
        // ContextCollector::collectContext() now provides), the factory must NOT call
        // getSessionHash() again (avoids a second RequestStack/session lookup per error).
        $contextCollector = $this->createMock(ContextCollectorInterface::class);
        $contextCollector->expects($this->never())->method('getSessionHash');
        $breadcrumbs = $this->createMock(BreadcrumbCollector::class);
        $breadcrumbs->method('get')->willReturn([]);

        $factory = new ErrorPayloadFactory($contextCollector, $breadcrumbs);

        $context = $this->sampleContext();
        $context['session_hash'] = 'precomputed-hash';

        $payload = $factory->fromThrowable(new \RuntimeException('x'), $context);

        $this->assertSame('precomputed-hash', $payload['session_hash']);
    }

    public function testSessionHashFallsBackToLookupWhenContextLacksKey(): void
    {
        // BC: a caller passing a context WITHOUT session_hash still gets a value via the
        // direct getSessionHash() lookup (exercised by the existing sampleContext()).
        $payload = $this->factory->fromThrowable(new \RuntimeException('x'), $this->sampleContext());

        $this->assertSame(hash('sha256', 'sid'), $payload['session_hash']);
    }

    public function testNullSessionHashInContextIsPreservedNotReLookedUp(): void
    {
        // A context with an explicit null session_hash (no session) must be honoured as
        // null, NOT trigger a fallback lookup.
        $contextCollector = $this->createMock(ContextCollectorInterface::class);
        $contextCollector->expects($this->never())->method('getSessionHash');
        $breadcrumbs = $this->createMock(BreadcrumbCollector::class);
        $breadcrumbs->method('get')->willReturn([]);

        $factory = new ErrorPayloadFactory($contextCollector, $breadcrumbs);

        $context = $this->sampleContext();
        $context['session_hash'] = null;

        $payload = $factory->fromThrowable(new \RuntimeException('x'), $context);

        $this->assertNull($payload['session_hash']);
    }

    public function testOverridesAreMergedOnTop(): void
    {
        $exception = new \RuntimeException('boom');
        $payload = $this->factory->fromThrowable($exception, $this->sampleContext(), [
            'http_status_code' => 404,
            'level' => 'fatal',
            'tags' => ['channel' => 'app'],
        ]);

        $this->assertSame(404, $payload['http_status_code']);
        $this->assertSame('fatal', $payload['level']);
        $this->assertSame(['channel' => 'app'], $payload['tags']);
        // Untouched base fields remain.
        $this->assertSame('RuntimeException', $payload['type']);
    }

    public function testFromThrowableUsesContextDefaultsWhenMissing(): void
    {
        $payload = $this->factory->fromThrowable(new \RuntimeException('x'), []);

        $this->assertSame('production', $payload['environment']);
        $this->assertNull($payload['release']);
        $this->assertNull($payload['server_name']);
        $this->assertNull($payload['url']);
        $this->assertNull($payload['http_method']);
        $this->assertNull($payload['ip_address']);
        $this->assertNull($payload['user_agent']);
        $this->assertNull($payload['request_data']);
        $this->assertSame([], $payload['context']);
    }

    public function testMinimalFallbackHasRequiredFields(): void
    {
        $exception = new \RuntimeException('boom');
        $payload = $this->factory->minimalFallback($exception, ['http_status_code' => 500]);

        $this->assertSame('RuntimeException', $payload['type']);
        $this->assertSame('boom', $payload['message']);
        $this->assertSame($exception->getFile(), $payload['file']);
        $this->assertSame($exception->getLine(), $payload['line']);
        $this->assertSame([], $payload['stack_trace']);
        $this->assertSame('error', $payload['level']);
        $this->assertSame('backend', $payload['source']);
        $this->assertArrayHasKey('timestamp', $payload);
        $this->assertSame(500, $payload['http_status_code']);
    }

    public function testTruncateValueAppliesLengthLimit(): void
    {
        $long = str_repeat('a', 2000);
        $this->assertSame(1000, mb_strlen($this->factory->truncateValue($long, 1000)));
    }

    public function testLongTypeAndMessageAreTruncated(): void
    {
        $exception = new \RuntimeException(str_repeat('b', 2000));
        $payload = $this->factory->fromThrowable($exception, $this->sampleContext());

        $this->assertSame(1000, mb_strlen($payload['message']));
    }
}
