<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\Tests\Twig;

use ApplicationLogger\Bundle\Twig\ApplicationLoggerExtension;
use ApplicationLogger\Bundle\Twig\CspNonceProvider;
use ApplicationLogger\Bundle\Twig\ScriptRenderer;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Unit tests for ApplicationLoggerExtension (Twig).
 *
 * Tests the JavaScript SDK initialization script generation including:
 * - Default configuration rendering
 * - Custom options override
 * - User context extraction
 * - Disabled state handling
 * - JSON encoding safety
 */
class ApplicationLoggerExtensionTest extends TestCase
{
    public function testGetFunctionsReturnsApplicationLoggerInit(): void
    {
        $config = $this->getDefaultConfig();
        $extension = $this->makeExtension($config);

        $functions = $extension->getFunctions();

        $this->assertCount(1, $functions);
        $this->assertSame('application_logger_init', $functions[0]->getName());
    }

    public function testGetGlobalsContainsPublishableKey(): void
    {
        $config = $this->getDefaultConfig();
        $extension = $this->makeExtension($config);

        $globals = $extension->getGlobals();

        $this->assertArrayHasKey('app_logger_publishable_key', $globals);
        $this->assertSame('pk_test_publishable', $globals['app_logger_publishable_key']);
    }

    public function testGetGlobalsPublishableKeyDefaultsToEmptyString(): void
    {
        $config = $this->getDefaultConfig();
        unset($config['publishable_key']);
        $extension = $this->makeExtension($config);

        $globals = $extension->getGlobals();

        $this->assertArrayHasKey('app_logger_publishable_key', $globals);
        $this->assertSame('', $globals['app_logger_publishable_key']);
    }

    public function testRenderInitReturnsEmptyStringWhenDisabled(): void
    {
        $config = $this->getDefaultConfig();
        $config['enabled'] = false;

        $extension = $this->makeExtension($config);
        $output = $extension->renderInit();

        $this->assertSame('', $output);
    }

    public function testRenderInitGeneratesInitializationScript(): void
    {
        $config = $this->getDefaultConfig();
        $extension = $this->makeExtension($config);

        $output = $extension->renderInit();

        // Should contain script tag
        $this->assertStringContainsString('<script type="module">', $output);

        // Should import from @application-logger/logger
        $this->assertStringContainsString('import ApplicationLogger from \'@application-logger/logger\';', $output);

        // Should contain DSN
        $this->assertStringContainsString('"dsn":"https://test-host.com/test-project"', $output);

        // MF-7: the secret apiKey must NEVER appear in the browser config; the
        // world-readable publishableKey must.
        $this->assertStringContainsString('"publishableKey":"pk_test_publishable"', $output);
        $this->assertStringNotContainsString('"apiKey"', $output);
        // Regression guard: if api_key were ever re-added to getDefaultConfig(), this would catch it.
        $this->assertStringNotContainsString('test-api-key', $output);

        // Should contain environment
        $this->assertStringContainsString('"environment":"test"', $output);

        // Should initialize logger
        $this->assertStringContainsString('logger.init();', $output);

        // Should make logger globally available
        $this->assertStringContainsString('window.appLogger = logger;', $output);
    }

    public function testRenderInitIncludesConfiguredScrubFields(): void
    {
        $config = $this->getDefaultConfig();
        $config['scrub_fields'] = ['password', 'token', 'custom_field'];

        $extension = $this->makeExtension($config);
        $output = $extension->renderInit();

        $this->assertStringContainsString('"scrubFields":["password","token","custom_field"]', $output);
    }

    public function testRenderInitOmitsNullValues(): void
    {
        $config = $this->getDefaultConfig();
        $config['release'] = null;

        $extension = $this->makeExtension($config);
        $output = $extension->renderInit();

        // Should not contain release property
        $this->assertStringNotContainsString('"release"', $output);
    }

    public function testRenderInitMergesCustomOptions(): void
    {
        $config = $this->getDefaultConfig();
        $extension = $this->makeExtension($config);

        $customOptions = [
            'release' => 'v2.0.0',
            'environment' => 'staging',
        ];

        $output = $extension->renderInit($customOptions);

        // Should contain custom release
        $this->assertStringContainsString('"release":"v2.0.0"', $output);

        // Should contain custom environment
        $this->assertStringContainsString('"environment":"staging"', $output);
    }

