<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\EventSubscriber;

use ApplicationLogger\Bundle\Service\ApiClient;
use ApplicationLogger\Bundle\Service\DataScrubber;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Uid\Uuid;

/**
 * Automatically track user sessions.
 *
 * Generates session IDs, tracks page views, and sends data to the API.
 * Designed to be non-intrusive and resilient.
 */
final class SessionTrackingSubscriber implements EventSubscriberInterface
{
    private const SESSION_KEY = '_application_logger_session_id';
    private const LAST_ACTIVITY_KEY = '_application_logger_last_activity';
    private const REGISTERED_KEY = '_application_logger_session_registered';

    /**
     * @param array<string> $ignoredRoutes
     * @param array<string> $ignoredPaths
     */
    public function __construct(
        private readonly ApiClient $apiClient,
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
                // Session expired - end old session and create new one
                $oldSessionId = $sessionId;
                $this->apiClient->endSession($oldSessionId);
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
                // Generate session hash (SHA-256 of session_id for GDPR compliance)
                $sessionHash = hash('sha256', $sessionId);

                $this->apiClient->createSession([
                    'session_id' => $sessionId,
                    'session_hash' => $sessionHash,
                    // GDPR: anonymise the IP before it ever leaves the host app.
                    'ip_address' => $this->scrubber->anonymizeIp($request->getClientIp()),
                    'user_agent' => $request->headers->get('User-Agent'),
                ]);

                $session->set(self::REGISTERED_KEY, true);
            }

            // Track page view
            if ($this->trackPageViews) {
                // Event type MUST match the platform's SessionEventTypeEnum backing values,
                // which are lower_snake_case ('page_view'). The platform drops unknown types
                // silently (SessionEventTypeEnum::tryFrom returns null), so an upper-case
                // 'PAGE_VIEW' here would never persist — keep this in sync with the enum.
                $this->apiClient->addSessionEvent($sessionId, [
                    'type' => 'page_view',
                    // Scrub sensitive query-string VALUES (e.g. ?token=...) before the
                    // URL ever leaves the host app. Same credential-leak class as C1.
                    'url' => $this->scrubber->scrubUrl($request->getUri()),
                    'timestamp' => (new \DateTimeImmutable())->format(\DateTimeImmutable::ATOM),
                ]);
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
        $sessionId = Uuid::v4()->toString();
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
