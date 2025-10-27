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
    /**
     * @param list<string> $scrubFields
     */
    public function __construct(
        private readonly array $scrubFields,
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
                'method' => $request->getMethod(),
                'query_string' => $request->getQueryString(),
                'headers' => $this->scrubSensitiveData($headers),
                'data' => $this->scrubSensitiveData($request->request->all()),
                'cookies' => $this->scrubSensitiveData($request->cookies->all()),
                'env' => [
                    'REMOTE_ADDR' => $this->anonymizeIp($request->getClientIp()),
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
                'id' => $session->getId(),
                'ip_address' => $this->anonymizeIp($request->getClientIp()),
                'session_id' => $session->getId(),
            ];
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
                    $serverInfo['server_product'] = $matches[1] ?? 'unknown';
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
            // @phpstan-ignore-next-line catch.neverThrown
        } catch (\Throwable) {
            // Defensive catch for resilience - even though nothing should throw
            return [];
        }
    }

    /**
     * Scrub sensitive data from arrays.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function scrubSensitiveData(array $data): array
    {
        try {
            $scrubbed = [];

            foreach ($data as $key => $value) {
                // Check if key matches any scrub pattern
                $shouldScrub = false;
                foreach ($this->scrubFields as $field) {
                    if (false !== stripos($key, $field)) {
                        $shouldScrub = true;
                        break;
                    }
                }

                if ($shouldScrub) {
                    $scrubbed[$key] = '[REDACTED]';
                } elseif (\is_array($value)) {
                    $scrubbed[$key] = $this->scrubSensitiveData($value);
                } else {
                    $scrubbed[$key] = $value;
                }
            }

            return $scrubbed;
        } catch (\Throwable) {
            // If scrubbing fails, return empty array (safe default)
            return [];
        }
    }

    /**
     * Anonymize IP address (mask last octet for IPv4, last 80 bits for IPv6).
     */
    private function anonymizeIp(?string $ip): ?string
    {
        if (null === $ip) {
            return null;
        }

        try {
            if (filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4)) {
                // IPv4: mask last octet
                $parts = explode('.', $ip);
                $parts[3] = '0';

                return implode('.', $parts);
            }

            if (filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6)) {
                // IPv6: mask last 80 bits (keep first 48 bits)
                $addr = inet_pton($ip);
                if (false !== $addr) {
                    $addr = substr($addr, 0, 6).str_repeat("\0", 10);
                    $anonymized = inet_ntop($addr);

                    return false !== $anonymized ? $anonymized : $ip;
                }
            }

            return $ip;
        } catch (\Throwable) {
            return null; // If anonymization fails, return null (safer than exposing real IP)
        }
    }
}
