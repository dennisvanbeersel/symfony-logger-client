<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\Tests\EventSubscriber;

use ApplicationLogger\Bundle\EventSubscriber\FlushTelemetrySubscriber;
use ApplicationLogger\Bundle\Monolog\Handler\ApplicationLoggerHandler;
use ApplicationLogger\Bundle\Service\ApiClient;
use ApplicationLogger\Bundle\Service\BreadcrumbCollector;
use ApplicationLogger\Bundle\Service\CircuitBreaker;
use ApplicationLogger\Bundle\Service\ContextCollector;
use ApplicationLogger\Bundle\Service\DataScrubber;
use ApplicationLogger\Bundle\Service\ErrorPayloadFactory;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Verifies that FlushTelemetrySubscriber:
 *   - subscribes to kernel.terminate at priority -1024,
 *   - calls ApiClient::flush() exactly once per terminate event,
 *   - swallows any exception thrown by flush() (resilience guarantee).
 */
final class FlushTelemetrySubscriberTest extends TestCase
{
    private function makeTerminateEvent(): TerminateEvent
    {
        $kernel = new class implements HttpKernelInterface {
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                return new Response();
            }
        };

        return new TerminateEvent($kernel, Request::create('/'), new Response());
    }

    // -------------------------------------------------------------------------
    // Subscription contract
    // -------------------------------------------------------------------------

    public function testSubscribesToKernelTerminate(): void
    {
        $events = FlushTelemetrySubscriber::getSubscribedEvents();

        $this->assertArrayHasKey(KernelEvents::TERMINATE, $events);
    }

    public function testRunsAtLowPriority(): void
    {
        $events = FlushTelemetrySubscriber::getSubscribedEvents();

        $config = $events[KernelEvents::TERMINATE];
        // May be ['onKernelTerminate', -1024] or just a callable — handle both.
        $priority = \is_array($config) ? ($config[1] ?? 0) : 0;

        $this->assertLessThanOrEqual(
            -512,
            $priority,
            'FlushTelemetrySubscriber must run at a very low (negative) priority so it fires LAST',
        );
    }

    // -------------------------------------------------------------------------
    // Core behaviour: flush() is called
    // -------------------------------------------------------------------------

    public function testOnTerminateCallsApiClientFlush(): void
    {
        /** @var MockObject&ApiClient $apiClient */
        $apiClient = $this->createMock(ApiClient::class);
        $apiClient->expects($this->once())->method('flush');

        $subscriber = new FlushTelemetrySubscriber($apiClient);
        $subscriber->onKernelTerminate($this->makeTerminateEvent());
    }

    public function testFlushIsCalledExactlyOncePerEvent(): void
    {
        /** @var MockObject&ApiClient $apiClient */
        $apiClient = $this->createMock(ApiClient::class);
        $apiClient->expects($this->exactly(3))->method('flush');

        $subscriber = new FlushTelemetrySubscriber($apiClient);

        $subscriber->onKernelTerminate($this->makeTerminateEvent());
        $subscriber->onKernelTerminate($this->makeTerminateEvent());
        $subscriber->onKernelTerminate($this->makeTerminateEvent());
    }

    // -------------------------------------------------------------------------
    // Resilience: exceptions must never propagate
    // -------------------------------------------------------------------------

    public function testFlushExceptionIsSwallowed(): void
    {
        /** @var MockObject&ApiClient $apiClient */
        $apiClient = $this->createMock(ApiClient::class);
        $apiClient->method('flush')->willThrowException(new \RuntimeException('transport exploded'));

        $subscriber = new FlushTelemetrySubscriber($apiClient);

        // Must not throw — resilience guarantee.
        $subscriber->onKernelTerminate($this->makeTerminateEvent());

        $this->addToAssertionCount(1);
    }

    // -------------------------------------------------------------------------
    // Integration smoke-test: real dispatcher is drained, not mocked
    // -------------------------------------------------------------------------

    // -------------------------------------------------------------------------
    // ASYNC-3: the log buffer is flushed at terminate, BEFORE draining (ordering)
    // -------------------------------------------------------------------------

    /**
     * Build a real ApplicationLoggerHandler (final, so it cannot be mocked) wired to a
     * mock ApiClient, with one non-exception record already buffered. Returns the handler.
     */
    private function handlerWithOneBufferedLog(ApiClient $apiClient): ApplicationLoggerHandler
    {
        $scrubber = new DataScrubber([]);
        $contextCollector = new ContextCollector($scrubber, null, 'test', new RequestStack());
        $payloadFactory = new ErrorPayloadFactory($contextCollector, new BreadcrumbCollector(50));

        $handler = new ApplicationLoggerHandler(
            apiClient: $apiClient,
            contextCollector: $contextCollector,
            scrubber: $scrubber,
            payloadFactory: $payloadFactory,
            enabled: true,
            captureLevel: 'info',
            environment: 'test',
            batchSize: 50,   // > 1 so handle() buffers without an in-line flush
            maxBuffer: 1000,
        );

        // A non-exception record routes to the log-aggregation buffer (not the error path).
        $handler->handle(new LogRecord(new \DateTimeImmutable(), 'app', Level::Info, 'buffered line', [], []));

        return $handler;
    }

    public function testTerminateFlushesLogBufferBeforeDrainingDispatchers(): void
    {
        $order = [];

        /** @var MockObject&ApiClient $apiClient */
        $apiClient = $this->createMock(ApiClient::class);
        $apiClient->method('sendLogs')->willReturnCallback(static function () use (&$order): bool {
            $order[] = 'sendLogs';

            return true;
        });
        $apiClient->method('flush')->willReturnCallback(static function () use (&$order): void {
            $order[] = 'flush';
        });

        $handler = $this->handlerWithOneBufferedLog($apiClient);
        $subscriber = new FlushTelemetrySubscriber($apiClient, $handler);

        $subscriber->onKernelTerminate($this->makeTerminateEvent());

        // The buffered log must be dispatched (handler->flushLogs -> sendLogs) BEFORE the
        // post-response drain (apiClient->flush), so its async handle is drained this
        // terminate rather than left for PHP shutdown.
        $this->assertSame(['sendLogs', 'flush'], $order);
    }

    public function testThrowingLogHandlerFlushIsSwallowed(): void
    {
        /** @var MockObject&ApiClient $apiClient */
        $apiClient = $this->createMock(ApiClient::class);
        $apiClient->method('sendLogs')->willThrowException(new \RuntimeException('collector exploded'));

        $handler = $this->handlerWithOneBufferedLog($apiClient);
        $subscriber = new FlushTelemetrySubscriber($apiClient, $handler);

        // A throwing log flush must never propagate into the host's terminate phase.
        $subscriber->onKernelTerminate($this->makeTerminateEvent());

        $this->addToAssertionCount(1);
    }

    /**
     * Smoke-test: constructing ApiClient with a real (non-mock) CircuitBreaker
     * and calling flush() on an empty dispatcher must not throw.
     */
    public function testFlushOnEmptyDispatcherIsNoOp(): void
    {
        $circuitBreaker = new CircuitBreaker(
            enabled: true,
            failureThreshold: 5,
            timeout: 60,
            maxHalfOpenAttempts: 1,
            cache: new ArrayAdapter(),
        );

        $apiClient = new ApiClient(
            dsn: 'https://key@example.com/proj',
            apiKey: 'test-key',
            timeout: 1.0,
            retryAttempts: 0,
            async: true,
            circuitBreaker: $circuitBreaker,
            logger: null,
            debug: false,
        );

        $subscriber = new FlushTelemetrySubscriber($apiClient);

        // No pending responses → must complete instantly without error.
        $subscriber->onKernelTerminate($this->makeTerminateEvent());

        $this->addToAssertionCount(1);
    }
}
