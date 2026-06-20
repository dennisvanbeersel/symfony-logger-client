<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\Service;

/**
 * Seam for {@see ContextCollector} so the concrete collector can be `final` while
 * its collaborators (ExceptionSubscriber, ApplicationLoggerHandler,
 * ErrorPayloadFactory) and their unit tests depend on an interface they can mock,
 * rather than mocking a final class.
 *
 * RESILIENCE: every implementation method is total — it returns a safe default
 * (empty array / null) on any internal failure and never throws into the host app.
 */
interface ContextCollectorInterface
{
    /**
     * Collect full context for an error.
     *
     * The returned array includes a precomputed `session_hash` (see
     * {@see getSessionHash()}) so consumers do not have to re-invoke the
     * RequestStack/session lookup per error.
     *
     * @return array<string, mixed>
     */
    public function collectContext(): array;

    /**
     * Collect request information, or null when there is no active request.
     *
     * @return array<string, mixed>|null
     */
    public function collectRequest(): ?array;

    /**
     * Collect user information, or null when there is no active request/session.
     *
     * @return array<string, mixed>|null
     */
    public function collectUser(): ?array;

    /**
     * Collect server information.
     *
     * @return array<string, mixed>
     */
    public function collectServer(): array;

    /**
     * SHA-256 hash of the Application Logger session id, or null if unavailable.
     *
     * @return string|null 64-character hex hash, or null if no session
     */
    public function getSessionHash(): ?string;
}
