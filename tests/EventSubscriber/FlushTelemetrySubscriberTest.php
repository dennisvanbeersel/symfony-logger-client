<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\Tests\EventSubscriber;

use ApplicationLogger\Bundle\EventSubscriber\FlushTelemetrySubscriber;
use ApplicationLogger\Bundle\Service\ApiClient;
use ApplicationLogger\Bundle\Service\CircuitBreaker;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
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