    public function testRenderInitIncludesDebugFlag(): void
    {
        $config = $this->getDefaultConfig();
        $config['debug'] = true;

        $extension = $this->makeExtension($config);
        $output = $extension->renderInit();

        $this->assertStringContainsString('"debug":true', $output);
    }

    public function testRenderInitWithoutUserWhenSecurityIsNull(): void
    {
        $config = $this->getDefaultConfig();
        $extension = $this->makeExtension($config, null);

        $output = $extension->renderInit();

        // Should only have initialization script, no user context script
        $scriptCount = substr_count($output, '<script type="module">');
        $this->assertSame(1, $scriptCount);
    }

    public function testRenderInitWithoutUserWhenNotAuthenticated(): void
    {
        $config = $this->getDefaultConfig();
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn(null);

        $extension = $this->makeExtension($config, $security);
        $output = $extension->renderInit();

        // Should only have initialization script, no user context script
        $scriptCount = substr_count($output, '<script type="module">');
        $this->assertSame(1, $scriptCount);
    }

    public function testRenderInitIncludesUserContextWhenAuthenticated(): void
    {
        $config = $this->getDefaultConfig();

        $user = $this->createMock(UserInterface::class);
        $user->method('getUserIdentifier')->willReturn('user-123');

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);

        $extension = $this->makeExtension($config, $security);
        $output = $extension->renderInit();

        // Should have both initialization and user context scripts
        $scriptCount = substr_count($output, '<script type="module">');
        $this->assertSame(2, $scriptCount);

        // Should contain user ID
        $this->assertStringContainsString('"id":"user-123"', $output);

