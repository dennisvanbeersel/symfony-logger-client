<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\Twig;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig extension for ApplicationLogger JavaScript SDK integration.
 *
 * Provides the `application_logger_init()` function that outputs a <script>
 * tag with the JavaScript SDK initialization code.
 *
 * This class is a thin facade: it owns the Twig function registration, the
 * config gating/merge and the session-id lookup, then delegates the actual
 * <script> generation to {@see ScriptRenderer} (single responsibility).
 */
class ApplicationLoggerExtension extends AbstractExtension
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly array $config,
        private readonly ScriptRenderer $scriptRenderer,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?RequestStack $requestStack = null,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('application_logger_init', [$this, 'renderInit'], [
                'is_safe' => ['html'],
            ]),
        ];
    }

    /**
     * Render JavaScript SDK initialization script (all fragments concatenated).
     *
     * Outputs the nuclear trap, early error buffer, SDK init module and user
     * context module as a single HTML string, in defense-in-depth order. This is
     * a thin convenience wrapper around {@see renderFragments()} for manual
     * `{{ application_logger_init() }}` usage and backward compatibility.
     *
     * This method is designed to never throw exceptions - it will silently fail
     * and return an empty string if any errors occur. This ensures the application
     * continues to work even if JavaScript SDK initialization fails.
     *
     * @param array<string, mixed> $options Override default configuration
     */
    public function renderInit(array $options = []): string
    {
        $fragments = $this->renderFragments($options);

        return $fragments['headStart'].$fragments['headEnd'].$fragments['bodyEnd'];
    }

    /**
     * Render the SDK scripts as separate fragments keyed by their target DOM position.
     *
     * Each fragment is a self-contained block of one or more <script> tags (with the
     * CSP nonce already applied) that the auto-injection subscriber drops in at the
     * matching position. Exposing them directly avoids assembling everything into one
     * blob and then splitting it apart again by sniffing magic strings.
     *
     * Positions (must match the historical injection placement exactly):
     * - headStart: nuclear trap, injected right after <head> (earliest execution)
     * - headEnd:   early error buffer, injected before </head>
     * - bodyEnd:   SDK init + user context modules, injected before </body> (deferred)
     *
     * The nuclear trap and buffer MUST stay inline in the head and run BEFORE the
     * deferred module so they can catch errors that occur before the SDK loads.
     *
     * Never throws - returns empty fragments on any failure (resilience priority).
     *
     * @param array<string, mixed> $options Override default configuration
     *
     * @return array{headStart: string, headEnd: string, bodyEnd: string}
     */
    public function renderFragments(array $options = []): array
    {
        $empty = ['headStart' => '', 'headEnd' => '', 'bodyEnd' => ''];

        try {
            // Skip if JavaScript SDK is disabled
            if (!isset($this->config['enabled']) || !$this->config['enabled']) {
                return $empty;
            }

            // Validate required configuration
            if (!$this->validateConfiguration()) {
                $this->logError('JavaScript SDK configuration is invalid - missing required fields');

                return $empty;
            }

            // Build configuration object
            $config = $this->buildConfig($options);

            return [
                // 1. Nuclear trap (ultra-minimal, captures catastrophic errors) - head start
                'headStart' => $this->scriptRenderer->generateNuclearTrap(),
                // 2. Early error buffer (lightweight, captures early errors) - head end
                'headEnd' => $this->scriptRenderer->generateBufferScript(),
                // 3. Full SDK init (deferred) + 4. user context (if authenticated) - body end
                'bodyEnd' => $this->scriptRenderer->generateInitScript($config).$this->scriptRenderer->generateUserScript(),
            ];
        } catch (\Throwable $e) {
            // Never throw - resilience is priority
            $this->logError('Failed to render JavaScript SDK initialization', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $empty;
        }
    }

    /**
     * Build configuration object for JavaScript SDK.
     *
     * Merges default configuration with custom options.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function buildConfig(array $options): array
    {
        $defaults = [
            'dsn' => $this->config['dsn'],
            'apiKey' => $this->config['api_key'],
            'environment' => $this->config['environment'],
            'release' => $this->config['release'],
            'debug' => $this->config['debug'],
            'scrubFields' => $this->config['scrub_fields'],
        ];

        // Forward the configured session-replay knobs to the JS SDK using the camelCase
        // keys it actually reads (see buildSessionReplayConfig()).
        $defaults += $this->buildSessionReplayConfig();

        // Add session ID if available
        $sessionId = $this->getSessionId();
        if (null !== $sessionId) {
            $defaults['sessionId'] = $sessionId;
        }

        // Merge with custom options
        $config = array_merge($defaults, $options);

        // Remove ONLY null values. Legitimate `false` (e.g. sessionReplayEnabled,
        // exposeApi) and `0` must survive so the SDK receives the configured value.
        return array_filter($config, fn ($value) => null !== $value);
    }

    /**
     * Map the bundle's `session_replay.*` config (snake_case) to the exact camelCase
     * keys the JS SDK consumes (see assets/src/index.js and assets/src/click-tracker.js).
     *
     * Returns an empty array when no session_replay config was injected, so the SDK
     * falls back to its own hardcoded defaults. Each PHP key maps explicitly to its
     * JS counterpart - there is no naive snake->camel transform, the names are pinned.
     *
     * @return array<string, mixed>
     */
    private function buildSessionReplayConfig(): array
    {
        $replay = $this->config['session_replay'] ?? null;

        if (!\is_array($replay)) {
            return [];
        }

        // PHP config key => JS SDK config key (verbatim, as read by the SDK).
        $map = [
            'enabled' => 'sessionReplayEnabled',
            'buffer_before_error_seconds' => 'bufferBeforeErrorSeconds',
            'buffer_before_error_clicks' => 'bufferBeforeErrorClicks',
            'buffer_after_error_seconds' => 'bufferAfterErrorSeconds',
            'buffer_after_error_clicks' => 'bufferAfterErrorClicks',
            'click_debounce_ms' => 'clickDebounceMs',
            'snapshot_throttle_ms' => 'snapshotThrottleMs',
            'max_snapshot_size' => 'maxSnapshotSize',
            'session_timeout_minutes' => 'sessionTimeoutMinutes',
            'max_buffer_size_mb' => 'maxBufferSizeMB',
            'expose_api' => 'exposeApi',
        ];

        $jsConfig = [];
        foreach ($map as $phpKey => $jsKey) {
            if (\array_key_exists($phpKey, $replay)) {
                $jsConfig[$jsKey] = $replay[$phpKey];
            }
        }

        return $jsConfig;
    }

    /**
     * Validate that required configuration fields are present.
     */
    private function validateConfiguration(): bool
    {
        // Check required fields
        $requiredFields = ['dsn', 'api_key'];

        foreach ($requiredFields as $field) {
            if (!isset($this->config[$field]) || empty($this->config[$field])) {
                return false;
            }
        }

        // Validate DSN format (basic check)
        if (!filter_var($this->config['dsn'], \FILTER_VALIDATE_URL)) {
            $this->logError('Invalid DSN format', ['dsn' => $this->config['dsn']]);

            return false;
        }

        return true;
    }

    /**
     * Get session ID from Symfony session.
     *
     * Retrieves the ApplicationLogger session ID created by SessionTrackingSubscriber.
     */
    private function getSessionId(): ?string
    {
        try {
            if (null === $this->requestStack) {
                return null;
            }

            $request = $this->requestStack->getCurrentRequest();

            if (null === $request || !$request->hasSession()) {
                return null;
            }

            $session = $request->getSession();
            $sessionId = $session->get('_application_logger_session_id');

            if (null === $sessionId || !\is_string($sessionId)) {
                return null;
            }

            return $sessionId;
        } catch (\Throwable) {
            // Silently fail - session ID is optional for JS SDK
            return null;
        }
    }

    /**
     * Log an error message.
     *
     * @param array<string, mixed> $context
     */
    private function logError(string $message, array $context = []): void
    {
        if (null !== $this->logger) {
            $this->logger->error('ApplicationLogger JavaScript SDK: '.$message, $context);
        }
    }
}
