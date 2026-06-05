<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\Monolog\Handler;

use ApplicationLogger\Bundle\Service\ApiClient;
use ApplicationLogger\Bundle\Service\BreadcrumbCollector;
use ApplicationLogger\Bundle\Service\ContextCollector;
use ApplicationLogger\Bundle\Service\DataScrubber;
use ApplicationLogger\Bundle\Util\StackTraceParserTrait;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * Monolog Handler for Application Logger.
 *
 * Routes records to the correct pipeline:
 * - Records WITH an attached Throwable -> error pipeline (sendError) so they group,
 *   fingerprint and surface on the error dashboard.
 * - Records WITHOUT an exception -> LOG AGGREGATION pipeline (sendLog) which ships
 *   them to the Go log-collector (ClickHouse). This avoids the previous bug where
 *   every plain log message fingerprinted to a single ('LogMessage','unknown',1)
 *   ErrorGroup. When log aggregation is not configured, sendLog() no-ops.
 *
 * It also batches log-aggregation records and flushes them in a single HTTP request
 * to reduce per-message overhead, with a hard buffer cap to bound memory.
 *
 * RESILIENCE GUARANTEE: never throws to the caller; logging must never crash the app.
 */
class ApplicationLoggerHandler extends AbstractProcessingHandler
{
    use StackTraceParserTrait;

    private readonly Level $minimumLevel;

    /** @var array<int, array<string, mixed>> */
    private array $logBuffer = [];

    public function __construct(
        private readonly ApiClient $apiClient,
        private readonly ContextCollector $contextCollector,
        private readonly BreadcrumbCollector $breadcrumbCollector,
        private readonly DataScrubber $scrubber,
        string $captureLevel = 'error',
        private readonly string $environment = 'production',
        private readonly int $batchSize = 50,
        private readonly int $maxBuffer = 1000,
    ) {
        try {
            $this->minimumLevel = Level::fromName(ucfirst(strtolower($captureLevel)));
        } catch (\ValueError) {
            $this->minimumLevel = Level::Error;
        }

        parent::__construct($this->minimumLevel);
    }

    public function __destruct()
    {
        // Flush any buffered log-aggregation records on shutdown. Never throws.
        try {
            $this->flushLogs();
        } catch (\Throwable) {
            // ignore
        }
    }

    /**
     * Write a single record. Exceptions go to the error pipeline; everything else
     * is buffered for the log-aggregation pipeline.
     */
    protected function write(LogRecord $record): void
    {
        try {
            $exception = $record->context['exception'] ?? null;

            if ($exception instanceof \Throwable) {
                $this->apiClient->sendError($this->buildErrorPayload($record, $exception));

                return;
            }

            $this->logBuffer[] = $this->buildLogEntry($record);

            // Bound memory: drop the OLDEST entry beyond the hard cap.
            while (\count($this->logBuffer) > $this->maxBuffer) {
                array_shift($this->logBuffer);
            }

            if (\count($this->logBuffer) >= $this->batchSize) {
                $this->flushLogs();
            }
        } catch (\Throwable) {
            // Silently fail - logging errors must never crash the application.
        }
    }

    /**
     * Monolog batch entry-point; ensure we flush after a batch is handled.
     *
     * @param array<int, LogRecord> $records
     */
    public function handleBatch(array $records): void
    {
        parent::handleBatch($records);
        try {
            $this->flushLogs();
        } catch (\Throwable) {
            // ignore
        }
    }

    /**
     * Flush buffered log-aggregation records to the collector in one (batched) request.
     */
    public function flushLogs(): void
    {
        if ([] === $this->logBuffer) {
            return;
        }

        $batch = $this->logBuffer;
        $this->logBuffer = [];

        // sendLogs() never throws and no-ops when log aggregation is unconfigured.
        $this->apiClient->sendLogs($batch);
    }

    /**
     * Build the error-pipeline payload for a record that carries a Throwable.
     *
     * @return array<string, mixed>
     */
    private function buildErrorPayload(LogRecord $record, \Throwable $exception): array
    {
        try {
            $context = $this->contextCollector->collectContext();

            return [
                'type' => $this->truncate(\get_class($exception), 255),
                'message' => $this->truncate($record->message, 1000),
                'file' => $this->truncate($exception->getFile(), 500),
                'line' => max(1, $exception->getLine()),
                'stack_trace' => $this->parseStackTrace($exception),
                'level' => $this->mapLevel($record->level),
                'source' => 'backend',
                'timestamp' => $record->datetime->format(\DateTimeImmutable::ATOM),
                'environment' => $context['environment'] ?? $this->environment,
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
                'context' => $this->scrubber->scrub($this->stripException($record->context)),
                'tags' => [
                    'channel' => $record->channel,
                    'monolog_level' => $record->level->name,
                ],
            ];
        } catch (\Throwable) {
            return [
                'type' => $this->truncate(\get_class($exception), 255),
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
     * Build a collector LogEntry from a non-exception record.
     *
     * Matches the Go collector HTTP contract (internal/http handlers.go LogEntry):
     * timestamp(RFC3339), severity(string), message, app_name, environment,
     * context(map<string,string>). Sensitive context fields are scrubbed recursively
     * and the map is flattened/stringified because the collector context is
     * map<string,string>.
     *
     * @return array<string, mixed>
     */
    private function buildLogEntry(LogRecord $record): array
    {
        $context = [];
        try {
            $scrubbed = $this->scrubber->scrub($this->stripException($record->context));
            $context = $this->flattenContext($scrubbed);
            // Preserve the originating channel for filtering/grouping in ClickHouse.
            $context['channel'] = $record->channel;
        } catch (\Throwable) {
            $context = [];
        }

        return [
            'timestamp' => $record->datetime->format(\DateTimeImmutable::ATOM),
            'severity' => $this->mapSeverity($record->level),
            'message' => $this->truncate($record->message, 8000),
            'app_name' => $this->truncate($record->channel, 255),
            'environment' => $this->environment,
            'context' => $context,
        ];
    }

    /**
     * Flatten an arbitrary context array to a map<string,string> (collector contract).
     *
     * @param array<array-key, mixed> $data
     *
     * @return array<string, string>
     */
    private function flattenContext(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $k = (string) $key;
            if (\is_scalar($value) || null === $value) {
                $out[$k] = $this->truncate($this->stringify($value), 4000);
            } else {
                try {
                    $out[$k] = $this->truncate((string) json_encode($value), 4000);
                } catch (\Throwable) {
                    $out[$k] = '[unserializable]';
                }
            }
        }

        return $out;
    }

    private function stringify(mixed $value): string
    {
        if (null === $value) {
            return '';
        }
        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function stripException(array $context): array
    {
        unset($context['exception']);

        return $context;
    }

    /**
     * Map Monolog level to platform error level (debug/info/warning/error/fatal).
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
     * Map Monolog level to RFC5424 syslog severity keyword expected by the collector.
     */
    private function mapSeverity(Level $level): string
    {
        return match ($level) {
            Level::Debug => 'debug',
            Level::Info => 'info',
            Level::Notice => 'notice',
            Level::Warning => 'warning',
            Level::Error => 'error',
            Level::Critical => 'critical',
            Level::Alert => 'alert',
            Level::Emergency => 'emergency',
        };
    }
}
