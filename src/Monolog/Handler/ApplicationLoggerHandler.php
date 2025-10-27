<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\Monolog\Handler;

use ApplicationLogger\Bundle\Service\ApiClient;
use ApplicationLogger\Bundle\Service\BreadcrumbCollector;
use ApplicationLogger\Bundle\Service\ContextCollector;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * Monolog Handler for Application Logger.
 *
 * Sends Monolog log records to Application Logger platform.
 * Only processes logs at or above the configured level.
 *
 * RESILIENCE GUARANTEE:
 * - All operations wrapped in try-catch
 * - Never throws exceptions to caller
 * - Handles failures gracefully
 */
class ApplicationLoggerHandler extends AbstractProcessingHandler
{
    private readonly Level $minimumLevel;

    public function __construct(
        private readonly ApiClient $apiClient,
        private readonly ContextCollector $contextCollector,
        private readonly BreadcrumbCollector $breadcrumbCollector,
        string $captureLevel = 'error',
    ) {
        // Convert string level to Monolog Level enum
        $this->minimumLevel = Level::fromName(ucfirst(strtolower($captureLevel)));

        parent::__construct($this->minimumLevel);
    }

    /**
     * Write log record to Application Logger.
     *
     * This method is wrapped in try-catch by Monolog's AbstractProcessingHandler,
     * but we add additional protection to ensure maximum resilience.
     */
    protected function write(LogRecord $record): void
    {
        try {
            // Build payload from log record
            $payload = $this->buildPayload($record);

            // Send to API (async, fire-and-forget)
            $this->apiClient->sendError($payload);
        } catch (\Throwable) {
            // Silently fail - logging errors should never crash the application
            // The AbstractProcessingHandler will catch exceptions, but we double-protect
        }
    }

    /**
     * Build error payload from log record.
     *
     * @return array<string, mixed>
     */
    private function buildPayload(LogRecord $record): array
    {
        try {
            $context = $this->contextCollector->collectContext();

            // Extract exception from context if present
            $exception = $record->context['exception'] ?? null;
            $exceptionData = null;

            if ($exception instanceof \Throwable) {
                $exceptionData = [
                    'type' => \get_class($exception),
                    'value' => $exception->getMessage(),
                    'stacktrace' => $this->parseStackTrace($exception),
                ];
            }

            return [
                'message' => $record->message,
                'level' => $this->mapLevel($record->level),
                'exception' => $exceptionData,
                'platform' => 'symfony',
                'timestamp' => $record->datetime->format(\DateTimeImmutable::ATOM),
                'environment' => $context['environment'] ?? 'production',
                'release' => $context['release'] ?? null,
                'request' => $context['request'] ?? null,
                'user' => $context['user'] ?? null,
                'server' => $context['server'] ?? [],
                'breadcrumbs' => $this->breadcrumbCollector->get(),
                'tags' => [
                    'channel' => $record->channel,
                    'level' => $record->level->name,
                ],
                'extra' => $record->extra,
                'context' => $this->scrubContext($record->context),
            ];
        } catch (\Throwable) {
            // If payload building fails, return minimal payload
            return [
                'message' => $record->message,
                'level' => $this->mapLevel($record->level),
                'platform' => 'symfony',
                'timestamp' => $record->datetime->format(\DateTimeImmutable::ATOM),
            ];
        }
    }

    /**
     * Map Monolog level to Application Logger level.
     */
    private function mapLevel(Level $level): string
    {
        return match ($level) {
            Level::Debug => 'debug',
            Level::Info, Level::Notice => 'info',
            Level::Warning => 'warning',
            Level::Error => 'error',
            Level::Critical, Level::Alert, Level::Emergency => 'critical',
        };
    }

    /**
     * Parse exception stack trace.
     *
     * @return array{frames: list<array<string, mixed>>}
     */
    private function parseStackTrace(\Throwable $exception): array
    {
        try {
            $frames = [];

            foreach ($exception->getTrace() as $trace) {
                $frame = [
                    'filename' => $trace['file'] ?? 'unknown',
                    'lineno' => $trace['line'] ?? 0,
                    'function' => $trace['function'] ?? 'unknown',
                ];

                if (isset($trace['class'])) {
                    $frame['module'] = $trace['class'];
                }

                $frame['in_app'] = !str_contains($frame['filename'], '/vendor/');

                $frames[] = $frame;
            }

            return ['frames' => array_reverse($frames)];
        } catch (\Throwable) {
            return ['frames' => []];
        }
    }

    /**
     * Scrub sensitive data from context.
     *
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function scrubContext(array $context): array
    {
        try {
            // Remove exception as it's already extracted
            unset($context['exception']);

            // Scrub sensitive fields
            $sensitiveFields = ['password', 'token', 'api_key', 'secret', 'authorization'];

            foreach ($context as $key => $value) {
                foreach ($sensitiveFields as $field) {
                    if (false !== stripos($key, $field)) {
                        $context[$key] = '[REDACTED]';
                        break;
                    }
                }
            }

            return $context;
        } catch (\Throwable) {
            return [];
        }
    }
}
