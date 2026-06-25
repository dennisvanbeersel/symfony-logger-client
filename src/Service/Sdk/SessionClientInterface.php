<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\Service\Sdk;

/**
 * Contract for the session-tracking transport used by subscribers.
 *
 * Extracted so that {@see SessionApiClient} (final readonly) can be mocked in
 * tests without requiring a Mockery extension or removing the `final` modifier.
 *
 * @internal not part of the bundle's public API
 */
interface SessionClientInterface
{
    /**
     * Create a new session.
     *
     * @param array<string, mixed> $sessionData
     */
    public function createSession(array $sessionData): void;

    /**
     * Add event(s) to an existing session.
     *
     * @param array<string, mixed>|array<int, array<string, mixed>> $eventData
     */
    public function addSessionEvent(string $sessionId, array $eventData): void;

    /**
     * End a session.
     */
    public function endSession(string $sessionId, ?\DateTimeImmutable $endedAt = null): void;

    /**
     * Flush pending sends. Never throws.
     */
    public function flush(): void;
}
