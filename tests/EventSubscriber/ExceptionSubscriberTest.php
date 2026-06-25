<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\Tests\EventSubscriber;

use ApplicationLogger\Bundle\EventSubscriber\ExceptionSubscriber;
use ApplicationLogger\Bundle\Service\ContextCollectorInterface;
use ApplicationLogger\Bundle\Service\Sdk\BundleContextCollector;
use ApplicationLogger\Bundle\Service\Sdk\LoopbackGuard;
use ApplicationLogger\Bundle\Service\Sdk\SdkClientFactory;
use ApplicationLogger\Sdk\Client;
use ApplicationLogger\Sdk\Clock\SystemClock;
use ApplicationLogger\Sdk\DataScrubber;
use ApplicationLogger\Sdk\Hub;
use ApplicationLogger\Sdk\Options;
use ApplicationLogger\Sdk\Scope;
use ApplicationLogger\Sdk\StackTraceParser;
use ApplicationLogger\Sdk\Transport\FileTransport;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class ExceptionSubscriberTest extends TestCase
{
    private MockObject&ContextCollectorInterface $contextCollector;
    private string $transportFile;
    private FileTransport $transport;
    private Hub $hub;
    private SdkClientFactory $factory;
    private ExceptionSubscriber $subscriber;

    protected function setUp(): void
    {
        Hub::reset();

        $this->contextCollector = $this->createMock(ContextCollectorInterface::class);
        $this->contextCollector->method('collectUser')->willReturn(['id' => 42, 'email' => 'test@example.com']);

        $this->transportFile = sys_get_temp_dir().'/applogger-test-'.uniqid('', true).'.ndjson';
        $this->transport = new FileTransport($this->transportFile);

        $this->hub = $this->buildFileHub($this->transport);
        $this->factory = $this->buildFactory($this->hub);

        $this->subscriber = new ExceptionSubscriber($this->factory, $this->contextCollector);
    }

    protected function tearDown(): void
    {
        Hub::reset();
        if (is_file($this->transportFile)) {
            unlink($this->transportFile);
        }
    }

    // ---------------------------------------------------------------------------
    // helpers
    // ---------------------------------------------------------------------------

    /**
     * Build a real sdk-core Hub backed by a FileTransport so captured events
     * can be inspected without a network call.
     */
    private function buildFileHub(FileTransport $transport): Hub
    {
        $inner = $this->createMock(ContextCollectorInterface::class);
        $inner->method('collectContext')->willReturn([]);
        $ctx = new BundleContextCollector($inner);

        $opts = Options::fromArray([
            'dsn' => 'https://applogger.eu/0xTEST',
            'api_key' => 'pk_test',
            'environment' => 'test',
            'release' => null,
            'enabled' => true,
            'scrub_fields' => [],
            'max_breadcrumbs' => 50,
            'timeout' => 2.0,
            'flush_budget' => 2.0,
            'circuit_breaker' => ['failure_threshold' => 5, 'timeout' => 60, 'half_open_attempts' => 1],
            'log_endpoint' => null,
            'log_token' => null,
            'session_hash_salt' => null,
            'app_name' => 'test-app',
            'cache_dir' => sys_get_temp_dir().'/applogger-test-hub-'.uniqid('', true),
        ]);

        $scrubber = new DataScrubber([], []);
        $client = new Client($opts, $transport, new SystemClock(), $scrubber, new StackTraceParser(), $ctx);

        return new Hub($client, new Scope($opts->maxBreadcrumbs));
    }

    /**
     * Build a real SdkClientFactory pre-loaded with the given Hub.
     * SdkClientFactory::getHub() returns $this->hub if non-null (skips build()),
     * so we inject the Hub via reflection before any getHub() call.
     * SdkClientFactory is final — subclassing is not possible.
     */
    private function buildFactory(Hub $hub): SdkClientFactory
    {
        $inner = $this->createMock(ContextCollectorInterface::class);
        $inner->method('collectContext')->willReturn([]);
        $ctx = new BundleContextCollector($inner);

        $config = [
            'dsn' => '',
            'api_key' => '',
            'environment' => 'test',
            'release' => null,
            'enabled' => true,
            'scrub_fields' => [],
            'max_breadcrumbs' => 50,
            'timeout' => 2.0,
            'flush_budget' => 2.0,
            'circuit_breaker' => ['failure_threshold' => 5, 'timeout' => 60, 'half_open_attempts' => 1],
            'log_endpoint' => null,
            'log_token' => null,
            'session_hash_salt' => null,
            'app_name' => 'test',
            'cache_dir' => sys_get_temp_dir().'/applogger-test-factory-'.uniqid('', true),
        ];

        $factory = new SdkClientFactory($config, $ctx, new LoopbackGuard(new RequestStack(), []));

        // Pre-load the test Hub into the factory's private $hub property so that
        // getHub() returns it immediately without calling build() (which would produce
        // a NullTransport Hub since dsn is empty above).
        $ref = new \ReflectionProperty(SdkClientFactory::class, 'hub');
        $ref->setValue($factory, $hub);

        return $factory;
    }

    private function exceptionEvent(\Throwable $exception): ExceptionEvent
    {
        return new ExceptionEvent(
            $this->createKernelStub(),
            Request::create('/test'),
            HttpKernelInterface::MAIN_REQUEST,
            $exception,
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

    // ---------------------------------------------------------------------------
    // tests
    // ---------------------------------------------------------------------------

    public function testGetSubscribedEvents(): void
    {
        $events = ExceptionSubscriber::getSubscribedEvents();

        $this->assertArrayHasKey(KernelEvents::EXCEPTION, $events);
        $this->assertEquals(['onKernelException', -100], $events[KernelEvents::EXCEPTION]);
    }

    public function testCapturesExceptionIntoHub(): void
    {
        $exception = new \RuntimeException('Test error message');

        $this->subscriber->onKernelException($this->exceptionEvent($exception));

        $events = $this->transport->capturedEvents();
        $this->assertCount(1, $events, 'Expected exactly one captured event');
        $this->assertEquals('RuntimeException', $events[0]['type']);
        $this->assertEquals('Test error message', $events[0]['message']);
        $this->assertEquals('error', $events[0]['level']);
    }

    public function testCapturedEventHasExceptionClassTag(): void
    {
        $exception = new \InvalidArgumentException('bad arg');

        $this->subscriber->onKernelException($this->exceptionEvent($exception));

        $events = $this->transport->capturedEvents();
        $this->assertCount(1, $events);
        $this->assertArrayHasKey('tags', $events[0]);
        $this->assertEquals(\InvalidArgumentException::class, $events[0]['tags']['exception_class'] ?? null);
    }

    public function testHttpExceptionTagsStatusCode(): void
    {
        $exception = new NotFoundHttpException('Page not found');

        $this->subscriber->onKernelException($this->exceptionEvent($exception));

        $events = $this->transport->capturedEvents();
        $this->assertCount(1, $events);
        $this->assertEquals('404', $events[0]['tags']['http_status_code'] ?? null);
    }

    public function testNonHttpExceptionTagsStatusCode500(): void
    {
        $exception = new \RuntimeException('Internal error');

        $this->subscriber->onKernelException($this->exceptionEvent($exception));

        $events = $this->transport->capturedEvents();
        $this->assertCount(1, $events);
        $this->assertEquals('500', $events[0]['tags']['http_status_code'] ?? null);
    }

    public function testDoesNotCaptureWhenMasterDisabled(): void
    {
        $subscriber = new ExceptionSubscriber(
            $this->factory,
            $this->contextCollector,
            false,
            false, // enabled = false
            true,
        );

        $subscriber->onKernelException($this->exceptionEvent(new \RuntimeException('boom')));

        $this->assertCount(0, $this->transport->capturedEvents());
    }

    public function testDoesNotCaptureWhenErrorTrackingDisabled(): void
    {
        $subscriber = new ExceptionSubscriber(
            $this->factory,
            $this->contextCollector,
            false,
            true,
            false, // errorTrackingEnabled = false
        );

        $subscriber->onKernelException($this->exceptionEvent(new \RuntimeException('boom')));

        $this->assertCount(0, $this->transport->capturedEvents());
    }

    public function testNeverThrowsOnInternalFailure(): void
    {
        // Build a factory whose private $hub is a hub whose captureException throws.
        // We achieve this by building a normal factory but NOT pre-loading the hub,
        // so getHub() tries to build() — which with empty dsn/api_key returns a valid
        // NullTransport hub. Instead, let's set the hub to an anonymous Hub subclass.
        // Since Hub is final we can't subclass it either. The cleanest approach:
        // set $hub = null on the factory so build() is called; build() with empty dsn
        // returns a NullTransport hub that won't throw. To test resilience, mock
        // the inner ContextCollector to throw inside collectUser().
        $throwingCollector = $this->createMock(ContextCollectorInterface::class);
        $throwingCollector->method('collectUser')
            ->willThrowException(new \RuntimeException('collector failure'));

        $subscriber = new ExceptionSubscriber(
            $this->factory,
            $throwingCollector,
        );

        // Must not throw — resilience guarantee
        $subscriber->onKernelException($this->exceptionEvent(new \RuntimeException('original')));

        $this->addToAssertionCount(1);
        $this->assertCount(0, $this->transport->capturedEvents(), 'Capture must not proceed when collectUser throws');
    }

    public function testSetsUserOnScopeWhenCollectorReturnsArray(): void
    {
        $this->subscriber->onKernelException($this->exceptionEvent(new \RuntimeException('user context test')));

        $events = $this->transport->capturedEvents();
        $this->assertCount(1, $events);
        // User set on Scope flows into event->context['user'] via Scope::applyTo()
        $this->assertArrayHasKey('context', $events[0]);
        $this->assertArrayHasKey('user', $events[0]['context']);
        $this->assertEquals(42, $events[0]['context']['user']['id'] ?? null);
    }

    public function testDoesNotSetUserWhenCollectorReturnsNull(): void
    {
        $nullUserCollector = $this->createMock(ContextCollectorInterface::class);
        $nullUserCollector->method('collectUser')->willReturn(null);
        $nullUserCollector->method('collectContext')->willReturn([]);

        $subscriber = new ExceptionSubscriber($this->factory, $nullUserCollector);

        $subscriber->onKernelException($this->exceptionEvent(new \RuntimeException('no user')));

        $events = $this->transport->capturedEvents();
        $this->assertCount(1, $events);
        // When collectUser() returns null the subscriber does NOT call $scope->setUser(),
        // so the Scope never merges user data. Any 'user' key in context comes only from
        // the BundleContextCollector (Client-level enrichment) — not from the Scope.
        // Assert the scope did not inject a concrete user id.
        $contextUser = $events[0]['context']['user'] ?? null;
        $this->assertNull($contextUser, 'Scope must not inject a user when collectUser() returns null');
    }
}
