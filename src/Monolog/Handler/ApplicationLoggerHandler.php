<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\Monolog\Handler;

use ApplicationLogger\Bundle\Service\ApiClient;
use ApplicationLogger\Bundle\Service\BreadcrumbCollector;
use ApplicationLogger\Bundle\Service\ContextCollector;
use ApplicationLogger\Bundle\Util\StackTraceParserTrait;
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
    use StackTraceParserTrait;

    private readonly Level $minimumLevel;

    public function __construct(
        private readonly ApiClient $apiClient,
        private readonly ContextCollector $contextCollector,
        private readonly BreadcrumbCollector $breadcrumbCollector,
        string $captureLevel = 'error',
    ) {
        // Convert string level to Monolog Level enum (default to ERROR on invalid input)
        try {
            $this->minimumLevel = Level::fromName(ucfirst(strtolower($captureLevel)));
        } catch (\ValueError) {
            // Invalid level string - fall back to ERROR for resilience
            $this->minimumLevel = Level::Error;
        }

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
     * API expects flat structure with required fields:
     * - type, message, file, line, stack_trace (required)
     * - level, source, environment, etc. (optional)
     *
     * @return array<string, mixed>
     */
    private function buildPayload(LogRecord $record): array
    {
        try {
            $context = $this->contextCollector->collectContext();

            // Extract exception details for required fields
            $exception = $record->context['exception'] ?? null;

            // Determine required field values from exception or defaults
            // Note: API requires line > 0 (Positive constraint), so we default to 1
            $type = 'LogMessage';
            $file = 'unknown';
            $line = 1;
            $stackTrace = [];

            if ($exception instanceof \Throwable) {
                $type = \get_class($exception);
                $file = $exception->getFile();
                $line = $exception->getLine();
                $stackTrace = $this->parseStackTrace($exception);
            }

            return [
                // Required fields (flat structure matching API)
                // Apply length limits to prevent API validation failures
                'type' => $this->truncate($type, 255),
                'message' => $this->truncate($record->message, 1000),
                'file' => $this->truncate($file, 500),
                'line' => $line,
                'stack_trace' => $stackTrace,

                // Optional fields
                'level' => $this->mapLevel($record->level),
                'source' => 'backend',
                'timestamp' => $record->datetime->format(\DateTimeImmutable::ATOM),
                'environment' => $context['environment'] ?? 'production',
                'release' => $context['release'] ?? null,
                'session_hash' => $this->contextCollector->getSessionHash(),
                'server_name' => $context['server']['server_name'] ?? null,
                'url' => $context['request']['url'] ?? null,
                'http_method' => $context['request']['method'] ?? null,
                'ip_address' => $context['request']['env']['REMOTE_ADDR'] ?? null,
                'user_agent' => $context['request']['env']['HTTP_USER_AGENT'] ?? null,
                'runtime' => 'PHP '.\PHP_VERSION,
                'breadcrumbs' => $this->breadcrumbCollector->get(),
                'request_data' => $context['request'] ?? null,
                'context' => $this->scrubContext($record->context),
                'tags' => [
                    'channel' => $record->channel,
                    'monolog_level' => $record->level->name,
                ],
            ];
        } catch (\Throwable) {
            // If payload building fails, return minimal payload with required fields
            // Note: API requires line > 0 (Positive constraint)
            return [
                'type' => 'LogMessage',
                'message' => $this->truncate($record->message, 1000),
                'file' => 'unknown',
                'line' => 1,
                'stack_trace' => [],
                'level' => $this->mapLevel($record->level),
                'source' => 'backend',
                'timestamp' => $record->datetime->format(\DateTimeImmutable::ATOM),
            ];
        }
    }

    /**
     * Map Monolog level to Application Logger level.
     *
     * API accepts: debug, info, warning, error, fatal
     */
    private function mapLevel(Level $level): string
    {
        return match ($level) {
            Level::Debug => 'debug',
            Level::Info, Level::Notice => 'info',
            Level::Warning => 'warning',
            Level::Error => 'error',
            Level::Critical, Level::Alert, Level::Emergency => 'fatal',
        };
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
