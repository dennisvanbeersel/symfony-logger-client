<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\Tests\EventSubscriber;

use ApplicationLogger\Bundle\EventSubscriber\SessionTrackingSubscriber;
use ApplicationLogger\Bundle\Service\Sdk\SessionClientInterface;
use ApplicationLogger\Sdk\DataScrubber;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class SessionTrackingSubscriberTest extends TestCase
{
    private MockObject&SessionClientInterface $sessionClient;
    private Session $session;
    private SessionTrackingSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->sessionClient = $this->createMock(SessionClientInterface::class);
        $this->session = new Session(new MockArraySessionStorage());
        $this->session->start(); // Start the session

        $this->subscriber = new SessionTrackingSubscriber(
            $this->sessionClient,
            true,
            true,
            1800,
            ['_profiler', '_wdt'],
            ['/api/', '/health'],
            new DataScrubber(['password', 'token', 'api_key', 'secret', 'authorization'])
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

    /**
     * Drive the full request → terminate lifecycle so the DEFERRED (BUNDLE-2) session
     * POSTs are actually dispatched. Session API calls now happen on kernel.terminate
     * (post-response), not on kernel.request, so every test that asserts a POST must
     * fire terminate too.
     */
    private function dispatchLifecycle(Request $request, ?SessionTrackingSubscriber $subscriber = null): void
    {
        $subscriber ??= $this->subscriber;
        $kernel = $this->createKernelStub();

        $subscriber->onKernelRequest(
            new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST)
        );
        $subscriber->onKernelTerminate(
            new TerminateEvent($kernel, $request, new Response())
        );
    }

    public function testGetSubscribedEvents(): void
    {
        $events = SessionTrackingSubscriber::getSubscribedEvents();

        $this->assertArrayHasKey(KernelEvents::REQUEST, $events);
        // BUNDLE-2: TERMINATE is now subscribed — the session POSTs are deferred there
        // (post-response) so the request hot path pays ~0.
        $this->assertArrayHasKey(KernelEvents::TERMINATE, $events);
        // RESPONSE is no longer subscribed: the listener was empty (M8). We must
        // not subscribe to an event we don't handle.
        $this->assertArrayNotHasKey(KernelEvents::RESPONSE, $events);
    }

    public function testSessionPostsAreDeferredToTerminateNotRequest(): void
    {
        // BUNDLE-2 core guarantee: NOTHING is POSTed during kernel.request (the hot
        // path); the createSession + page_view POSTs fire only on kernel.terminate.
        $request = Request::create('/test-page');
        $request->setSession($this->session);

        $kernel = $this->createKernelStub();

        // No API calls yet on the request event.
        $this->sessionClient->expects($this->once())->method('createSession');
        $this->sessionClient->expects($this->once())->method('addSessionEvent');

        $this->subscriber->onKernelRequest(
            new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST)
        );

        // The session id was still written to the session bag synchronously so the next
        // request observes it, even though no POST has fired.
        $this->assertNotNull($this->session->get('_application_logger_session_id'));

        // Now the deferred POSTs fire at terminate.
        $this->subscriber->onKernelTerminate(
            new TerminateEvent($kernel, $request, new Response())
        );
    }

    public function testTerminateWithoutPriorRequestIsNoOp(): void
    {
        // A terminate with no collected intent (e.g. an ignored/sessionless request)
        // must not POST anything and must never throw.
        $this->sessionClient->expects($this->never())->method('createSession');
        $this->sessionClient->expects($this->never())->method('addSessionEvent');
        $this->sessionClient->expects($this->never())->method('endSession');

        $kernel = $this->createKernelStub();
        $request = Request::create('/api/ignored');
        $request->setSession($this->session);

        $this->subscriber->onKernelTerminate(
            new TerminateEvent($kernel, $request, new Response())
        );
    }

    public function testOnKernelRequestCreatesNewSession(): void
    {
        $request = Request::create('/test-page');
        $request->setSession($this->session);

        // Expect session creation API call
        $this->sessionClient->expects($this->once())
            ->method('createSession')
            ->with($this->callback(function (array $data) {
                $this->assertArrayHasKey('session_id', $data);
                $this->assertArrayHasKey('session_hash', $data);
                $this->assertArrayHasKey('ip_address', $data);
                $this->assertArrayHasKey('user_agent', $data);

                return true;
            }));

        // Expect page view event
        $this->sessionClient->expects($this->once())
            ->method('addSessionEvent');

        $this->dispatchLifecycle($request);

        // Session ID should be stored
        $sessionId = $this->session->get('_application_logger_session_id');
        $this->assertNotNull($sessionId);
        $this->assertIsString($sessionId);
    }

    public function testOnKernelRequestSkipsIgnoredRoutes(): void
    {
        $request = Request::create('/_profiler/test');
        $request->setSession($this->session);
        $request->attributes->set('_route', '_profiler');

        $kernel = $this->createKernelStub();
        $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        // Should not call API
        $this->sessionClient->expects($this->never())->method('createSession');
        $this->sessionClient->expects($this->never())->method('addSessionEvent');

        $this->subscriber->onKernelRequest($event);
    }

    public function testOnKernelRequestSkipsIgnoredPaths(): void
    {
        $request = Request::create('/api/test');
        $request->setSession($this->session);

        $kernel = $this->createKernelStub();
        $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        // Should not call API
        $this->sessionClient->expects($this->never())->method('createSession');
        $this->sessionClient->expects($this->never())->method('addSessionEvent');

        $this->subscriber->onKernelRequest($event);
    }

    public function testOnKernelRequestReuseExistingSession(): void
    {
        // Set existing session ID
        $existingSessionId = 'existing-session-id';
        $this->session->set('_application_logger_session_id', $existingSessionId);
        $this->session->set('_application_logger_last_activity', time());

        $request = Request::create('/test-page');
        $request->setSession($this->session);

        // Should create/update session (always called)
        $this->sessionClient->expects($this->once())
            ->method('createSession');

        // Should add page view event
        $this->sessionClient->expects($this->once())
            ->method('addSessionEvent')
            ->with(
                $existingSessionId,
                $this->callback(function (array $data) {
                    $this->assertArrayHasKey('type', $data);
                    // MUST match the platform SessionEventTypeEnum backing value (lower_snake_case).
                    // The platform silently drops unknown event types, so an upper-case value here
                    // would never persist (verified end-to-end in the dogfood deep-integration spec).
                    $this->assertEquals('page_view', $data['type']);

                    return true;
                })
            );

        $this->dispatchLifecycle($request);
    }

    public function testOnKernelRequestCreatesNewSessionAfterIdleTimeout(): void
    {
        // Set existing session ID with old timestamp
        $this->session->set('_application_logger_session_id', 'old-session-id');
        $this->session->set('_application_logger_last_activity', time() - 2000); // 2000 seconds ago

        $request = Request::create('/test-page');
        $request->setSession($this->session);

        // Should end old session
        $this->sessionClient->expects($this->once())
            ->method('endSession')
            ->with('old-session-id');

        // Should create new session
        $this->sessionClient->expects($this->once())
            ->method('createSession');

        // Should add page view event
        $this->sessionClient->expects($this->once())
            ->method('addSessionEvent');

        $this->dispatchLifecycle($request);

        // New session ID should be different
        $newSessionId = $this->session->get('_application_logger_session_id');
        $this->assertNotEquals('old-session-id', $newSessionId);
    }

    public function testDoesNotTrackWhenDisabled(): void
    {
        // Create subscriber with disabled tracking
        $subscriber = new SessionTrackingSubscriber(
            $this->sessionClient,
            false,
            true,
            1800,
            [],
            [],
            new DataScrubber(['password', 'token', 'api_key', 'secret', 'authorization'])
        );

        $request = Request::create('/test');
        $request->setSession($this->session);

        $kernel = $this->createKernelStub();
        $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        // Should not make any API calls when tracking is disabled.
        $this->sessionClient->expects($this->never())->method($this->anything());

        $subscriber->onKernelRequest($event);
    }

    public function testSessionDataIncludesUserAgent(): void
    {
        $request = Request::create('/test');
        $request->setSession($this->session);
        $request->headers->set('User-Agent', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)');

        $this->sessionClient->expects($this->once())
            ->method('createSession')
            ->with($this->callback(function (array $data) {
                $this->assertArrayHasKey('user_agent', $data);
                $this->assertStringContainsString('Macintosh', $data['user_agent']);

                return true;
            }));

        $this->sessionClient->expects($this->once())
            ->method('addSessionEvent');

        $this->dispatchLifecycle($request);
    }

    public function testPageViewUrlScrubsSensitiveQueryParam(): void
    {
        // A page_view URL with a sensitive query param must have its VALUE redacted
        // before it ever leaves the host app (same credential-leak class as C1).
        $request = Request::create('/dashboard?token=secret123&page=2');
        $request->setSession($this->session);

        $this->sessionClient->expects($this->once())->method('createSession');

        $this->sessionClient->expects($this->once())
            ->method('addSessionEvent')
            ->with(
                $this->anything(),
                $this->callback(function (array $data): bool {
                    $this->assertSame('page_view', $data['type']);
                    $this->assertArrayHasKey('url', $data);
                    // Sensitive value redacted, non-sensitive query + path intact.
                    $this->assertStringNotContainsString('secret123', $data['url']);
                    $this->assertStringContainsString('token=[REDACTED]', $data['url']);
                    $this->assertStringContainsString('page=2', $data['url']);
                    $this->assertStringContainsString('/dashboard', $data['url']);

                    return true;
                })
            );

        $this->dispatchLifecycle($request);
    }

    public function testSessionIdIsValidUuid(): void
    {
        $request = Request::create('/test');
        $request->setSession($this->session);

        $this->sessionClient->expects($this->once())
            ->method('createSession')
            ->with($this->callback(function (array $data) {
                $sessionId = $data['session_id'];
                // Validate UUID v4 format
                $uuidPattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';
                $this->assertMatchesRegularExpression($uuidPattern, $sessionId);

                return true;
            }));

        $this->sessionClient->expects($this->once())
            ->method('addSessionEvent');

        $this->dispatchLifecycle($request);
    }
}
