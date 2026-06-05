<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\Service;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Context Collector.
 *
 * Safely collects request, user, and environment context for error reports.
 * All methods are wrapped in try-catch to ensure context collection never crashes.
 *
 * RESILIENCE: Returns empty arrays/null on any errors - never throws exceptions.
 */
class ContextCollector
{
    public function __construct(
        private readonly DataScrubber $scrubber,
        private readonly ?string $release,
        private readonly string $environment,
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * Collect full context for an error.
     *
     * @return array<string, mixed>
     */
    public function collectContext(): array
    {
        return [
            'request' => $this->collectRequest(),
            'user' => $this->collectUser(),
            'server' => $this->collectServer(),
            'environment' => $this->environment,
            'release' => $this->release,
        ];
    }

    /**
     * Collect request information.
     *
     * @return array<string, mixed>|null
     */
    public function collectRequest(): ?array
    {
        try {
            $request = $this->requestStack->getCurrentRequest();

            if (null === $request) {
                return null;
            }

            $headers = [];
            foreach ($request->headers->all() as $key => $value) {
                $headers[$key] = \is_array($value) ? implode(', ', $value) : $value;
            }

            return [
                'url' => $request->getUri(),
                'method' => $this->sanitizeHttpMethod($request->getMethod()),
                'query_string' => $request->getQueryString(),
                'headers' => $this->scrubber->scrub($headers),
                'data' => $this->scrubber->scrub($request->request->all()),
                'cookies' => $this->scrubber->scrub($request->cookies->all()),
                'env' => [
                    'REMOTE_ADDR' => $this->scrubber->anonymizeIp($request->getClientIp()),
                    'SERVER_NAME' => $request->getHost(),
                    'SERVER_PORT' => $request->getPort(),
                    'REQUEST_URI' => $request->getRequestUri(),
                    'HTTP_USER_AGENT' => $request->headers->get('User-Agent'),
                ],
            ];
        } catch (\Throwable) {
            // Never crash on context collection
            return null;
        }
    }

    /**
     * Collect user information.
     *
     * @return array<string, mixed>|null
     */
    public function collectUser(): ?array
    {
        try {
            $request = $this->requestStack->getCurrentRequest();

            if (null === $request || !$request->hasSession()) {
                return null;
            }

            $session = $request->getSession();

            // Try to get user from security token
            $user = null;
            if ($session->has('_security_main')) {
                // This is a simplified approach - in real implementation,
                // you'd inject Security and get the actual user
                // For now, we'll just capture session ID
            }

            return [
                'ip_address' => $this->scrubber->anonymizeIp($request->getClientIp()),
                'session_id' => $session->getId(),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Get session hash for GDPR-compliant session tracking.
     *
     * Returns SHA-256 hash of the Application Logger session ID if available.
     * This ensures sessions can be correlated without storing identifiable data.
     *
     * @return string|null 64-character hex hash, or null if no session
     */
    public function getSessionHash(): ?string
    {
        try {
            $request = $this->requestStack->getCurrentRequest();

            if (null === $request || !$request->hasSession()) {
                return null;
            }

            $session = $request->getSession();
            $sessionId = $session->get('_application_logger_session_id');

            if (null !== $sessionId && \is_string($sessionId)) {
                return hash('sha256', $sessionId);
            }

            return null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Collect server information.
     *
     * @return array<string, mixed>
     */
    public function collectServer(): array
    {
        try {
            $serverInfo = [
                'php_version' => \PHP_VERSION,
                'php_sapi' => \PHP_SAPI,
                'symfony_version' => \Symfony\Component\HttpKernel\Kernel::VERSION,
                'server_name' => gethostname() ?: 'unknown',
                'os' => \PHP_OS,
            ];

            // Detect web server software (Apache, Nginx, Caddy, etc.)
            if (isset($_SERVER['SERVER_SOFTWARE'])) {
                $serverSoftware = $_SERVER['SERVER_SOFTWARE'];
                $serverInfo['server_software'] = $serverSoftware;

                // Parse server name and version
                if (preg_match('/^([^\/\s]+)(?:\/([^\s]+))?/', $serverSoftware, $matches)) {
                    $serverInfo['server_product'] = $matches[1];
                    $serverInfo['server_version'] = $matches[2] ?? 'unknown';
                }
            }

            // Add additional server details if available
            if (isset($_SERVER['SERVER_PROTOCOL'])) {
                $serverInfo['server_protocol'] = $_SERVER['SERVER_PROTOCOL'];
            }

            if (isset($_SERVER['HTTPS'])) {
                $serverInfo['https'] = 'on' === strtolower($_SERVER['HTTPS']);
            }

            return $serverInfo;
        } catch (\Throwable) {
            // Defensive catch for resilience - even though nothing should throw
            return [];
        }
    }

    /**
     * Sanitize HTTP method to allowed API values.
     *
     * API only accepts: GET, POST, PUT, PATCH, DELETE, HEAD, OPTIONS
     * Other methods (TRACE, CONNECT, PROPFIND, etc.) return null to avoid validation errors.
     */
    private function sanitizeHttpMethod(string $method): ?string
    {
        $allowedMethods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];
        $method = strtoupper($method);

        return \in_array($method, $allowedMethods, true) ? $method : null;
    }
}
