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

        // Should contain API key
        $this->assertStringContainsString('"apiKey":"test-api-key"', $output);

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

    public function testRenderInitReturnsEmptyStringWhenApiKeyMissing(): void
    {
        $config = $this->getDefaultConfig();
        unset($config['api_key']); // Remove API key

        $extension = $this->makeExtension($config);
        $output = $extension->renderInit();

        $this->assertSame('', $output);
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
            'api_key' => 'test-api-key',
            'environment' => 'test',
            'release' => 'v1.0.0',
            'debug' => false,
            'scrub_fields' => ['password', 'token'],
        ];
    }
}
