<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\Tests\Monolog\Handler;

use ApplicationLogger\Bundle\Monolog\Handler\ApplicationLoggerHandler;
use ApplicationLogger\Bundle\Service\ApiClient;
use ApplicationLogger\Bundle\Service\BreadcrumbCollector;
use ApplicationLogger\Bundle\Service\ContextCollectorInterface;
use ApplicationLogger\Bundle\Service\DataScrubber;
use ApplicationLogger\Bundle\Service\ErrorPayloadFactory;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ApplicationLoggerHandlerTest extends TestCase
{
    private MockObject&ApiClient $apiClient;
    private MockObject&ContextCollectorInterface $contextCollector;
    private MockObject&BreadcrumbCollector $breadcrumbCollector;
    private ApplicationLoggerHandler $handler;

    protected function setUp(): void
    {
        $this->apiClient = $this->createMock(ApiClient::class);
        $this->contextCollector = $this->createMock(ContextCollectorInterface::class);
        $this->breadcrumbCollector = $this->createMock(BreadcrumbCollector::class);

        $this->contextCollector->method('collectContext')->willReturn([
            'environment' => 'test',
            'release' => '1.0.0',
            'request' => [
                'url' => 'https://example.com/test',
                'method' => 'POST',
                'env' => [
                    'REMOTE_ADDR' => '10.0.0.1',
                    'HTTP_USER_AGENT' => 'Test Agent',
                ],
            ],
            'server' => [
                'server_name' => 'test-server',
            ],
        ]);

        $this->breadcrumbCollector->method('get')->willReturn([]);

        $this->handler = $this->createHandler($this->apiClient);
    }

    private function createHandler(ApiClient $apiClient, string $captureLevel = 'error', int $batchSize = 1, bool $enabled = true): ApplicationLoggerHandler
    {
        return new ApplicationLoggerHandler(
            $apiClient,
            $this->contextCollector,
            new DataScrubber(['password', 'token', 'api_key', 'secret', 'authorization']),
            new ErrorPayloadFactory($this->contextCollector, $this->breadcrumbCollector),
            enabled: $enabled,
            captureLevel: $captureLevel,
            environment: 'test',
            // batchSize 1 => flush each log record immediately for deterministic assertions.
            batchSize: $batchSize,
        );
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $extra
     */
    private function createLogRecord(
        Level $level,
        string $message,
        array $context = [],
        array $extra = []
    ): LogRecord {
        return new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'test',
            level: $level,
            message: $message,
            context: $context,
            extra: $extra
        );
    }

    // ---- Exception records -> error pipeline (sendError) ----

    public function testExceptionRecordIsRoutedToSendError(): void
    {
        $exception = new \InvalidArgumentException('Invalid argument provided');
        $record = $this->createLogRecord(Level::Error, 'Error occurred', ['exception' => $exception]);

        $this->apiClient->expects($this->once())
            ->method('sendError')
            ->with($this->callback(function (array $payload) use ($exception) {
                $this->assertEquals('InvalidArgumentException', $payload['type']);
                $this->assertEquals('Error occurred', $payload['message']);
                $this->assertEquals($exception->getFile(), $payload['file']);
                $this->assertEquals($exception->getLine(), $payload['line']);
                $this->assertNotEmpty($payload['stack_trace']);
                $this->assertArrayNotHasKey('exception', $payload);

                return true;
            }));
        // Non-exception path must NOT be used for an exception record.
        $this->apiClient->expects($this->never())->method('sendLogs');

        $this->handler->handle($record);
    }

    public function testExceptionPayloadHasFlatStructure(): void
    {
        $record = $this->createLogRecord(Level::Error, 'msg', ['exception' => new \RuntimeException('x')]);

        $this->apiClient->expects($this->once())
            ->method('sendError')
            ->with($this->callback(function (array $payload) {
                foreach (['type', 'message', 'file', 'line', 'stack_trace'] as $key) {
                    $this->assertArrayHasKey($key, $payload);
                }
                $this->assertArrayNotHasKey('frames', $payload['stack_trace']);

                return true;
            }));

        $this->handler->handle($record);
    }

    public function testExceptionContextIsScrubbedRecursively(): void
    {
        $record = $this->createLogRecord(Level::Error, 'msg', [
            'exception' => new \RuntimeException('x'),
            'password' => 'secret123',
            'nested' => ['api_key' => 'k', 'safe' => 'v'],
        ]);

        $this->apiClient->expects($this->once())
            ->method('sendError')
            ->with($this->callback(function (array $payload) {
                $this->assertEquals('[REDACTED]', $payload['context']['password']);
                // Recursive scrub (the original shallow loop missed this).
                $this->assertEquals('[REDACTED]', $payload['context']['nested']['api_key']);
                $this->assertEquals('v', $payload['context']['nested']['safe']);
                $this->assertArrayNotHasKey('exception', $payload['context']);

                return true;
            }));

        $this->handler->handle($record);
    }

    // ---- Non-exception records -> LOG AGGREGATION pipeline (sendLogs) ----

    public function testNonExceptionRecordIsRoutedToSendLogs(): void
    {
        $record = $this->createLogRecord(Level::Error, 'Simple log message');

        // It must NOT hit the error pipeline (was the fingerprint-collapse bug).
        $this->apiClient->expects($this->never())->method('sendError');

        $this->apiClient->expects($this->once())
            ->method('sendLogs')
            ->with($this->callback(function (array $batch) {
                $this->assertCount(1, $batch);
                $entry = $batch[0];
                // Collector LogEntry contract fields.
                $this->assertEquals('Simple log message', $entry['message']);
                $this->assertEquals('error', $entry['severity']);
                $this->assertEquals('test', $entry['app_name']); // channel
                $this->assertArrayHasKey('timestamp', $entry);
                $this->assertArrayHasKey('context', $entry);
                $this->assertEquals('test', $entry['context']['channel']);

                return true;
            }));

        $this->handler->handle($record);
    }

    public function testNonExceptionLogContextIsScrubbedAndStringified(): void
    {
        $record = $this->createLogRecord(Level::Error, 'msg', [
            'password' => 'secret',
            'count' => 5,
            'flag' => true,
        ]);

        $this->apiClient->expects($this->once())
            ->method('sendLogs')
            ->with($this->callback(function (array $batch) {
                $ctx = $batch[0]['context'];
                $this->assertEquals('[REDACTED]', $ctx['password']);
                // map<string,string> contract: everything stringified.
                $this->assertSame('5', $ctx['count']);
                $this->assertSame('true', $ctx['flag']);

                return true;
            }));

        $this->handler->handle($record);
    }

    #[DataProvider('severityMappingProvider')]
    public function testSeverityMappingForLogs(Level $level, string $expected): void
    {
        $apiClient = $this->createMock(ApiClient::class);
        $handler = $this->createHandler($apiClient, captureLevel: 'debug');

        $apiClient->expects($this->once())
            ->method('sendLogs')
            ->with($this->callback(function (array $batch) use ($expected) {
                $this->assertEquals($expected, $batch[0]['severity']);

                return true;
            }));

        $handler->handle($this->createLogRecord($level, 'msg'));
    }

    /**
     * @return array<string, array{Level, string}>
     */
    public static function severityMappingProvider(): array
    {
        return [
            'debug' => [Level::Debug, 'debug'],
            'info' => [Level::Info, 'info'],
            'notice' => [Level::Notice, 'notice'],
            'warning' => [Level::Warning, 'warning'],
            'error' => [Level::Error, 'error'],
            'critical' => [Level::Critical, 'critical'],
            'alert' => [Level::Alert, 'alert'],
            'emergency' => [Level::Emergency, 'emergency'],
        ];
    }

    public function testLogsAreBatchedUntilBatchSizeReached(): void
    {
        $apiClient = $this->createMock(ApiClient::class);
        // batchSize 3: first two records buffer, the third triggers one flush of 3.
        $handler = $this->createHandler($apiClient, captureLevel: 'debug', batchSize: 3);

        $apiClient->expects($this->once())
            ->method('sendLogs')
            ->with($this->callback(function (array $batch) {
                $this->assertCount(3, $batch);

                return true;
            }));

        $handler->handle($this->createLogRecord(Level::Info, 'one'));
        $handler->handle($this->createLogRecord(Level::Info, 'two'));
        $handler->handle($this->createLogRecord(Level::Info, 'three'));
    }

    public function testBufferIsBoundedByMaxBuffer(): void
    {
        $apiClient = $this->createMock(ApiClient::class);
        // Large batch size so it never auto-flushes; tiny maxBuffer to force dropping.
        $handler = new ApplicationLoggerHandler(
            $apiClient,
            $this->contextCollector,
            new DataScrubber([]),
            new ErrorPayloadFactory($this->contextCollector, $this->breadcrumbCollector),
            captureLevel: 'debug',
            environment: 'test',
            batchSize: 1000,
            maxBuffer: 2,
        );

        $captured = null;
        $apiClient->method('sendLogs')->willReturnCallback(function (array $batch) use (&$captured) {
            $captured = $batch;

            return true;
        });

        $handler->handle($this->createLogRecord(Level::Info, 'first'));
        $handler->handle($this->createLogRecord(Level::Info, 'second'));
        $handler->handle($this->createLogRecord(Level::Info, 'third'));

        $handler->flushLogs();

        $this->assertNotNull($captured);
        // Oldest ('first') dropped; only the last 2 remain.
        $this->assertCount(2, $captured);
        $messages = array_map(static fn (array $e) => $e['message'], $captured);
        $this->assertSame(['second', 'third'], $messages);
    }

    public function testResilienceWhenApiThrows(): void
    {
        $record = $this->createLogRecord(Level::Error, 'Test message');
        $this->apiClient->method('sendLogs')->willThrowException(new \RuntimeException('boom'));

        // Must not propagate.
        $this->handler->handle($record);

        $this->addToAssertionCount(1);
    }

    // ---- Enabled gate (C2): a disabled install must never ship anything ----

    public function testDisabledHandlerNeverSendsExceptionRecord(): void
    {
        $apiClient = $this->createMock(ApiClient::class);
        $handler = $this->createHandler($apiClient, captureLevel: 'debug', enabled: false);

        $apiClient->expects($this->never())->method('sendError');
        $apiClient->expects($this->never())->method('sendLogs');

        $handler->handle($this->createLogRecord(Level::Error, 'boom', ['exception' => new \RuntimeException('x')]));
        $handler->flushLogs();
    }

    public function testDisabledHandlerNeverSendsLogRecord(): void
    {
        $apiClient = $this->createMock(ApiClient::class);
        $handler = $this->createHandler($apiClient, captureLevel: 'debug', enabled: false);

        $apiClient->expects($this->never())->method('sendError');
        $apiClient->expects($this->never())->method('sendLogs');

        $handler->handle($this->createLogRecord(Level::Info, 'plain log'));
        $handler->flushLogs();
    }

    public function testEnabledHandlerSendsExceptionRecord(): void
    {
        $apiClient = $this->createMock(ApiClient::class);
        $handler = $this->createHandler($apiClient, captureLevel: 'error', enabled: true);

        $apiClient->expects($this->once())->method('sendError');
        $apiClient->expects($this->never())->method('sendLogs');

        $handler->handle($this->createLogRecord(Level::Error, 'boom', ['exception' => new \RuntimeException('x')]));
    }
}
