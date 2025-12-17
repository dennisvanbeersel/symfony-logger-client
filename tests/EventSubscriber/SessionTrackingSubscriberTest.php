<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\Tests\EventSubscriber;

use ApplicationLogger\Bundle\EventSubscriber\SessionTrackingSubscriber;
use ApplicationLogger\Bundle\Service\ApiClient;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class SessionTrackingSubscriberTest extends TestCase
{
    private MockObject&ApiClient $apiClient;
    private Session $session;
    private SessionTrackingSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->apiClient = $this->createMock(ApiClient::class);
        $this->session = new Session(new MockArraySessionStorage());
        $this->session->start(); // Start the session

        $config = [
            'enabled' => true,
            'track_page_views' => true,
            'idle_timeout' => 1800,
            'ignored_routes' => ['_profiler', '_wdt'],
            'ignored_paths' => ['/api/', '/health'],
        ];

        $this->subscriber = new SessionTrackingSubscriber(
            $this->apiClient,
            $config
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
        $events = SessionTrackingSubscriber::getSubscribedEvents();

        $this->assertArrayHasKey(KernelEvents::REQUEST, $events);
        $this->assertArrayHasKey(KernelEvents::RESPONSE, $events);
    }

    public function testOnKernelRequestCreatesNewSession(): void
    {
        $request = Request::create('/test-page');
        $request->setSession($this->session);

        $kernel = $this->createKernelStub();
        $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        // Expect session creation API call
        $this->apiClient->expects($this->once())
            ->method('createSession')
            ->with($this->callback(function (array $data) {
                $this->assertArrayHasKey('session_id', $data);
                $this->assertArrayHasKey('session_hash', $data);
                $this->assertArrayHasKey('ip_address', $data);
                $this->assertArrayHasKey('user_agent', $data);

                return true;
            }));

        // Expect page view event
        $this->apiClient->expects($this->once())
            ->method('addSessionEvent');

        $this->subscriber->onKernelRequest($event);

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
        $this->apiClient->expects($this->never())->method('createSession');
        $this->apiClient->expects($this->never())->method('addSessionEvent');

        $this->subscriber->onKernelRequest($event);
    }

    public function testOnKernelRequestSkipsIgnoredPaths(): void
    {
        $request = Request::create('/api/test');
        $request->setSession($this->session);

        $kernel = $this->createKernelStub();
        $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        // Should not call API
        $this->apiClient->expects($this->never())->method('createSession');
        $this->apiClient->expects($this->never())->method('addSessionEvent');

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

        $kernel = $this->createKernelStub();
        $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        // Should create/update session (always called)
        $this->apiClient->expects($this->once())
            ->method('createSession');

        // Should add page view event
        $this->apiClient->expects($this->once())
            ->method('addSessionEvent')
            ->with(
                $existingSessionId,
                $this->callback(function (array $data) {
                    $this->assertArrayHasKey('type', $data);
                    $this->assertEquals('PAGE_VIEW', $data['type']);

                    return true;
                })
            );

        $this->subscriber->onKernelRequest($event);
    }

    public function testOnKernelRequestCreatesNewSessionAfterIdleTimeout(): void
    {
        // Set existing session ID with old timestamp
        $this->session->set('_application_logger_session_id', 'old-session-id');
        $this->session->set('_application_logger_last_activity', time() - 2000); // 2000 seconds ago

        $request = Request::create('/test-page');
        $request->setSession($this->session);

        $kernel = $this->createKernelStub();
        $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        // Should end old session
        $this->apiClient->expects($this->once())
            ->method('endSession')
            ->with('old-session-id');

        // Should create new session
        $this->apiClient->expects($this->once())
            ->method('createSession');

        // Should add page view event
        $this->apiClient->expects($this->once())
            ->method('addSessionEvent');

        $this->subscriber->onKernelRequest($event);

        // New session ID should be different
        $newSessionId = $this->session->get('_application_logger_session_id');
        $this->assertNotEquals('old-session-id', $newSessionId);
    }

    public function testOnKernelResponseDoesNotTrackWhenDisabled(): void
    {
        // Create subscriber with disabled tracking
        $disabledConfig = [
            'enabled' => false,
            'track_page_views' => true,
            'idle_timeout' => 1800,
            'ignored_routes' => [],
            'ignored_paths' => [],
        ];

        $subscriber = new SessionTrackingSubscriber(
            $this->apiClient,
            $disabledConfig
        );

        $request = Request::create('/test');
        $request->setSession($this->session);
        $response = new Response();

        $kernel = $this->createKernelStub();
        $event = new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);

        // Should not make any API calls
        $this->apiClient->expects($this->never())->method($this->anything());

        $subscriber->onKernelResponse($event);
    }

    public function testSessionDataIncludesUserAgent(): void
    {
        $request = Request::create('/test');
        $request->setSession($this->session);
        $request->headers->set('User-Agent', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)');

        $kernel = $this->createKernelStub();
        $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        $this->apiClient->expects($this->once())
            ->method('createSession')
            ->with($this->callback(function (array $data) {
                $this->assertArrayHasKey('user_agent', $data);
                $this->assertStringContainsString('Macintosh', $data['user_agent']);

                return true;
            }));

        $this->apiClient->expects($this->once())
            ->method('addSessionEvent');

        $this->subscriber->onKernelRequest($event);
    }

    public function testSessionIdIsValidUuid(): void
    {
        $request = Request::create('/test');
        $request->setSession($this->session);

        $kernel = $this->createKernelStub();
        $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        $this->apiClient->expects($this->once())
            ->method('createSession')
            ->with($this->callback(function (array $data) {
                $sessionId = $data['session_id'];
                // Validate UUID v4 format
                $uuidPattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';
                $this->assertMatchesRegularExpression($uuidPattern, $sessionId);

                return true;
            }));

        $this->apiClient->expects($this->once())
            ->method('addSessionEvent');

        $this->subscriber->onKernelRequest($event);
    }
}
