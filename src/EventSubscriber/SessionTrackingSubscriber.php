<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\EventSubscriber;

use ApplicationLogger\Bundle\Service\ApiClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Uid\Uuid;

/**
 * Automatically track user sessions.
 *
 * Generates session IDs, tracks page views, and sends data to the API.
 * Designed to be non-intrusive and resilient.
 */
class SessionTrackingSubscriber implements EventSubscriberInterface
{
    private const SESSION_KEY = '_application_logger_session_id';
    private const LAST_ACTIVITY_KEY = '_application_logger_last_activity';

    /**
     * @param array{enabled: bool, track_page_views: bool, idle_timeout: int, ignored_routes: array<string>, ignored_paths: array<string>} $config
     */
    public function __construct(
        private readonly ApiClient $apiClient,
        private readonly array $config,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', -100],
            KernelEvents::RESPONSE => ['onKernelResponse', -100],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$this->config['enabled']) {
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
            $idleTimeout = $this->config['idle_timeout'];
            $now = time();

            if (null !== $lastActivity && ($now - $lastActivity) > $idleTimeout) {
                // Session expired - end old session and create new one
                $oldSessionId = $sessionId;
                $this->apiClient->endSession($oldSessionId);
                $sessionId = $this->createNewSession($session);
            }

            // Update last activity
            $session->set(self::LAST_ACTIVITY_KEY, $now);

            // Generate session hash (SHA-256 of session_id for GDPR compliance)
            $sessionHash = hash('sha256', $sessionId);

            // Create/update session
            $this->apiClient->createSession([
                'session_id' => $sessionId,
                'session_hash' => $sessionHash,
                'ip_address' => $request->getClientIp(),
                'user_agent' => $request->headers->get('User-Agent'),
            ]);

            // Track page view
            if ($this->config['track_page_views']) {
                $this->apiClient->addSessionEvent($sessionId, [
                    'type' => 'PAGE_VIEW',
                    'url' => $request->getUri(),
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

    public function onKernelResponse(ResponseEvent $event): void
    {
        // Currently just updates last activity time
        // Could be extended to track response status, duration, etc.
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
        foreach ($this->config['ignored_routes'] as $ignoredRoute) {
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
        foreach ($this->config['ignored_paths'] as $ignoredPath) {
            if (str_starts_with($path, $ignoredPath)) {
                return true;
            }
        }

        return false;
    }
}
