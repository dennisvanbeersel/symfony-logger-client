<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\Service;

use ApplicationLogger\Bundle\Util\StackTraceParserTrait;

/**
 * Builds the error-pipeline payload shared by ExceptionSubscriber and
 * ApplicationLoggerHandler.
 *
 * Both consumers previously hand-rolled a near-identical ~20-field payload from
 * the same ContextCollector output (plus a minimal catch-block fallback). This
 * factory centralises that mapping so the field set stays in lock-step.
 *
 * Consumer-specific differences are expressed as `$overrides`:
 * - ExceptionSubscriber adds `http_status_code` and exception tags (`http_method`
 *   is part of the base mapping below).
 * - ApplicationLoggerHandler overrides `message` (from the LogRecord), `level`,
 *   `timestamp`, `context` (scrubbed record context) and channel/level tags.
 *
 * RESILIENCE: never throws. Stack parsing/truncation come from StackTraceParserTrait.
 */
final class ErrorPayloadFactory
{
    use StackTraceParserTrait;

    public function __construct(
        private readonly ContextCollector $contextCollector,
        private readonly BreadcrumbCollector $breadcrumbCollector,
    ) {
    }

    /**
     * Build the common error payload from a Throwable + collected context, then
     * shallow-merge `$overrides` on top (consumer-specific fields).
     *
     * @param array<string, mixed> $context ContextCollector::collectContext() output
     * @param array<string, mixed> $overrides fields to add/replace for this consumer
     *
     * @return array<string, mixed>
     */
    public function fromThrowable(\Throwable $exception, array $context, array $overrides = []): array
    {
        $base = [
            // Required fields (flat structure with snake_case).
            // Apply length limits to prevent API validation failures.
            'type' => $this->truncate(\get_class($exception), 255),
            'message' => $this->truncate($exception->getMessage(), 1000),
            'file' => $this->truncate($exception->getFile(), 500),
            'line' => $exception->getLine(),
            'stack_trace' => $this->parseStackTrace($exception),

            // Optional fields (all snake_case to match API).
            'level' => 'error',
            'source' => 'backend',
            'environment' => $context['environment'] ?? 'production',
            'release' => $context['release'] ?? null,
            'session_hash' => $this->contextCollector->getSessionHash(),
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeImmutable::ATOM),
            'server_name' => $context['server']['server_name'] ?? null,
            'url' => $context['request']['url'] ?? null,
            'http_method' => $context['request']['method'] ?? null,
            'ip_address' => $context['request']['env']['REMOTE_ADDR'] ?? null,
            'user_agent' => $context['request']['env']['HTTP_USER_AGENT'] ?? null,
            'runtime' => 'PHP '.\PHP_VERSION,
            'breadcrumbs' => $this->breadcrumbCollector->get(),
            'request_data' => $context['request'] ?? null,
            'context' => $context['server'] ?? [],
            'tags' => [],
        ];

        return array_merge($base, $overrides);
    }

    /**
     * Minimal catch-block fallback payload, centralised. Used when full payload
     * building fails. `$overrides` lets each consumer set its message/level/
     * timestamp/file/line semantics.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public function minimalFallback(\Throwable $exception, array $overrides = []): array
    {
        $base = [
            'type' => $this->truncate(\get_class($exception), 255),
            'message' => $this->truncate($exception->getMessage(), 1000),
            'file' => $this->truncate($exception->getFile(), 500),
            'line' => $exception->getLine(),
            'stack_trace' => [],
            'level' => 'error',
            'source' => 'backend',
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeImmutable::ATOM),
        ];

        return array_merge($base, $overrides);
    }

    /**
     * Truncate a value to the API field length using the shared trait helper.
     *
     * Exposed so consumers building overrides (e.g. message from a LogRecord)
     * apply the exact same truncation rules as the base payload.
     */
    public function truncateValue(string $value, int $maxLength): string
    {
        return $this->truncate($value, $maxLength);
    }
}
