<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\EventSubscriber;

use ApplicationLogger\Bundle\Service\Sdk\SessionClientInterface;
use ApplicationLogger\Sdk\DataScrubber;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Uid\Uuid;

/**
 * Automatically track user sessions.
 *
 * Generates session IDs, tracks page views, and sends data to the API.
 * Designed to be non-intrusive and resilient.
 *
 * HOT-PATH DEFERRAL (BUNDLE-2): the request-phase handler does ONLY cheap, local
 * work — read/rotate the session id in the session bag, update last-activity, and
 * COLLECT the createSession/page_view/endSession *intent* (plus the request-derived
 * data: anonymised IP, user-agent, scrubbed URL — none of which survive to
 * kernel.terminate). The actual API POSTs are DEFERRED to kernel.terminate, after
 * the response has been flushed to the client, mirroring the error/log paths. The
 * host hot path therefore pays ~0 for session telemetry (no synchronous dispatch,
 * no fire-and-forget poll on the request thread).
 */
final class SessionTrackingSubscriber implements EventSubscriberInterface
{
    private const SESSION_KEY = '_application_logger_session_id';
    private const LAST_ACTIVITY_KEY = '_application_logger_last_activity';
    private const REGISTERED_KEY = '_application_logger_session_registered';

    /**
     * Session API intent collected during kernel.request and flushed at
     * kernel.terminate. Keyed slots so a (theoretical) re-entrant main request does
     * not duplicate; in practice there is one main request per process.
     *
     * @var array{
     *     endSessionId?: string,
     *     createSession?: array<string, mixed>,
     *     pageView?: array{sessionId: string, event: array<string, mixed>}
     * }
     */
    private array $pending = [];

    /**
     * @param array<string> $ignoredRoutes
     * @param array<string> $ignoredPaths
     */
    public function __construct(
        private readonly SessionClientInterface $sessionClient,
        private readonly bool $enabled,
        private readonly bool $trackPageViews,
        private readonly int $idleTimeout,
        private readonly array $ignoredRoutes,
        private readonly array $ignoredPaths,
        private readonly DataScrubber $scrubber,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', -100],
            // Priority -1023: flush the collected session intent at terminate, just
            // BEFORE FlushTelemetrySubscriber (-1024) drains the dispatcher, so the
            // session POSTs queued here are dispatched and then completed in the same
            // post-response window.
            KernelEvents::TERMINATE => ['onKernelTerminate', -1023],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$this->enabled) {
            return;
        }

        // Only handle main requests
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        // Skip internal routes (profiler, wdt, etc.)
        $route = $request->attributes->get('_route');
        if (null !== $route && $this->shouldIgnoreRoute((string) $route)) {
            return;
        }

        // Skip API and fragment paths
        $path = $request->getPathInfo();
        if ($this->shouldIgnorePath($path)) {
            return;
        }

