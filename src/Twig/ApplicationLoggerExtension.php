<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\Twig;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
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
class ApplicationLoggerExtension extends AbstractExtension implements GlobalsInterface
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
     * Expose bundle config values as Twig globals so manual-include templates
     * (e.g. init.html.twig) can read them without extra template variables.
     *
     * The publishable key is world-readable (pk_…) and safe to inline into HTML;
     * the secret api_key is deliberately absent here (it is server-side only, G1).
     *
     * When the master kill-switch (`application_logger.enabled`) is false the
     * publishable key is suppressed so the key cannot be observed even through
     * the Twig global when the bundle is disabled wholesale.
     *
     * @return array<string, mixed>
     */
    public function getGlobals(): array
    {
        if (!($this->config['master_enabled'] ?? true)) {
            return ['app_logger_publishable_key' => ''];
        }

        return [
            'app_logger_publishable_key' => $this->config['publishable_key'] ?? '',
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
            // Skip if the bundle master kill-switch is off (application_logger.enabled=false).
            // This gates the manual {{ application_logger_init() }} path the same way
            // JavaScriptInjectionSubscriber gates the auto-injection path. Default true
            // for backward compatibility when the key is absent from the config array.
            if (!($this->config['master_enabled'] ?? true)) {
                return $empty;
            }

            // Skip if JavaScript SDK is disabled
            if (!isset($this->config['enabled']) || !$this->config['enabled']) {
                return $empty;
            }

            // Validate required configuration
            if (!$this->validateConfiguration()) {
                $this->logWarning('JavaScript SDK configuration is invalid - missing required fields');

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
            // World-readable browser key ONLY. The secret api_key is server-side and
            // MUST NOT be present in this Twig $config (see services.yaml swap) nor
            // reach the browser (G1). publishableKey is the JS SDK config key.
            'publishableKey' => $this->config['publishable_key'],
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

        // Hard-strip backstop (G1, MF-7): the browser config must NEVER carry a secret.
        // Belt-and-suspenders against a future services.yaml regression or a caller-
        // supplied $options that smuggles one of these in. Drop both snake_case and
        // camelCase spellings of the server credentials before they can be json_encode'd.
        foreach (['apiKey', 'api_key', 'logToken', 'log_token'] as $secretKey) {
            unset($config[$secretKey]);
        }

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
        // Required fields: dsn + the world-readable publishable key. The secret api_key
        // is deliberately NOT here — it never reaches the Twig path (G1/§6.4).
        $requiredFields = ['dsn', 'publishable_key'];

        foreach ($requiredFields as $field) {
            if (!isset($this->config[$field]) || empty($this->config[$field])) {
                return false;
            }
        }

        // Validate DSN format (basic check)
        if (!filter_var($this->config['dsn'], \FILTER_VALIDATE_URL)) {
            $this->logWarning('Invalid DSN format', ['dsn' => $this->config['dsn']]);

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
     * Log a bundle self-diagnostic. Routed to the dedicated internal Monolog channel
     * (excluded from the bundle's own handler) so it can never feed the pipeline.
     *
     * @param array<string, mixed> $context
     */
    private function logWarning(string $message, array $context = []): void
    {
        $this->logger?->warning('ApplicationLogger JavaScript SDK: '.$message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function logError(string $message, array $context = []): void
    {
        $this->logger?->error('ApplicationLogger JavaScript SDK: '.$message, $context);
    }
}
