<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\Tests\Monolog\Handler;

use ApplicationLogger\Bundle\Monolog\Handler\ApplicationLoggerHandler;
use ApplicationLogger\Bundle\Service\ApiClient;
use ApplicationLogger\Bundle\Service\BreadcrumbCollector;
use ApplicationLogger\Bundle\Service\ContextCollector;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ApplicationLoggerHandlerTest extends TestCase
{
    private MockObject&ApiClient $apiClient;
    private MockObject&ContextCollector $contextCollector;
    private MockObject&BreadcrumbCollector $breadcrumbCollector;
    private ApplicationLoggerHandler $handler;

    protected function setUp(): void
    {
        $this->apiClient = $this->createMock(ApiClient::class);
        $this->contextCollector = $this->createMock(ContextCollector::class);
        $this->breadcrumbCollector = $this->createMock(BreadcrumbCollector::class);

        // Mock context collector
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

        // Mock breadcrumb collector
        $this->breadcrumbCollector->method('get')->willReturn([]);

        $this->handler = new ApplicationLoggerHandler(
            $this->apiClient,
            $this->contextCollector,
            $this->breadcrumbCollector,
            captureLevel: 'error'
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

    public function testPayloadHasFlatStructureWithRequiredFields(): void
    {
        $record = $this->createLogRecord(Level::Error, 'Test error message');

        $this->apiClient->expects($this->once())
            ->method('sendError')
            ->with($this->callback(function (array $payload) {
                // Required fields must be present at root level (flat structure)
                $this->assertArrayHasKey('type', $payload);
                $this->assertArrayHasKey('message', $payload);
                $this->assertArrayHasKey('file', $payload);
                $this->assertArrayHasKey('line', $payload);
                $this->assertArrayHasKey('stack_trace', $payload);

                // Should NOT have nested 'exception' object
                $this->assertArrayNotHasKey('exception', $payload);

                return true;
            }));

        $this->handler->handle($record);
    }

    public function testLogMessageWithoutExceptionUsesDefaults(): void
    {
        $record = $this->createLogRecord(Level::Error, 'Simple log message');

        $this->apiClient->expects($this->once())
            ->method('sendError')
            ->with($this->callback(function (array $payload) {
                // Type should be LogMessage for non-exception logs
                $this->assertEquals('LogMessage', $payload['type']);
                $this->assertEquals('Simple log message', $payload['message']);
                $this->assertEquals('unknown', $payload['file']);
                // API requires line > 0 (Positive constraint), so default is 1
                $this->assertEquals(1, $payload['line']);
                $this->assertIsArray($payload['stack_trace']);
                $this->assertEmpty($payload['stack_trace']);

                return true;
            }));

        $this->handler->handle($record);
    }

    public function testLogWithExceptionExtractsDetails(): void
    {
        $exception = new \InvalidArgumentException('Invalid argument provided');
        $record = $this->createLogRecord(
            Level::Error,
            'Error occurred',
            ['exception' => $exception]
        );

        $this->apiClient->expects($this->once())
            ->method('sendError')
            ->with($this->callback(function (array $payload) use ($exception) {
                // Should extract exception details
                $this->assertEquals('InvalidArgumentException', $payload['type']);
                $this->assertEquals('Error occurred', $payload['message']);
                $this->assertEquals($exception->getFile(), $payload['file']);
                $this->assertEquals($exception->getLine(), $payload['line']);
                $this->assertIsArray($payload['stack_trace']);
                $this->assertNotEmpty($payload['stack_trace']);

                return true;
            }));

        $this->handler->handle($record);
    }

    #[DataProvider('levelMappingProvider')]
    public function testLevelMapping(Level $monologLevel, string $expectedLevel): void
    {
        // Create a handler with 'debug' capture level to test all level mappings
        $apiClient = $this->createMock(ApiClient::class);
        $handler = new ApplicationLoggerHandler(
            $apiClient,
            $this->contextCollector,
            $this->breadcrumbCollector,
            captureLevel: 'debug' // Capture all levels for testing
        );

        $record = $this->createLogRecord($monologLevel, 'Test message');

        $apiClient->expects($this->once())
            ->method('sendError')
            ->with($this->callback(function (array $payload) use ($expectedLevel) {
                $this->assertEquals($expectedLevel, $payload['level']);

                return true;
            }));

        $handler->handle($record);
    }

    /**
     * @return array<string, array{Level, string}>
     */
    public static function levelMappingProvider(): array
    {
        return [
            'debug' => [Level::Debug, 'debug'],
            'info' => [Level::Info, 'info'],
            'notice' => [Level::Notice, 'info'],
            'warning' => [Level::Warning, 'warning'],
            'error' => [Level::Error, 'error'],
            'critical' => [Level::Critical, 'fatal'],  // critical -> fatal
            'alert' => [Level::Alert, 'fatal'],
            'emergency' => [Level::Emergency, 'fatal'],
        ];
    }

    public function testStackTraceIsFlatArray(): void
    {
        $exception = new \RuntimeException('Test exception');
        $record = $this->createLogRecord(
            Level::Error,
            'Error with exception',
            ['exception' => $exception]
        );

        $this->apiClient->expects($this->once())
            ->method('sendError')
            ->with($this->callback(function (array $payload) {
                $stackTrace = $payload['stack_trace'];

                // Stack trace should be a flat array, not wrapped in 'frames'
                $this->assertIsArray($stackTrace);

                // Should not have 'frames' key (was the bug)
                $this->assertArrayNotHasKey('frames', $stackTrace);

                // Each frame should have correct structure
                if (\count($stackTrace) > 0) {
                    $frame = $stackTrace[0];
                    $this->assertArrayHasKey('file', $frame);
                    $this->assertArrayHasKey('line', $frame);
                    $this->assertArrayHasKey('function', $frame);
                    $this->assertArrayHasKey('in_app', $frame);
                }

                return true;
            }));

        $this->handler->handle($record);
    }

    public function testPayloadIncludesContextData(): void
    {
        $record = $this->createLogRecord(Level::Error, 'Test message');

        $this->apiClient->expects($this->once())
            ->method('sendError')
            ->with($this->callback(function (array $payload) {
                // Context data should be included
                $this->assertEquals('test', $payload['environment']);
                $this->assertEquals('1.0.0', $payload['release']);
                $this->assertEquals('test-server', $payload['server_name']);
                $this->assertEquals('https://example.com/test', $payload['url']);
                $this->assertEquals('POST', $payload['http_method']);
                $this->assertEquals('10.0.0.1', $payload['ip_address']);
                $this->assertEquals('Test Agent', $payload['user_agent']);

                return true;
            }));

        $this->handler->handle($record);
    }

    public function testPayloadIncludesSourceAsBackend(): void
    {
        $record = $this->createLogRecord(Level::Error, 'Test message');

        $this->apiClient->expects($this->once())
            ->method('sendError')
            ->with($this->callback(function (array $payload) {
                $this->assertEquals('backend', $payload['source']);

                return true;
            }));

        $this->handler->handle($record);
    }

    public function testPayloadIncludesRuntime(): void
    {
        $record = $this->createLogRecord(Level::Error, 'Test message');

        $this->apiClient->expects($this->once())
            ->method('sendError')
            ->with($this->callback(function (array $payload) {
                $this->assertArrayHasKey('runtime', $payload);
                $this->assertStringStartsWith('PHP ', $payload['runtime']);

                return true;
            }));

        $this->handler->handle($record);
    }

    public function testScrubsSensitiveDataFromContext(): void
    {
        $record = $this->createLogRecord(
            Level::Error,
            'Test message',
            [
                'password' => 'secret123',
                'api_key' => 'key123',
                'user_token' => 'token456',
                'safe_data' => 'visible',
            ]
        );

        $this->apiClient->expects($this->once())
            ->method('sendError')
            ->with($this->callback(function (array $payload) {
                $context = $payload['context'];

                // Sensitive fields should be scrubbed
                $this->assertEquals('[REDACTED]', $context['password']);
                $this->assertEquals('[REDACTED]', $context['api_key']);
                $this->assertEquals('[REDACTED]', $context['user_token']);

                // Safe data should remain
                $this->assertEquals('visible', $context['safe_data']);

                // Exception should be removed from context
                $this->assertArrayNotHasKey('exception', $context);

                return true;
            }));

        $this->handler->handle($record);
    }

    public function testResilienceOnApiFailure(): void
    {
        $record = $this->createLogRecord(Level::Error, 'Test message');

        // Simulate API failure
        $this->apiClient->method('sendError')
            ->willThrowException(new \RuntimeException('API unavailable'));

        // Should not throw - handler should be resilient
        $this->handler->handle($record);

        // If we reach here, the test passes
        $this->addToAssertionCount(1);
    }

    public function testIncludesTagsWithChannelAndLevel(): void
    {
        $record = $this->createLogRecord(Level::Error, 'Test error');

        $this->apiClient->expects($this->once())
            ->method('sendError')
            ->with($this->callback(function (array $payload) {
                $this->assertArrayHasKey('tags', $payload);
                $this->assertEquals('test', $payload['tags']['channel']);
                $this->assertEquals('Error', $payload['tags']['monolog_level']);

                return true;
            }));

        $this->handler->handle($record);
    }

    public function testIncludesTimestamp(): void
    {
        $record = $this->createLogRecord(Level::Error, 'Test message');

        $this->apiClient->expects($this->once())
            ->method('sendError')
            ->with($this->callback(function (array $payload) {
                $this->assertArrayHasKey('timestamp', $payload);
                // Timestamp should be ISO 8601 format
                $this->assertMatchesRegularExpression(
                    '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/',
                    $payload['timestamp']
                );

                return true;
            }));

        $this->handler->handle($record);
    }

    public function testInAppFlagDistinguishesVendorCode(): void
    {
        // Create exception with trace that includes vendor paths
        $exception = new \RuntimeException('Test');
        $record = $this->createLogRecord(
            Level::Error,
            'Error',
            ['exception' => $exception]
        );

        $this->apiClient->expects($this->once())
            ->method('sendError')
            ->with($this->callback(function (array $payload) {
                foreach ($payload['stack_trace'] as $frame) {
                    $this->assertArrayHasKey('in_app', $frame);
                    $this->assertIsBool($frame['in_app']);

                    // If file contains /vendor/, in_app should be false
                    if (str_contains($frame['file'] ?? '', '/vendor/')) {
                        $this->assertFalse($frame['in_app']);
                    }
                }

                return true;
            }));

        $this->handler->handle($record);
    }
}
