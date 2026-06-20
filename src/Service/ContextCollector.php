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
 *
 * `final`: collaborators and tests depend on {@see ContextCollectorInterface} (the
 * mock seam) rather than on this concrete class, so it can be sealed.
 */
final class ContextCollector implements ContextCollectorInterface
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
            // Precompute the session hash ONCE here so ErrorPayloadFactory can read it
            // from the context instead of re-running getSessionHash() (a second
            // RequestStack + session lookup) for every error it builds.
            'session_hash' => $this->getSessionHash(),
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
                // Cookie headers carry session identifiers, CSRF tokens, and
                // remember-me secrets. Their names do not match any scrub
                // fragment, so force-redact them regardless of scrub_fields.
                if (\in_array(strtolower((string) $key), ['cookie', 'set-cookie'], true)) {
                    $headers[$key] = '[REDACTED]';
                    continue;
                }

                $headers[$key] = \is_array($value) ? implode(', ', $value) : $value;
            }

            return [
                // getUri()/getQueryString() expose raw credentials/PII in the query
                // (e.g. ?token=, ?password=). Scrub query VALUES whose name is
                // sensitive before they leave the host. Path/host are kept intact.
                'url' => $this->scrubber->scrubUrl($request->getUri()),
                'method' => $this->sanitizeHttpMethod($request->getMethod()),
                'query_string' => $this->scrubber->scrubQueryString($request->getQueryString()),
                'headers' => $this->scrubber->scrub($headers),
                'data' => $this->scrubber->scrub($request->request->all()),
                'cookies' => $this->scrubber->scrub($request->cookies->all()),
                'env' => [
                    'REMOTE_ADDR' => $this->scrubber->anonymizeIp($request->getClientIp()),
                    'SERVER_NAME' => $request->getHost(),
                    'SERVER_PORT' => $request->getPort(),
                    // getRequestUri() is path+query; scrub sensitive query values too.
                    'REQUEST_URI' => $this->scrubber->scrubUrl($request->getRequestUri()),
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

            // Never emit the live PHP session id verbatim: it is the real
            // framework session identifier and is usable for session hijacking.
            // Hash it (SHA-256) so sessions can still be correlated without
            // shipping a usable credential. Consistent with getSessionHash().
            $rawSessionId = $session->getId();

            return [
                'ip_address' => $this->scrubber->anonymizeIp($request->getClientIp()),
                'session_id' => '' !== $rawSessionId ? hash('sha256', $rawSessionId) : null,
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
            $serverSoftware = $this->serverValue('SERVER_SOFTWARE');
            if (null !== $serverSoftware) {
                $serverInfo['server_software'] = $serverSoftware;

                // Parse server name and version
                if (preg_match('/^([^\/\s]+)(?:\/([^\s]+))?/', $serverSoftware, $matches)) {
                    $serverInfo['server_product'] = $matches[1];
                    $serverInfo['server_version'] = $matches[2] ?? 'unknown';
                }
            }

            // Add additional server details if available
            $serverProtocol = $this->serverValue('SERVER_PROTOCOL');
            if (null !== $serverProtocol) {
                $serverInfo['server_protocol'] = $serverProtocol;
            }

            $https = $this->serverValue('HTTPS');
            if (null !== $https) {
                $serverInfo['https'] = 'on' === strtolower($https);
            }

            return $serverInfo;
        } catch (\Throwable) {
            // Defensive catch for resilience - even though nothing should throw
            return [];
        }
    }

    /**
     * Read a server variable, preferring the current Request's server bag so we
     * use the framework's request scope rather than the raw $_SERVER superglobal.
     *
     * Falls back to $_SERVER only when there is no active Request (e.g. CLI),
     * where the server bag is unavailable but $_SERVER may still hold values.
     */
    private function serverValue(string $key): ?string
    {
        $request = $this->requestStack->getCurrentRequest();

        $value = null !== $request
            ? $request->server->get($key)
            : ($_SERVER[$key] ?? null);

        return null !== $value ? (string) $value : null;
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
