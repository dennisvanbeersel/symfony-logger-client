<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\Service;

use ApplicationLogger\Bundle\Service\Sdk\SdkClientFactory;
use ApplicationLogger\Bundle\Service\Sdk\SessionClientInterface;
use ApplicationLogger\Sdk\Event;
use ApplicationLogger\Sdk\Severity;

/**
 * @deprecated since 2.0 — delegates to applogger/sdk-core; inject SdkClientFactory/SessionApiClient directly.
 *
 * Backward-compat facade over the sdk-core Hub + SessionApiClient pipeline.
 * All public methods are preserved for BC; implementations are best-effort
 * delegation that NEVER throws into the host application.
 */
class ApiClient
{
    public function __construct(
        private readonly SdkClientFactory $factory,
        private readonly SessionClientInterface $sessions,
    ) {
    }

    /**
     * Send error payload to the platform (fire-and-forget, best-effort).
     *
     * @param array<string, mixed> $payload
     */
    public function sendError(array $payload): void
    {
        try {
            $type = \is_string($payload['type'] ?? null) ? $payload['type'] : 'Error';
            $message = \is_string($payload['message'] ?? null) ? $payload['message'] : '';
            $file = \is_string($payload['file'] ?? null) ? $payload['file'] : '';
            $line = \is_int($payload['line'] ?? null) ? $payload['line'] : 0;
            $level = Severity::fromName(\is_string($payload['level'] ?? null) ? $payload['level'] : 'error');
            $ts = ($payload['timestamp'] ?? null) instanceof \DateTimeImmutable
                ? $payload['timestamp']
                : new \DateTimeImmutable();

            $event = new Event(
                type: $type,
                message: $message,
                file: $file,
                line: $line,
                level: $level,
                environment: 'production',
                release: null,
                timestamp: $ts,
            );

            $this->factory->getHub()->captureEvent($event);
        } catch (\Throwable) {
        }
    }

    /**
     * Ship a single log record to the log-collector ingestion endpoint (best-effort).
     *
     * @param array<string, mixed> $logEntry
     */
    public function sendLog(array $logEntry): bool
    {
        return $this->sendLogs([$logEntry]);
    }

    /**
     * Ship a batch of log records (best-effort).
     *
     * @param array<int, array<string, mixed>> $logEntries
     */
    public function sendLogs(array $logEntries): bool
    {
        if ([] === $logEntries) {
            return false;
        }

        try {
            $logClient = $this->factory->getHub()->getLogClient();
            if (null === $logClient) {
                return true;
            }

            foreach ($logEntries as $entry) {
                $level = \is_string($entry['level'] ?? null)
                    ? $entry['level']
                    : (\is_string($entry['severity'] ?? null) ? $entry['severity'] : 'info');
                $message = \is_string($entry['message'] ?? null) ? $entry['message'] : '';
                $context = \is_array($entry['context'] ?? null) ? $entry['context'] : [];

                $logClient->log($level, $message, $context);
            }
        } catch (\Throwable) {
        }

        return true;
    }

    /**
     * Synchronous single-log send. Returns 202 on success path, null on failure.
     *
     * @param array<string, mixed> $logEntry
     */
    public function sendLogSync(array $logEntry): ?int
    {
        try {
            $logClient = $this->factory->getHub()->getLogClient();
            if (null === $logClient) {
                return null;
            }

            $level = \is_string($logEntry['level'] ?? null)
                ? $logEntry['level']
                : (\is_string($logEntry['severity'] ?? null) ? $logEntry['severity'] : 'info');
            $message = \is_string($logEntry['message'] ?? null) ? $logEntry['message'] : '';
            $context = \is_array($logEntry['context'] ?? null) ? $logEntry['context'] : [];

            $logClient->log($level, $message, $context);

            return 202;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Create or update a session.
     *
     * @param array<string, mixed> $sessionData
     */
    public function createSession(array $sessionData): void
    {
        try {
            $this->sessions->createSession($sessionData);
        } catch (\Throwable) {
            /* best-effort: never throw into the host */
        }
    }

    /**
     * Add event(s) to a session.
     *
     * @param array<string, mixed>|array<int, array<string, mixed>> $eventData
     */
    public function addSessionEvent(string $sessionId, array $eventData): void
    {
        try {
            $this->sessions->addSessionEvent($sessionId, $eventData);
        } catch (\Throwable) {
            /* best-effort: never throw into the host */
        }
    }

    /**
     * End a session.
     */
    public function endSession(string $sessionId, ?\DateTimeImmutable $endedAt = null): void
    {
        try {
            $this->sessions->endSession($sessionId, $endedAt);
        } catch (\Throwable) {
            /* best-effort: never throw into the host */
        }
    }

    /**
     * Flush pending telemetry. Never throws.
     */
    public function flush(): void
    {
        try {
            $this->factory->getHub()->getClient()->flush();
        } catch (\Throwable) {
        }

        try {
            $this->factory->getHub()->getLogClient()?->flush();
        } catch (\Throwable) {
        }

        try {
            $this->sessions->flush();
        } catch (\Throwable) {
        }
    }

    /**
     * @deprecated test seam — always returns 0.0 in the facade
     */
    public function flushCeilingForTesting(bool $anyHalfOpen): float
    {
        return 0.0;
    }

    /**
     * @deprecated sdk-core does not expose breaker state on Client/Hub
     *
     * @return array{state: string, delegated: bool}
     */
    public function getCircuitBreakerState(): array
    {
        return ['state' => 'unknown', 'delegated' => true];
    }

    /**
     * @deprecated sdk-core does not expose breaker state on Client/Hub
     *
     * @return array{state: string, delegated: bool}
     */
    public function getLogCircuitBreakerState(): array
    {
        return ['state' => 'unknown', 'delegated' => true];
    }
}