        // Should set user on appLogger
        $this->assertStringContainsString('window.appLogger.setUser(', $output);
    }

    public function testRenderInitIncludesUserEmail(): void
    {
        $config = $this->getDefaultConfig();

        $user = new class implements UserInterface {
            public function getUserIdentifier(): string
            {
                return 'user-123';
            }

            public function getEmail(): string
            {
                return 'test@example.com';
            }

            public function getRoles(): array
            {
                return ['ROLE_USER'];
            }

            public function eraseCredentials(): void
            {
            }
        };

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);

        $extension = $this->makeExtension($config, $security);
        $output = $extension->renderInit();

        // Should contain email
        $this->assertStringContainsString('"email":"test@example.com"', $output);
    }

    public function testRenderInitIncludesUsernameWhenDifferentFromIdentifier(): void
    {
        $config = $this->getDefaultConfig();

        $user = new class implements UserInterface {
            public function getUserIdentifier(): string
            {
                return 'user-123';
            }

            public function getUsername(): string
            {
                return 'johndoe';
            }

            public function getRoles(): array
            {
                return ['ROLE_USER'];
            }

            public function eraseCredentials(): void
            {
            }
        };

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);

        $extension = $this->makeExtension($config, $security);
        $output = $extension->renderInit();

        // Should contain username
        $this->assertStringContainsString('"username":"johndoe"', $output);
    }

    public function testRenderInitOmitsUsernameWhenSameAsIdentifier(): void
    {
        $config = $this->getDefaultConfig();

        $user = new class implements UserInterface {
            public function getUserIdentifier(): string
            {
                return 'johndoe';
            }

            public function getUsername(): string
            {
                return 'johndoe'; // Same as identifier
            }

            public function getRoles(): array
            {
                return ['ROLE_USER'];
            }

            public function eraseCredentials(): void
            {
            }
        };

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);

        $extension = $this->makeExtension($config, $security);
        $output = $extension->renderInit();

        // Should NOT contain username field (only id)
        $this->assertStringNotContainsString('"username"', $output);
    }

    public function testRenderInitHandlesNullEmail(): void
    {
        $config = $this->getDefaultConfig();

        $user = new class implements UserInterface {
            public function getUserIdentifier(): string
            {
                return 'user-123';
            }

            public function getEmail(): null
            {
                return null; // Email can be null
            }

            public function getRoles(): array
            {
                return ['ROLE_USER'];
            }

            public function eraseCredentials(): void
            {
            }
        };

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);

        $extension = $this->makeExtension($config, $security);
        $output = $extension->renderInit();

        // Should NOT contain email field
        $this->assertStringNotContainsString('"email"', $output);
        // Should only contain id
        $this->assertStringContainsString('"id":"user-123"', $output);
    }

    public function testRenderInitEscapesHtmlInJson(): void
    {
        $config = $this->getDefaultConfig();
        $config['environment'] = '<script>alert("xss")</script>';

        $extension = $this->makeExtension($config);
        $output = $extension->renderInit();

        // JSON_HEX_TAG should escape < and > in the environment value
        // The output will contain real <script> tags for the SDK, but the
        // environment JSON value should have escaped characters
        $this->assertStringContainsString('\\u003C', $output); // <
        $this->assertStringContainsString('\\u003E', $output); // >

        // Check that the malicious environment value is properly escaped
        // (not that there are no script tags, since the SDK needs script tags)
        $this->assertStringContainsString('\\u003Cscript\\u003Ealert', $output);
        $this->assertStringNotContainsString('"environment":"<script>', $output);
    }

    public function testRenderInitEscapesAmpersandsInJson(): void
    {
        $config = $this->getDefaultConfig();
        $config['dsn'] = 'https://host.com/test?foo=1&bar=2';

        $extension = $this->makeExtension($config);
        $output = $extension->renderInit();

        // JSON_HEX_AMP should escape &
        $this->assertStringContainsString('\\u0026', $output);
    }

    public function testRenderInitReturnsEmptyStringWhenDsnMissing(): void
    {
        $config = $this->getDefaultConfig();
        unset($config['dsn']); // Remove DSN

        $extension = $this->makeExtension($config);
        $output = $extension->renderInit();

        $this->assertSame('', $output);
    }

    public function testRenderInitReturnsEmptyStringWhenPublishableKeyMissing(): void
    {
        $config = $this->getDefaultConfig();
        unset($config['publishable_key']); // Remove publishable key

        $extension = $this->makeExtension($config);
        $output = $extension->renderInit();

        $this->assertSame('', $output);
    }

    public function testRenderInitHardStripsSecretEvenIfPresentInConfig(): void
    {
        // Defense-in-depth: even if a misconfiguration leaves api_key/log_token in the
        // Twig $config, the hard-strip backstop must remove it before json_encode.
        // All four spellings stripped by the backstop loop are exercised here:
        // snake_case (api_key, log_token) AND camelCase (apiKey, logToken).
        $config = $this->getDefaultConfig();
        $config['api_key'] = 'sk_should_never_render';
        $config['log_token'] = 'sk_log_should_never_render';
        $config['apiKey'] = 'sk_camel_api_should_never_render';
        $config['logToken'] = 'sk_camel_log_should_never_render';

        $extension = $this->makeExtension($config);
        $output = $extension->renderInit();

        $this->assertStringNotContainsString('sk_should_never_render', $output);
        $this->assertStringNotContainsString('sk_log_should_never_render', $output);
        $this->assertStringNotContainsString('sk_camel_api_should_never_render', $output);
        $this->assertStringNotContainsString('sk_camel_log_should_never_render', $output);
        $this->assertStringNotContainsString('"apiKey"', $output);
        $this->assertStringNotContainsString('"api_key"', $output);
        $this->assertStringNotContainsString('"log_token"', $output);
        $this->assertStringNotContainsString('"logToken"', $output);
        // The publishable key still renders.
        $this->assertStringContainsString('"publishableKey":"pk_test_publishable"', $output);
    }

    public function testRenderInitReturnsEmptyStringWhenDsnIsInvalid(): void
    {
        $config = $this->getDefaultConfig();
        $config['dsn'] = 'not-a-valid-url'; // Invalid DSN format

        $extension = $this->makeExtension($config);
        $output = $extension->renderInit();

        $this->assertSame('', $output);
    }

    public function testRenderInitReturnsEmptyStringWhenDsnIsEmpty(): void
    {
        $config = $this->getDefaultConfig();
        $config['dsn'] = ''; // Empty DSN

        $extension = $this->makeExtension($config);
        $output = $extension->renderInit();

        $this->assertSame('', $output);
    }

    public function testRenderInitEmitsSessionReplayKeysWithCamelCaseNames(): void
    {
        $config = $this->getDefaultConfig();
        $config['session_replay'] = [
            'enabled' => true,
            'buffer_before_error_seconds' => 45,
            'buffer_before_error_clicks' => 12,
            'buffer_after_error_seconds' => 50,
            'buffer_after_error_clicks' => 8,
            'click_debounce_ms' => 250,
            'snapshot_throttle_ms' => 750,
            'max_snapshot_size' => 2097152,
            'session_timeout_minutes' => 90,
            'max_buffer_size_mb' => 10,
            'expose_api' => true,
        ];

        $extension = $this->makeExtension($config);
        $output = $extension->renderInit();

        // Each configured knob must reach the SDK config under the exact camelCase key
        // index.js / click-tracker.js read.
        $this->assertStringContainsString('"sessionReplayEnabled":true', $output);
        $this->assertStringContainsString('"bufferBeforeErrorSeconds":45', $output);
        $this->assertStringContainsString('"bufferBeforeErrorClicks":12', $output);
        $this->assertStringContainsString('"bufferAfterErrorSeconds":50', $output);
        $this->assertStringContainsString('"bufferAfterErrorClicks":8', $output);
        $this->assertStringContainsString('"clickDebounceMs":250', $output);
        $this->assertStringContainsString('"snapshotThrottleMs":750', $output);
        $this->assertStringContainsString('"maxSnapshotSize":2097152', $output);
        $this->assertStringContainsString('"sessionTimeoutMinutes":90', $output);
        $this->assertStringContainsString('"maxBufferSizeMB":10', $output);
        $this->assertStringContainsString('"exposeApi":true', $output);

        // Snake_case keys must NOT leak into the JS config.
        $this->assertStringNotContainsString('buffer_before_error_seconds', $output);
        $this->assertStringNotContainsString('expose_api', $output);
    }

    public function testRenderInitPreservesFalseSessionReplayValues(): void
    {
        $config = $this->getDefaultConfig();
        $config['session_replay'] = [
            'enabled' => false,
            'expose_api' => false,
        ];

        $extension = $this->makeExtension($config);
        $output = $extension->renderInit();

        // Legitimate `false` booleans must survive the null-only array_filter so the SDK
        // does not silently fall back to its `true` defaults.
        $this->assertStringContainsString('"sessionReplayEnabled":false', $output);
        $this->assertStringContainsString('"exposeApi":false', $output);
    }

    public function testRenderInitOmitsSessionReplayKeysWhenNotConfigured(): void
    {
        $config = $this->getDefaultConfig();
        // No 'session_replay' key at all.

        $extension = $this->makeExtension($config);
        $output = $extension->renderInit();

        // Without injected config the SDK keeps its own hardcoded defaults: emit nothing.
        $this->assertStringNotContainsString('sessionReplayEnabled', $output);
        $this->assertStringNotContainsString('bufferBeforeErrorSeconds', $output);
        $this->assertStringNotContainsString('exposeApi', $output);
    }

    public function testInvalidJsConfigLogsAtWarningNotError(): void
    {
        $logger = new class implements \Psr\Log\LoggerInterface {
            use \Psr\Log\LoggerTrait;
            /** @var list<array{level: string, message: string}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => (string) $level, 'message' => (string) $message];
            }
        };

        // enabled JS but missing dsn/publishable_key → validateConfiguration() fails → advisory.
        $ext = new ApplicationLoggerExtension(
            config: ['enabled' => true, 'dsn' => '', 'publishable_key' => ''],
            scriptRenderer: new ScriptRenderer(new CspNonceProvider()),
            logger: $logger,
            requestStack: null,
        );

        $ext->renderFragments([]); // triggers the missing-fields advisory

        self::assertNotEmpty($logger->records, 'advisory must be logged');
        self::assertSame('warning', $logger->records[0]['level'], 'JS-config advisory must log at warning, not error');
    }

    // --- master_enabled kill-switch tests ---

    public function testRenderFragmentsReturnsEmptyWhenMasterDisabled(): void
    {
        // application_logger.enabled=false must suppress the manual
        // {{ application_logger_init() }} path — identical to what
        // JavaScriptInjectionSubscriber does for auto-injection.
        $config = $this->getDefaultConfig();
        $config['master_enabled'] = false;

        $extension = $this->makeExtension($config);
        $fragments = $extension->renderFragments();

        $this->assertSame('', $fragments['headStart'], 'headStart must be empty when master disabled');
        $this->assertSame('', $fragments['headEnd'], 'headEnd must be empty when master disabled');
        $this->assertSame('', $fragments['bodyEnd'], 'bodyEnd must be empty when master disabled');
    }

    public function testRenderInitReturnsEmptyStringWhenMasterDisabled(): void
    {
        $config = $this->getDefaultConfig();
        $config['master_enabled'] = false;

        $extension = $this->makeExtension($config);

        $this->assertSame('', $extension->renderInit(), 'renderInit() must return empty string when master disabled');
    }

    public function testRenderFragmentsRendersNormallyWhenMasterEnabled(): void
    {
        // Positive control: master_enabled=true must not suppress output.
        $config = $this->getDefaultConfig();
        $config['master_enabled'] = true;

        $extension = $this->makeExtension($config);
        $output = $extension->renderInit();

        $this->assertStringContainsString('<script', $output, 'renderInit() must produce a script tag when master enabled');
        $this->assertStringContainsString('"dsn"', $output);
    }

    public function testRenderFragmentsDefaultsTrueWhenMasterEnabledKeyAbsent(): void
    {
        // BC: configs built before master_enabled was added must still render.
        $config = $this->getDefaultConfig();
        // Deliberately no 'master_enabled' key.

        $extension = $this->makeExtension($config);
        $output = $extension->renderInit();

        $this->assertStringContainsString('<script', $output, 'renderInit() must render when master_enabled is absent (default true)');
    }

    public function testGetGlobalsReturnsEmptyPublishableKeyWhenMasterDisabled(): void
    {
        $config = $this->getDefaultConfig();
        $config['master_enabled'] = false;

        $extension = $this->makeExtension($config);
        $globals = $extension->getGlobals();

        $this->assertArrayHasKey('app_logger_publishable_key', $globals);
        $this->assertSame('', $globals['app_logger_publishable_key'], 'publishable key must be suppressed when master disabled');
    }

    public function testGetGlobalsReturnsPublishableKeyWhenMasterEnabled(): void
    {
        $config = $this->getDefaultConfig();
        $config['master_enabled'] = true;

        $extension = $this->makeExtension($config);
        $globals = $extension->getGlobals();

        $this->assertSame('pk_test_publishable', $globals['app_logger_publishable_key']);
    }

    public function testInvalidDsnFormatLogsAtWarningNotError(): void
    {
        $logger = new class implements \Psr\Log\LoggerInterface {
            use \Psr\Log\LoggerTrait;
            /** @var list<array{level: string, message: string}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => (string) $level, 'message' => (string) $message];
            }
        };

        // enabled JS, non-empty dsn/publishable_key but dsn is not a valid URL →
        // passes the missing-fields check and reaches the filter_var(FILTER_VALIDATE_URL) failure.
        $ext = new ApplicationLoggerExtension(
            config: ['enabled' => true, 'dsn' => 'not a url', 'publishable_key' => 'pk_test_x'],
            scriptRenderer: new ScriptRenderer(new CspNonceProvider()),
            logger: $logger,
            requestStack: null,
        );

        $ext->renderFragments([]); // triggers the invalid-DSN-format advisory

        self::assertNotEmpty($logger->records, 'advisory must be logged');
        self::assertSame('warning', $logger->records[0]['level'], 'invalid-DSN-format advisory must log at warning, not error');
    }

    /**
     * Build the extension with a ScriptRenderer wired to the given (optional) security.
     *
     * No AssetMapper is injected, so the SDK module resolves to the bare
     * "@application-logger/logger" specifier the assertions expect.
     *
     * @param array<string, mixed> $config
     */
    private function makeExtension(array $config, ?Security $security = null): ApplicationLoggerExtension
    {
        $renderer = new ScriptRenderer(new CspNonceProvider(), $security);

        return new ApplicationLoggerExtension($config, $renderer);
    }

    /**
     * Get default configuration for tests.
     *
     * @return array<string, mixed>
     */
    private function getDefaultConfig(): array
    {
        return [
            'enabled' => true,
            'dsn' => 'https://test-host.com/test-project',
            'publishable_key' => 'pk_test_publishable',
            'environment' => 'test',
            'release' => 'v1.0.0',
            'debug' => false,
            'scrub_fields' => ['password', 'token'],
        ];
    }
}
