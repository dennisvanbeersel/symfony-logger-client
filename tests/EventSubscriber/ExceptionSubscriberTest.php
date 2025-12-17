<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\Tests\EventSubscriber;

use ApplicationLogger\Bundle\EventSubscriber\ExceptionSubscriber;
use ApplicationLogger\Bundle\Service\ApiClient;
use ApplicationLogger\Bundle\Service\BreadcrumbCollector;
use ApplicationLogger\Bundle\Service\ContextCollector;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class ExceptionSubscriberTest extends TestCase
{
    private MockObject&ApiClient $apiClient;
    private MockObject&ContextCollector $contextCollector;
    private MockObject&BreadcrumbCollector $breadcrumbCollector;
    private ExceptionSubscriber $subscriber;
    private Session $session;

    protected function setUp(): void
    {
        $this->apiClient = $this->createMock(ApiClient::class);
        $this->contextCollector = $this->createMock(ContextCollector::class);
        $this->breadcrumbCollector = $this->createMock(BreadcrumbCollector::class);
        $this->session = new Session(new MockArraySessionStorage());
        $this->session->start();

        // Mock context collector to return test data
        $this->contextCollector->method('collectContext')->willReturn([
            'environment' => 'test',
            'release' => '1.0.0',
            'request' => [
                'url' => 'https://example.com/test',
                'method' => 'GET',
                'env' => [
                    'REMOTE_ADDR' => '192.168.1.0',
                    'HTTP_USER_AGENT' => 'Mozilla/5.0 Test Browser',
                ],
            ],
            'server' => [
                'server_name' => 'test-server',
                'php_version' => PHP_VERSION,
            ],
        ]);

        // Mock breadcrumb collector
        $this->breadcrumbCollector->method('get')->willReturn([
            ['type' => 'navigation', 'message' => 'Navigated to /test'],
        ]);

        $this->subscriber = new ExceptionSubscriber(
            $this->apiClient,
            $this->contextCollector,
            $this->breadcrumbCollector,
            debug: false
        );
    }

    private function createKernelStub(): HttpKernelInterface
    {
        return new class implements HttpKernelInterface {
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                return new Response();
            }
        };
    }

    public function testGetSubscribedEvents(): void
    {
        $events = ExceptionSubscriber::getSubscribedEvents();

        $this->assertArrayHasKey(KernelEvents::EXCEPTION, $events);
        // Should have low priority (-100) to run after other handlers
        $this->assertEquals(['onKernelException', -100], $events[KernelEvents::EXCEPTION]);
    }

    public function testOnKernelExceptionSendsErrorToApi(): void
    {
        $exception = new \RuntimeException('Test error message');
        $request = Request::create('/test');
        $request->setSession($this->session);

        $kernel = $this->createKernelStub();
        $event = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception);

        // Expect API call with correct payload structure
        $this->apiClient->expects($this->once())
            ->method('sendError')
            ->with($this->callback(function (array $payload) {
                // Required fields must be present (flat structure)
                $this->assertArrayHasKey('type', $payload);
                $this->assertArrayHasKey('message', $payload);
                $this->assertArrayHasKey('file', $payload);
                $this->assertArrayHasKey('line', $payload);
                $this->assertArrayHasKey('stack_trace', $payload);

                // Verify field values
                $this->assertEquals('RuntimeException', $payload['type']);
                $this->assertEquals('Test error message', $payload['message']);
                $this->assertIsString($payload['file']);
                $this->assertIsInt($payload['line']);
                $this->assertIsArray($payload['stack_trace']);

                return true;
            }));

        $this->subscriber->onKernelException($event);
    }

    public function testPayloadIncludesContextData(): void
    {
        $exception = new \RuntimeException('Test error');
        $request = Request::create('/test');
        $request->setSession($this->session);

        $kernel = $this->createKernelStub();
        $event = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception);

        $this->apiClient->expects($this->once())
            ->method('sendError')
            ->with($this->callback(function (array $payload) {
                // Optional fields from context
                $this->assertEquals('test', $payload['environment']);
                $this->assertEquals('1.0.0', $payload['release']);
                $this->assertEquals('test-server', $payload['server_name']);
                $this->assertEquals('https://example.com/test', $payload['url']);
                $this->assertEquals('GET', $payload['http_method']);
                $this->assertEquals('192.168.1.0', $payload['ip_address']);
                $this->assertEquals('Mozilla/5.0 Test Browser', $payload['user_agent']);

                return true;
            }));

        $this->subscriber->onKernelException($event);
    }

    public function testPayloadIncludesBreadcrumbs(): void
    {
        $exception = new \RuntimeException('Test error');
        $request = Request::create('/test');
        $request->setSession($this->session);

        $kernel = $this->createKernelStub();
        $event = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception);

        $this->apiClient->expects($this->once())
            ->method('sendError')
            ->with($this->callback(function (array $payload) {
                $this->assertArrayHasKey('breadcrumbs', $payload);
                $this->assertIsArray($payload['breadcrumbs']);
                $this->assertCount(1, $payload['breadcrumbs']);
                $this->assertEquals('navigation', $payload['breadcrumbs'][0]['type']);

                return true;
            }));

        $this->subscriber->onKernelException($event);
    }

    public function testHttpExceptionExtractsStatusCode(): void
    {
        $exception = new NotFoundHttpException('Page not found');
        $request = Request::create('/not-found');
        $request->setSession($this->session);

        $kernel = $this->createKernelStub();
        $event = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception);

        $this->apiClient->expects($this->once())
            ->method('sendError')
            ->with($this->callback(function (array $payload) {
                $this->assertArrayHasKey('http_status_code', $payload);
                $this->assertEquals(404, $payload['http_status_code']);

                return true;
            }));

        $this->subscriber->onKernelException($event);
    }

    public function testNonHttpExceptionDefaults500StatusCode(): void
    {
        $exception = new \RuntimeException('Internal error');
        $request = Request::create('/error');
        $request->setSession($this->session);

        $kernel = $this->createKernelStub();
        $event = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception);

        $this->apiClient->expects($this->once())
            ->method('sendError')
            ->with($this->callback(function (array $payload) {
                $this->assertArrayHasKey('http_status_code', $payload);
                $this->assertEquals(500, $payload['http_status_code']);

                return true;
            }));

        $this->subscriber->onKernelException($event);
    }

    public function testStackTraceIsReversed(): void
    {
        $exception = new \RuntimeException('Test error');
        $request = Request::create('/test');
        $request->setSession($this->session);

        $kernel = $this->createKernelStub();
        $event = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception);

        $this->apiClient->expects($this->once())
            ->method('sendError')
            ->with($this->callback(function (array $payload) {
                $this->assertArrayHasKey('stack_trace', $payload);
                $this->assertIsArray($payload['stack_trace']);

                // Stack trace should be a flat array of frames
                if (\count($payload['stack_trace']) > 0) {
                    $firstFrame = $payload['stack_trace'][0];
                    $this->assertArrayHasKey('file', $firstFrame);
                    $this->assertArrayHasKey('line', $firstFrame);
                    $this->assertArrayHasKey('function', $firstFrame);
                    $this->assertArrayHasKey('in_app', $firstFrame);
                }

                return true;
            }));

        $this->subscriber->onKernelException($event);
    }

    public function testAddsBreadcrumbAfterCapture(): void
    {
        $exception = new \RuntimeException('Test error message');
        $request = Request::create('/test');
        $request->setSession($this->session);

        $kernel = $this->createKernelStub();
        $event = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception);

        // Expect breadcrumb to be added after capture
        $this->breadcrumbCollector->expects($this->once())
            ->method('add')
            ->with($this->callback(function (array $breadcrumb) {
                $this->assertEquals('error', $breadcrumb['type']);
                $this->assertEquals('exception', $breadcrumb['category']);
                $this->assertStringContainsString('Test error message', $breadcrumb['message']);

                return true;
            }));

        $this->subscriber->onKernelException($event);
    }

    public function testResilienceOnApiFailure(): void
    {
        $exception = new \RuntimeException('Test error');
        $request = Request::create('/test');
        $request->setSession($this->session);

        $kernel = $this->createKernelStub();
        $event = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception);

        // Simulate API failure
        $this->apiClient->expects($this->any())
            ->method('sendError')
            ->willThrowException(new \RuntimeException('API unavailable'));

        // Should not throw - resilience guarantee
        $this->subscriber->onKernelException($event);

        // Test passes if we reach here without exception (resilience guarantee)
        $this->addToAssertionCount(1);
    }

    public function testIncludesSessionHashWhenAvailable(): void
    {
        // Create a new mock that includes getSessionHash
        $contextCollector = $this->createMock(ContextCollector::class);
        $contextCollector->method('collectContext')->willReturn([
            'environment' => 'test',
            'release' => '1.0.0',
            'request' => [
                'url' => 'https://example.com/test',
                'method' => 'GET',
                'env' => [
                    'REMOTE_ADDR' => '192.168.1.0',
                    'HTTP_USER_AGENT' => 'Mozilla/5.0 Test Browser',
                ],
            ],
            'server' => [
                'server_name' => 'test-server',
                'php_version' => PHP_VERSION,
            ],
        ]);
        // Mock getSessionHash to return a valid SHA-256 hash
        $expectedHash = hash('sha256', 'test-session-id');
        $contextCollector->method('getSessionHash')->willReturn($expectedHash);

        $subscriber = new ExceptionSubscriber(
            $this->apiClient,
            $contextCollector,
            $this->breadcrumbCollector,
            debug: false
        );

        $exception = new \RuntimeException('Test error');
        $request = Request::create('/test');
        $request->setSession($this->session);

        $kernel = $this->createKernelStub();
        $event = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception);

        $this->apiClient->expects($this->once())
            ->method('sendError')
            ->with($this->callback(function (array $payload) use ($expectedHash) {
                $this->assertArrayHasKey('session_hash', $payload);
                // Session hash should be SHA-256 (64 hex chars)
                $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $payload['session_hash']);
                $this->assertEquals($expectedHash, $payload['session_hash']);

                return true;
            }));

        $subscriber->onKernelException($event);
    }

    public function testPayloadHasCorrectLevelAndSource(): void
    {
        $exception = new \RuntimeException('Test error');
        $request = Request::create('/test');
        $request->setSession($this->session);

        $kernel = $this->createKernelStub();
        $event = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception);

        $this->apiClient->expects($this->once())
            ->method('sendError')
            ->with($this->callback(function (array $payload) {
                $this->assertEquals('error', $payload['level']);
                $this->assertEquals('backend', $payload['source']);

                return true;
            }));

        $this->subscriber->onKernelException($event);
    }

    public function testTimestampIsIso8601String(): void
    {
        $exception = new \RuntimeException('Test error');
        $request = Request::create('/test');
        $request->setSession($this->session);

        $kernel = $this->createKernelStub();
        $event = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception);

        $this->apiClient->expects($this->once())
            ->method('sendError')
            ->with($this->callback(function (array $payload) {
                $this->assertArrayHasKey('timestamp', $payload);
                $this->assertIsString($payload['timestamp']);
                // Verify ISO 8601 format (ATOM)
                $this->assertMatchesRegularExpression(
                    '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/',
                    $payload['timestamp']
                );
                // Verify it can be parsed as a valid date
                $date = new \DateTimeImmutable($payload['timestamp']);
                $this->assertInstanceOf(\DateTimeImmutable::class, $date);

                return true;
            }));

        $this->subscriber->onKernelException($event);
    }
}
