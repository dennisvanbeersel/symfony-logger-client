<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\Monolog\Handler;

use ApplicationLogger\Bundle\Service\ContextCollectorInterface;
use ApplicationLogger\Bundle\Service\Sdk\LoopbackGuard;
use ApplicationLogger\Bundle\Service\Sdk\SdkClientFactory;
use ApplicationLogger\Sdk\DataScrubber;
use ApplicationLogger\Sdk\Event;
use ApplicationLogger\Sdk\Severity;
use ApplicationLogger\Sdk\StackTraceParser;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * Monolog Handler for Application Logger.
 *
 * Routes records to the correct pipeline:
 * - Records WITH an attached Throwable -> error pipeline (Hub::captureEvent) so they group,
 *   fingerprint and surface on the error dashboard.
 * - Records WITHOUT an exception -> LOG AGGREGATION pipeline (LogClient::log) which ships
 *   them to the Go log-collector (ClickHouse). This avoids the previous bug where
 *   every plain log message fingerprinted to a single ('LogMessage','unknown',1)
 *   ErrorGroup. When log aggregation is not configured, LogClient is null and the path no-ops.
 *
 * Buffering is fully delegated to LogClient (sdk-core). The handler no longer buffers.
 *
 * RESILIENCE GUARANTEE: never throws to the caller; logging must never crash the app.
 */
final class ApplicationLoggerHandler extends AbstractProcessingHandler
{
    private readonly Level $minimumLevel;

    public function __construct(
        private readonly SdkClientFactory $factory,
        private readonly ContextCollectorInterface $contextCollector,
        private readonly DataScrubber $scrubber,
        private readonly LoopbackGuard $loopback,
        private readonly bool $enabled = true,
        string $captureLevel = 'error',
        private readonly string $environment = 'production',
        private readonly bool $errorTrackingEnabled = true,
        private readonly bool $logAggregationEnabled = true,
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
     * is delegated to LogClient for the log-aggregation pipeline.
     */
    protected function write(LogRecord $record): void
    {
        // Master kill-switch wins over the sub-toggles.
        if (!$this->enabled) {
            return;
        }

        try {
            if ($this->loopback->isIngestRequest()) {
                return; // loopback gates BOTH paths
            }

            $exception = $record->context['exception'] ?? null;

            if ($exception instanceof \Throwable) {
                if ($this->errorTrackingEnabled) {
                    $this->captureErrorEvent($record, $exception);

                    return;
                }
                // Error tracking off: fall through to log aggregation (the Throwable is
                // stripped by emitLog()/stripException()) if it is enabled; else drop.
                if (!$this->logAggregationEnabled) {
                    return;
                }
            } elseif (!$this->logAggregationEnabled) {
                // Plain record, log aggregation disabled.
                return;
            }

            $this->emitLog($record);
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
     * Flush buffered log-aggregation records via the LogClient.
     */
    public function flushLogs(): void
    {
        try {
            $this->factory->getHub()->getLogClient()?->flush();
        } catch (\Throwable) {
            // ignore
        }
    }

    /**
     * Build and capture an sdk-core Event for a record that carries a Throwable.
     * captureEvent does NOT auto-collect context or parse a stack trace, so we
     * enrich both here from the LogRecord data.
     */
    private function captureErrorEvent(LogRecord $record, \Throwable $exception): void
    {
        try {
            $context = array_merge(
                $this->contextCollector->collectContext(),
                $this->stripException($record->context),
            );

            $event = new Event(
                type: $exception::class,
                message: $this->truncate($record->message, 1000),
                file: $exception->getFile() ?: 'unknown',
                line: max(1, $exception->getLine()),
                level: Severity::fromName($this->mapLevel($record->level)),
                environment: \is_string($context['environment'] ?? null) ? $context['environment'] : $this->environment,
                release: \is_string($context['release'] ?? null) ? $context['release'] : null,
                timestamp: $record->datetime,
                tags: ['channel' => $record->channel, 'monolog_level' => $record->level->name],
                context: $context,
                stackTrace: (new StackTraceParser())->parse($exception),
            );

            $this->factory->getHub()->captureEvent($event);
        } catch (\Throwable) {
            // degraded: ship minimal event so the error isn't lost
            try {
                $this->factory->getHub()->captureEvent(new Event(
                    type: $exception::class,
                    message: $this->truncate($record->message, 1000),
                    file: 'unknown',
                    line: 1,
                    level: Severity::fromName($this->mapLevel($record->level)),
                    environment: $this->environment,
                    release: null,
                    timestamp: $record->datetime,
                    tags: ['channel' => $record->channel, 'monolog_level' => $record->level->name],
                ));
            } catch (\Throwable) {
                // give up silently
            }
        }
    }

    /**
     * Delegate a non-exception record to LogClient for log aggregation.
     * Pre-scrubs and flattens the context (collector contract: map<string,string>).
     */
    private function emitLog(LogRecord $record): void
    {
        $logClient = $this->factory->getHub()->getLogClient();
        if (null === $logClient) {
            return; // log aggregation unconfigured → no-op
        }

        $scrubbed = $this->scrubber->scrub($this->stripException($record->context));
        $ctx = $this->flattenContext($scrubbed);
        $ctx['channel'] = $record->channel;

        $logClient->log(
            $this->mapSeverity($record->level),
            $this->truncate($record->message, 8000),
            $ctx,
        );
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
     * Truncate a string to at most $max bytes, appending '…' when cut.
     *
     * Uses mb_strcut() so the cut always falls on a valid UTF-8 character
     * boundary — a raw substr() cut can split a multibyte character and emit
     * an invalid byte sequence, which causes json_encode(JSON_THROW_ON_ERROR)
     * to drop the entire event in sdk-core's HttpTransport.
     *
     * The suffix '…' is 3 bytes in UTF-8, so the budget for the actual content
     * is $max - 3 bytes.
     */
    private function truncate(string $value, int $max): string
    {
        if (\strlen($value) <= $max) {
            return $value;
        }

        return mb_strcut($value, 0, $max - 3, 'UTF-8').'…';
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
