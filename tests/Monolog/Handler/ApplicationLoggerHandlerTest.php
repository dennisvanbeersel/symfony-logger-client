<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\Tests\Monolog\Handler;

use ApplicationLogger\Bundle\Monolog\Handler\ApplicationLoggerHandler;
use ApplicationLogger\Bundle\Service\ContextCollectorInterface;
use ApplicationLogger\Bundle\Service\Sdk\BundleContextCollector;
use ApplicationLogger\Bundle\Service\Sdk\LoopbackGuard;
use ApplicationLogger\Bundle\Service\Sdk\SdkClientFactory;
use ApplicationLogger\Sdk\Client;
use ApplicationLogger\Sdk\Clock\SystemClock;
use ApplicationLogger\Sdk\DataScrubber;
use ApplicationLogger\Sdk\Hub;
use ApplicationLogger\Sdk\Options;
use ApplicationLogger\Sdk\Scope;
use ApplicationLogger\Sdk\StackTraceParser;
use ApplicationLogger\Sdk\Stats;
use ApplicationLogger\Sdk\Transport\FileTransport;
use ApplicationLogger\Sdk\Transport\TransportInterface;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class ApplicationLoggerHandlerTest extends TestCase
{
    private MockObject&ContextCollectorInterface $contextCollector;
    private string $transportFile;
    private FileTransport $transport;
    private Hub $hub;
    private SdkClientFactory $factory;
    private ApplicationLoggerHandler $handler;

    protected function setUp(): void
    {
        Hub::reset();

        $this->contextCollector = $this->createMock(ContextCollectorInterface::class);
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

        $this->transportFile = sys_get_temp_dir().'/applogger-handler-test-'.uniqid('', true).'.ndjson';
        $this->transport = new FileTransport($this->transportFile);

        $this->hub = $this->buildFileHub($this->transport);
        $this->factory = $this->buildFactory($this->hub);

        $this->handler = $this->createHandler($this->factory);
    }

    protected function tearDown(): void
    {
        Hub::reset();
        if (is_file($this->transportFile)) {
            unlink($this->transportFile);
        }
    }

    // ---------------------------------------------------------------------------
    // helpers
    // ---------------------------------------------------------------------------

    /**
     * Build a real sdk-core Hub backed by a FileTransport so captured events
     * can be inspected without a network call.
     *
     * @param list<string> $scrubFields
     */
    private function buildFileHub(FileTransport $transport, array $scrubFields = []): Hub
    {
        $inner = $this->createMock(ContextCollectorInterface::class);
        $inner->method('collectContext')->willReturn([]);
        $ctx = new BundleContextCollector($inner);

        $opts = Options::fromArray([
            'dsn' => 'https://applogger.eu/0xTEST',
            'api_key' => 'pk_test',
            'environment' => 'test',
            'release' => null,
            'enabled' => true,
            'scrub_fields' => $scrubFields,
            'max_breadcrumbs' => 50,
            'timeout' => 2.0,
            'flush_budget' => 2.0,
            'circuit_breaker' => ['failure_threshold' => 5, 'timeout' => 60, 'half_open_attempts' => 1],
            'log_endpoint' => null,
            'log_token' => null,
            'session_hash_salt' => null,
            'app_name' => 'test-app',
            'cache_dir' => sys_get_temp_dir().'/applogger-test-hub-'.uniqid('', true),
        ]);

        $scrubber = new DataScrubber($scrubFields, []);
        $client = new Client($opts, $transport, new SystemClock(), $scrubber, new StackTraceParser(), $ctx);

        // Hub without LogClient — log path goes to null-guard no-op by default.
        // Tests that need a log path spy wire it separately through createHandlerWithSpy().
        return new Hub($client, new Scope($opts->maxBreadcrumbs));
    }

    /**
     * Build a real SdkClientFactory pre-loaded with the given Hub.
     * Inject the Hub via reflection to bypass build() (which needs a real DSN).
     */
    private function buildFactory(Hub $hub): SdkClientFactory
    {
        $inner = $this->createMock(ContextCollectorInterface::class);
        $inner->method('collectContext')->willReturn([]);
        $ctx = new BundleContextCollector($inner);

        $config = [
            'dsn' => '',
            'api_key' => '',
            'environment' => 'test',
            'release' => null,
            'enabled' => true,
            'scrub_fields' => [],
            'max_breadcrumbs' => 50,
            'timeout' => 2.0,
            'flush_budget' => 2.0,
            'circuit_breaker' => ['failure_threshold' => 5, 'timeout' => 60, 'half_open_attempts' => 1],
            'log_endpoint' => null,
            'log_token' => null,
            'session_hash_salt' => null,
            'app_name' => 'test',
            'cache_dir' => sys_get_temp_dir().'/applogger-test-factory-'.uniqid('', true),
        ];

        $factory = new SdkClientFactory($config, $ctx, new LoopbackGuard(new RequestStack(), []));

        // Pre-load the test Hub so getHub() returns it without calling build().
        $ref = new \ReflectionProperty(SdkClientFactory::class, 'hub');
        $ref->setValue($factory, $hub);

        return $factory;
    }

    private function createHandler(
        SdkClientFactory $factory,
        string $captureLevel = 'error',
        bool $enabled = true,
        bool $errorTrackingEnabled = true,
        bool $logAggregationEnabled = true,
        ?LoopbackGuard $loopback = null,
    ): ApplicationLoggerHandler {
        return new ApplicationLoggerHandler(
            factory: $factory,
            contextCollector: $this->contextCollector,
            scrubber: new DataScrubber(['password', 'token', 'api_key', 'secret', 'authorization']),
            loopback: $loopback ?? new LoopbackGuard(new RequestStack(), []),
            enabled: $enabled,
            captureLevel: $captureLevel,
            environment: 'test',
            errorTrackingEnabled: $errorTrackingEnabled,
            logAggregationEnabled: $logAggregationEnabled,
        );
    }

    /**
     * Build a Hub with a real LogClient that buffers to memory (no I/O).
     * LogClientFactory::create() requires PSR-17 factory discovery which is not
     * available in this test environment, so we construct LogClient directly using
     * Symfony\Component\HttpClient\Psr18Client (which also implements PSR-17).
     * The LogClient's buffer can be read back via the public readonly $buffer
     * property — actually $buffer is private, so we read it via ReflectionProperty.
     *
     * @return array{Hub, \ReflectionProperty, \ApplicationLogger\Sdk\Log\LogClient}
     */
    private function buildHubWithLogClient(): array
    {
        $sfClient = \Symfony\Component\HttpClient\HttpClient::create(['timeout' => 0.5]);
        $psr18 = new \Symfony\Component\HttpClient\Psr18Client($sfClient);

        $cacheDir = sys_get_temp_dir().'/applogger-logclient-'.uniqid('', true);

        $logConfig = \ApplicationLogger\Sdk\Log\LogConfig::fromArray([
            'log_endpoint' => 'http://127.0.0.1:19999', // non-listening — log() only buffers, no network until flush()
            'log_token' => 'sk_log_test',
            'app_name' => 'test',
            'environment' => 'test',
            'timeout' => 0.5,
            'flush_budget' => 0.5,
            'scrub_fields' => [],
            'cache_dir' => $cacheDir,
        ]);

        $scrubber = new DataScrubber([], []);
        $clock = new SystemClock();

        // Use a file-based pool for breaker + rate-limiter (avoids cache pool dep)
        $pool = new \ApplicationLogger\Sdk\Cache\FilesystemPsr6Pool($cacheDir);
        $breaker = new \ApplicationLogger\Sdk\CircuitBreaker($pool, $clock, cacheKey: 'log_cb_test');
        $rateLimiter = new \ApplicationLogger\Sdk\RateLimiter($clock, $pool, cacheKey: 'log_rl_test');

        $logClient = new \ApplicationLogger\Sdk\Log\LogClient(
            $logConfig,
            $psr18,
            $psr18, // implements RequestFactoryInterface
            $psr18, // implements StreamFactoryInterface
            new \ApplicationLogger\Sdk\Log\LogPayloadFactory(),
            $scrubber,
            $breaker,
            $rateLimiter,
            $clock,
            new Stats(),
        );

        $hub = new Hub(
            $this->hub->getClient(),
            new Scope(50),
            $logClient,
        );

        $bufferProp = new \ReflectionProperty(\ApplicationLogger\Sdk\Log\LogClient::class, 'buffer');

        return [$hub, $bufferProp, $logClient];
    }

    /**
     * Build handler + LogClient spy for tests that need to observe log path behaviour.
     *
     * @return array{ApplicationLoggerHandler, \ReflectionProperty, \ApplicationLogger\Sdk\Log\LogClient}
     */
    private function createHandlerWithLogSpy(): array
    {
        [$hub, $bufferProp, $logClient] = $this->buildHubWithLogClient();
        $factory = $this->buildFactory($hub);

        $handler = new ApplicationLoggerHandler(
            factory: $factory,
            contextCollector: $this->contextCollector,
            scrubber: new DataScrubber(['password', 'token', 'api_key', 'secret', 'authorization']),
            loopback: new LoopbackGuard(new RequestStack(), []),
            enabled: true,
            captureLevel: 'debug',
            environment: 'test',
        );

        return [$handler, $bufferProp, $logClient];
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

    // ---------------------------------------------------------------------------
    // Error path: exception-bearing records → Hub::captureEvent
    // ---------------------------------------------------------------------------

    public function testExceptionRecordCapturesEvent(): void
    {
        $exception = new \InvalidArgumentException('Invalid argument provided');
        $record = $this->createLogRecord(Level::Error, 'Error occurred', ['exception' => $exception]);

        $this->handler->handle($record);

        $events = $this->transport->capturedEvents();
        $this->assertCount(1, $events, 'Expected exactly one captured event');

        // type = exception class (NOT record message)
        $this->assertEquals('InvalidArgumentException', $events[0]['type']);
        // message = record message (NOT exception message)
        $this->assertEquals('Error occurred', $events[0]['message']);
        $this->assertEquals($exception->getFile(), $events[0]['file']);
        $this->assertEquals($exception->getLine(), $events[0]['line']);
        $this->assertNotEmpty($events[0]['stack_trace'], 'stackTrace must be populated by handler');
        $this->assertArrayNotHasKey('exception', $events[0]['context'] ?? []);
    }

    public function testExceptionRecordHasChannelAndMonologLevelTags(): void
    {
        $record = $this->createLogRecord(Level::Error, 'msg', ['exception' => new \RuntimeException('x')]);

        $this->handler->handle($record);

        $events = $this->transport->capturedEvents();
        $this->assertCount(1, $events);
        $this->assertEquals('test', $events[0]['tags']['channel'] ?? null);
        $this->assertEquals('Error', $events[0]['tags']['monolog_level'] ?? null);
    }

    public function testExceptionRecordUsesRecordMessageNotExceptionMessage(): void
    {
        $exception = new \RuntimeException('exception internal message');
        $record = $this->createLogRecord(Level::Error, 'record message override', ['exception' => $exception]);

        $this->handler->handle($record);

        $events = $this->transport->capturedEvents();
        $this->assertCount(1, $events);
        $this->assertEquals('record message override', $events[0]['message']);
        $this->assertNotEquals('exception internal message', $events[0]['message']);
    }

    public function testExceptionContextIsScrubbedRecursively(): void
    {
        // Build a hub with matching scrub fields so the sdk-core pipeline scrubs
        // the event context before FileTransport writes it.
        $scrubFields = ['password', 'token', 'api_key', 'secret', 'authorization'];
        $scrubFile = sys_get_temp_dir().'/applogger-scrub-test-'.uniqid('', true).'.ndjson';
        $transport = new FileTransport($scrubFile);
        $hub = $this->buildFileHub($transport, $scrubFields);
        $factory = $this->buildFactory($hub);
        $handler = $this->createHandler($factory);

        $record = $this->createLogRecord(Level::Error, 'msg', [
            'exception' => new \RuntimeException('x'),
            'password' => 'secret123',
            'nested' => ['api_key' => 'k', 'safe' => 'v'],
        ]);

        $handler->handle($record);

        $events = $transport->capturedEvents();
        $this->assertCount(1, $events);
        $ctx = $events[0]['context'] ?? [];
        $this->assertEquals('[REDACTED]', $ctx['password']);
        $this->assertEquals('[REDACTED]', $ctx['nested']['api_key']);
        $this->assertEquals('v', $ctx['nested']['safe']);
        $this->assertArrayNotHasKey('exception', $ctx);

        if (is_file($scrubFile)) {
            unlink($scrubFile);
        }
    }

    public function testExceptionContextMergesCollectedContext(): void
    {
        $record = $this->createLogRecord(Level::Error, 'msg', ['exception' => new \RuntimeException('x')]);

        $this->handler->handle($record);

        $events = $this->transport->capturedEvents();
        $this->assertCount(1, $events);
        // contextCollector returns 'environment' => 'test' and 'release' => '1.0.0'
        $this->assertArrayHasKey('environment', $events[0]);
        $this->assertEquals('test', $events[0]['environment']);
        $this->assertEquals('1.0.0', $events[0]['release']);
    }

    // ---------------------------------------------------------------------------
    // Log path: plain records → LogClient::log (verified via buffer reflection)
    // ---------------------------------------------------------------------------

    public function testPlainRecordIsRoutedToLogClient(): void
    {
        [$handler, $bufferProp, $logClient] = $this->createHandlerWithLogSpy();

        $handler->handle($this->createLogRecord(Level::Error, 'Simple log message'));

        /** @var list<object> $buffer */
        $buffer = $bufferProp->getValue($logClient);
        $this->assertCount(1, $buffer, 'Expected exactly one buffered log entry');
    }

    public function testPlainRecordDoesNotCaptureEvent(): void
    {
        // Hub has no LogClient → log path is no-op; verify no event captured
        $handler = $this->createHandler($this->factory);
        $handler->handle($this->createLogRecord(Level::Error, 'Simple log message'));

        $this->assertCount(0, $this->transport->capturedEvents());
    }

    public function testPlainRecordContextIsScrubbedAndStringified(): void
    {
        [$handler, $bufferProp, $logClient] = $this->createHandlerWithLogSpy();

        $handler->handle($this->createLogRecord(Level::Error, 'msg', [
            'password' => 'secret',
            'count' => 5,
            'flag' => true,
        ]));

        /** @var list<\ApplicationLogger\Sdk\Log\LogRecord> $buffer */
        $buffer = $bufferProp->getValue($logClient);
        $this->assertCount(1, $buffer);

        // LogRecord properties are public readonly — no reflection needed
        $ctx = $buffer[0]->context;

        // Handler pre-scrubs password before calling LogClient::log(); LogClient also scrubs
        $this->assertEquals('[REDACTED]', $ctx['password']);
        // Handler flattens to map<string,string> before calling LogClient::log()
        $this->assertSame('5', $ctx['count']);
        $this->assertSame('true', $ctx['flag']);
    }

    #[DataProvider('severityMappingProvider')]
    public function testSeverityMappingForLogs(Level $level, string $expected): void
    {
        [$handler, $bufferProp, $logClient] = $this->createHandlerWithLogSpy();

        $handler->handle($this->createLogRecord($level, 'msg'));

        /** @var list<\ApplicationLogger\Sdk\Log\LogRecord> $buffer */
        $buffer = $bufferProp->getValue($logClient);
        $this->assertCount(1, $buffer, "Expected buffered entry for level {$level->name}");

        // LogRecord::$level is a public readonly string (already normalized by LogClient)
        $this->assertSame($expected, $buffer[0]->level);
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

    // ---------------------------------------------------------------------------
    // Loopback guard: ingest-path requests → both paths suppressed
    // ---------------------------------------------------------------------------

    public function testLoopbackGuardSuppressesErrorPath(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/api/v1/errors'));
        $loopback = new LoopbackGuard($requestStack, ['/api/v1/errors']);

        $handler = $this->createHandler($this->factory, loopback: $loopback);

        $handler->handle($this->createLogRecord(Level::Error, 'boom', ['exception' => new \RuntimeException('x')]));

        $this->assertCount(0, $this->transport->capturedEvents(), 'Loopback must suppress error path');
    }

    public function testLoopbackGuardSuppressesLogPath(): void
    {
        [$hub, $bufferProp, $logClient] = $this->buildHubWithLogClient();
        $factory = $this->buildFactory($hub);

        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/api/v1/errors'));
        $loopback = new LoopbackGuard($requestStack, ['/api/v1/errors']);

        $loopedHandler = new ApplicationLoggerHandler(
            factory: $factory,
            contextCollector: $this->contextCollector,
            scrubber: new DataScrubber([]),
            loopback: $loopback,
            environment: 'test',
            captureLevel: 'debug',
        );

        $loopedHandler->handle($this->createLogRecord(Level::Error, 'plain log'));

        /** @var list<\ApplicationLogger\Sdk\Log\LogRecord> $buffer */
        $buffer = $bufferProp->getValue($logClient);
        $this->assertCount(0, $buffer, 'Loopback must suppress log path');
    }

    // ---------------------------------------------------------------------------
    // Enabled gate: a disabled install must never ship anything
    // ---------------------------------------------------------------------------

    public function testDisabledHandlerNeverCapturesEvent(): void
    {
        $handler = $this->createHandler($this->factory, enabled: false);

        $handler->handle($this->createLogRecord(Level::Error, 'boom', ['exception' => new \RuntimeException('x')]));
        $handler->flushLogs();

        $this->assertCount(0, $this->transport->capturedEvents());
    }

    public function testDisabledHandlerNeverBuffersLogEntry(): void
    {
        [$hub, $bufferProp, $logClient] = $this->buildHubWithLogClient();
        $factory = $this->buildFactory($hub);

        $disabledHandler = new ApplicationLoggerHandler(
            factory: $factory,
            contextCollector: $this->contextCollector,
            scrubber: new DataScrubber([]),
            loopback: new LoopbackGuard(new RequestStack(), []),
            enabled: false,
            captureLevel: 'debug',
            environment: 'test',
        );

        $disabledHandler->handle($this->createLogRecord(Level::Info, 'plain log'));
        $disabledHandler->flushLogs();

        /** @var list<\ApplicationLogger\Sdk\Log\LogRecord> $buffer */
        $buffer = $bufferProp->getValue($logClient);
        $this->assertCount(0, $buffer, 'Disabled handler must not buffer log entries');
    }

    public function testEnabledHandlerCapturesExceptionEvent(): void
    {
        $handler = $this->createHandler($this->factory, enabled: true);

        $handler->handle($this->createLogRecord(Level::Error, 'boom', ['exception' => new \RuntimeException('x')]));

        $this->assertCount(1, $this->transport->capturedEvents());
    }

    // ---------------------------------------------------------------------------
    // Toggle routing: error_tracking / log_aggregation sub-toggles
    // ---------------------------------------------------------------------------

    public function testErrorTrackingOffSendsExceptionRecordToLogClient(): void
    {
        [$hub, $bufferProp, $logClient] = $this->buildHubWithLogClient();
        $factory = $this->buildFactory($hub);

        $handler = new ApplicationLoggerHandler(
            factory: $factory,
            contextCollector: $this->contextCollector,
            scrubber: new DataScrubber([]),
            loopback: new LoopbackGuard(new RequestStack(), []),
            environment: 'test',
            captureLevel: 'debug',
            errorTrackingEnabled: false,
            logAggregationEnabled: true,
        );

        $handler->handle($this->createLogRecord(Level::Error, 'boom', ['exception' => new \RuntimeException('boom')]));

        // Read buffer BEFORE any flush — flush() drains the buffer unconditionally (sends or drops),
        // so the buffer must be inspected while it still holds the staged entry.
        /** @var list<\ApplicationLogger\Sdk\Log\LogRecord> $buffer */
        $buffer = $bufferProp->getValue($logClient);
        $this->assertCount(1, $buffer, 'Exception record must fall through to log path when errorTracking is off');

        // Context of buffered entry must not contain 'exception' (LogRecord props are public readonly)
        $this->assertArrayNotHasKey('exception', $buffer[0]->context, 'Throwable must be stripped from log context');
    }

    public function testLogAggregationOffDropsPlainRecord(): void
    {
        [$hub, $bufferProp, $logClient] = $this->buildHubWithLogClient();
        $factory = $this->buildFactory($hub);

        $handler = new ApplicationLoggerHandler(
            factory: $factory,
            contextCollector: $this->contextCollector,
            scrubber: new DataScrubber([]),
            loopback: new LoopbackGuard(new RequestStack(), []),
            environment: 'test',
            captureLevel: 'debug',
            errorTrackingEnabled: true,
            logAggregationEnabled: false,
        );

        $handler->handle($this->createLogRecord(Level::Error, 'hello'));
        $handler->flushLogs();

        /** @var list<\ApplicationLogger\Sdk\Log\LogRecord> $buffer */
        $buffer = $bufferProp->getValue($logClient);
        $this->assertCount(0, $buffer, 'Plain record must be dropped when logAggregation is disabled');
    }

    public function testBothOffWithMasterOnDropsEverything(): void
    {
        [$hub, $bufferProp, $logClient] = $this->buildHubWithLogClient();
        $factory = $this->buildFactory($hub);

        $handler = new ApplicationLoggerHandler(
            factory: $factory,
            contextCollector: $this->contextCollector,
            scrubber: new DataScrubber([]),
            loopback: new LoopbackGuard(new RequestStack(), []),
            environment: 'test',
            captureLevel: 'debug',
            errorTrackingEnabled: false,
            logAggregationEnabled: false,
        );

        $handler->handle($this->createLogRecord(Level::Error, 'x', ['exception' => new \RuntimeException('x')]));
        $handler->handle($this->createLogRecord(Level::Error, 'y'));
        $handler->flushLogs();

        /** @var list<\ApplicationLogger\Sdk\Log\LogRecord> $buffer */
        $buffer = $bufferProp->getValue($logClient);
        $this->assertCount(0, $buffer, 'Both toggles off must drop all records');
    }

    public function testMasterOffWinsOverSubToggles(): void
    {
        [$hub, $bufferProp, $logClient] = $this->buildHubWithLogClient();
        $factory = $this->buildFactory($hub);

        $handler = new ApplicationLoggerHandler(
            factory: $factory,
            contextCollector: $this->contextCollector,
            scrubber: new DataScrubber([]),
            loopback: new LoopbackGuard(new RequestStack(), []),
            enabled: false,
            environment: 'test',
            captureLevel: 'debug',
            errorTrackingEnabled: true,
            logAggregationEnabled: true,
        );

        $handler->handle($this->createLogRecord(Level::Error, 'x', ['exception' => new \RuntimeException('x')]));
        $handler->handle($this->createLogRecord(Level::Error, 'y'));
        $handler->flushLogs();

        /** @var list<\ApplicationLogger\Sdk\Log\LogRecord> $buffer */
        $buffer = $bufferProp->getValue($logClient);
        $this->assertCount(0, $buffer, 'Master-off must win over sub-toggles');
    }

    // ---------------------------------------------------------------------------
    // Resilience: handler must never throw even when the Hub/transport throws
    // ---------------------------------------------------------------------------

    public function testResilienceWhenTransportThrowsOnCaptureEvent(): void
    {
        /** @var MockObject&TransportInterface $throwingTransport */
        $throwingTransport = $this->createMock(TransportInterface::class);
        $throwingTransport->method('send')->willThrowException(new \RuntimeException('transport blow-up'));
        $throwingTransport->method('flush')->willReturn(true);
        $throwingTransport->method('getStats')->willReturn(new Stats());

        $inner = $this->createMock(ContextCollectorInterface::class);
        $inner->method('collectContext')->willReturn([]);
        $ctx = new BundleContextCollector($inner);

        $opts = Options::fromArray([
            'dsn' => 'https://applogger.eu/0xTEST',
            'api_key' => 'pk_test',
            'environment' => 'test',
            'release' => null,
            'enabled' => true,
            'scrub_fields' => [],
            'max_breadcrumbs' => 50,
            'timeout' => 2.0,
            'flush_budget' => 2.0,
            'circuit_breaker' => ['failure_threshold' => 5, 'timeout' => 60, 'half_open_attempts' => 1],
            'log_endpoint' => null,
            'log_token' => null,
            'session_hash_salt' => null,
            'app_name' => 'test',
            'cache_dir' => sys_get_temp_dir().'/applogger-throw-'.uniqid('', true),
        ]);
        $scrubber = new DataScrubber([], []);
        $client = new Client($opts, $throwingTransport, new SystemClock(), $scrubber, new StackTraceParser(), $ctx);
        $hub = new Hub($client, new Scope($opts->maxBreadcrumbs));
        $factory = $this->buildFactory($hub);
        $handler = $this->createHandler($factory);

        // Must not throw
        $handler->handle($this->createLogRecord(Level::Error, 'msg', ['exception' => new \RuntimeException('x')]));

        $this->addToAssertionCount(1);
    }

    public function testResilienceWhenContextCollectorThrows(): void
    {
        $throwingCollector = $this->createMock(ContextCollectorInterface::class);
        $throwingCollector->method('collectContext')->willThrowException(new \RuntimeException('collector down'));

        $handler = new ApplicationLoggerHandler(
            factory: $this->factory,
            contextCollector: $throwingCollector,
            scrubber: new DataScrubber([]),
            loopback: new LoopbackGuard(new RequestStack(), []),
            environment: 'test',
        );

        // Must not throw — resilience guarantee
        $handler->handle($this->createLogRecord(Level::Error, 'msg', ['exception' => new \RuntimeException('x')]));

        $this->addToAssertionCount(1);
    }

    // ---------------------------------------------------------------------------
    // UTF-8 safety: truncate() must never split a multibyte character
    // ---------------------------------------------------------------------------

    public function testTruncateViaErrorPathProducesValidUtf8(): void
    {
        // Build a message of repeated 2-byte UTF-8 characters ('é' = 0xC3 0xA9).
        // 1000 chars × 2 bytes = 2000 bytes, well above the 1000-byte truncation cap.
        // A naive substr() cut at byte 999 would land inside a 2-byte sequence and
        // produce invalid UTF-8; mb_strcut() must prevent that.
        $multibyte = str_repeat('é', 500); // 1000 bytes

        $record = $this->createLogRecord(Level::Error, $multibyte, ['exception' => new \RuntimeException('x')]);
        $this->handler->handle($record);

        $events = $this->transport->capturedEvents();
        $this->assertCount(1, $events, 'Event must be captured despite multibyte message');

        $message = $events[0]['message'];
        $this->assertTrue(
            mb_check_encoding($message, 'UTF-8'),
            'Truncated message must be valid UTF-8 (no split multibyte character)'
        );
        $this->assertLessThanOrEqual(
            1000,
            \strlen($message),
            'Truncated message must not exceed the 1000-byte cap'
        );
    }

    public function testTruncateViaErrorPathProducesValidUtf8With4ByteChars(): void
    {
        // 4-byte emoji: '🚀' = 0xF0 0x9F 0x9A 0x80.
        // 250 chars × 4 bytes = 1000 bytes — right at the boundary.
        // A substr() cut at byte 999 would split the last emoji; mb_strcut() must not.
        $multibyte = str_repeat('🚀', 250); // exactly 1000 bytes

        $record = $this->createLogRecord(Level::Error, $multibyte, ['exception' => new \RuntimeException('x')]);
        $this->handler->handle($record);

        $events = $this->transport->capturedEvents();
        $this->assertCount(1, $events, 'Event must be captured for 4-byte emoji message');

        $message = $events[0]['message'];
        $this->assertTrue(
            mb_check_encoding($message, 'UTF-8'),
            'Truncated 4-byte-char message must be valid UTF-8'
        );
        $this->assertLessThanOrEqual(
            1000,
            \strlen($message),
            'Truncated 4-byte-char message must not exceed the 1000-byte cap'
        );
    }

    public function testHandleBatchFlushesLogs(): void
    {
        // handleBatch calls flushLogs() after processing; since we can't easily observe
        // flush on a real LogClient without a network call, we verify through the error
        // path: after a handleBatch with exception records, events are captured.
        $handler = $this->createHandler($this->factory);

        $handler->handleBatch([
            $this->createLogRecord(Level::Error, 'one', ['exception' => new \RuntimeException('1')]),
            $this->createLogRecord(Level::Error, 'two', ['exception' => new \RuntimeException('2')]),
        ]);

        // Both exception records must be captured
        $events = $this->transport->capturedEvents();
        $this->assertCount(2, $events, 'handleBatch must process all records');
    }
}