        try {
            $session = $request->hasSession() ? $request->getSession() : null;

            if (null === $session) {
                // No session available - skip tracking
                return;
            }

            $sessionId = $this->getOrCreateSessionId($session);
            $lastActivity = $session->get(self::LAST_ACTIVITY_KEY);

            // Check if session has expired (idle timeout)
            $idleTimeout = $this->idleTimeout;
            $now = time();

            $expired = false;
            if (null !== $lastActivity && ($now - $lastActivity) > $idleTimeout) {
                // Session expired - record end-of-old-session intent and rotate. The
                // POST is deferred to terminate; the session-bag rotation happens now so
                // the next request sees the fresh id/registration state.
                $this->pending['endSessionId'] = $sessionId;
                $sessionId = $this->createNewSession($session);
                $session->remove(self::REGISTERED_KEY);
                $expired = true;
            }

            // Update last activity
            $session->set(self::LAST_ACTIVITY_KEY, $now);

            // DEBOUNCE: only POST createSession once per session lifetime (or after a
            // rotation). Previously this fired on EVERY request, generating 2-3 API
            // calls per host request. Subsequent page views still record events.
            if ($expired || true !== $session->get(self::REGISTERED_KEY)) {
                // Generate session hash (SHA-256 of session_id for GDPR compliance).
                // Build the payload now (it needs the request: IP + user-agent) but
                // DEFER the POST to kernel.terminate.
                $sessionHash = hash('sha256', $sessionId);

                $this->pending['createSession'] = [
                    'session_id' => $sessionId,
                    'session_hash' => $sessionHash,
                    // GDPR: anonymise the IP before it ever leaves the host app.
                    'ip_address' => $this->scrubber->anonymizeIp($request->getClientIp()),
                    'user_agent' => $request->headers->get('User-Agent'),
                ];

                // Mark registered NOW so a follow-up request in the same session does
                // not re-queue createSession even though the POST has not fired yet.
                $session->set(self::REGISTERED_KEY, true);
            }

            // Track page view
            if ($this->trackPageViews) {
                // Event type MUST match the platform's SessionEventTypeEnum backing values,
                // which are lower_snake_case ('page_view'). The platform drops unknown types
                // silently (SessionEventTypeEnum::tryFrom returns null), so an upper-case
                // 'PAGE_VIEW' here would never persist — keep this in sync with the enum.
                // Built now (needs the request URI); POST deferred to terminate.
                $this->pending['pageView'] = [
                    'sessionId' => $sessionId,
                    'event' => [
                        'type' => 'page_view',
                        // Scrub sensitive query-string VALUES (e.g. ?token=...) before the
                        // URL ever leaves the host app. Same credential-leak class as C1.
                        'url' => $this->scrubber->scrubUrl($request->getUri()),
                        'timestamp' => (new \DateTimeImmutable())->format(\DateTimeImmutable::ATOM),
                    ],
                ];
            }
        } catch (\Throwable $e) {
            // Never let session tracking break the application
            $this->logger?->error('ApplicationLogger: Session tracking failed', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Flush the session API intent collected on kernel.request, AFTER the response has
     * been sent to the client. These POSTs are themselves fire-and-forget (the
     * dispatcher returns immediately) and are completed by FlushTelemetrySubscriber at
     * priority -1024. Never throws into the host app.
     */
    public function onKernelTerminate(TerminateEvent $event): void
    {
        if (!$this->enabled || !$event->isMainRequest()) {
            return;
        }

        // Snapshot + clear so a reused subscriber instance (worker/test) does not
        // re-dispatch stale intent on a later request.
        $pending = $this->pending;
        $this->pending = [];

        if ([] === $pending) {
            return;
        }

        // Preserve the original ordering: end old session, then create new, then page view.
        try {
            if (isset($pending['endSessionId'])) {
                $this->sessionClient->endSession($pending['endSessionId']);
            }
            if (isset($pending['createSession'])) {
                $this->sessionClient->createSession($pending['createSession']);
            }
            if (isset($pending['pageView'])) {
                $this->sessionClient->addSessionEvent(
                    $pending['pageView']['sessionId'],
                    $pending['pageView']['event'],
                );
            }
        } catch (\Throwable $e) {
            // Never let session tracking break the application.
            $this->logger?->error('ApplicationLogger: Session flush failed', [
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get or create session ID.
     */
    private function getOrCreateSessionId(SessionInterface $session): string
    {
        $sessionId = $session->get(self::SESSION_KEY);

        if (null === $sessionId || !\is_string($sessionId)) {
            $sessionId = $this->createNewSession($session);
        }

        return $sessionId;
    }

    /**
     * Create a new session ID and store it.
     */
    private function createNewSession(SessionInterface $session): string
    {
        // toRfc4122() (not toString()): AbstractUid::toString() only exists since
        // symfony/uid 7.0, but the bundle supports ^6.4. toRfc4122() yields the same
        // canonical UUID string on 6.4/7.x/8.x.
        $sessionId = Uuid::v4()->toRfc4122();
        $session->set(self::SESSION_KEY, $sessionId);
        $session->set(self::LAST_ACTIVITY_KEY, time());

        return $sessionId;
    }

    /**
     * Check if route should be ignored.
     */
    private function shouldIgnoreRoute(string $route): bool
    {
        foreach ($this->ignoredRoutes as $ignoredRoute) {
            if (str_starts_with($route, $ignoredRoute)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if path should be ignored.
     */
    private function shouldIgnorePath(string $path): bool
    {
        foreach ($this->ignoredPaths as $ignoredPath) {
            if (str_starts_with($path, $ignoredPath)) {
                return true;
            }
        }

        return false;
    }
}
