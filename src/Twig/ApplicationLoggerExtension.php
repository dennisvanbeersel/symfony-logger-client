<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\Twig;

use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig extension for ApplicationLogger JavaScript SDK integration.
 *
 * Provides the `application_logger_init()` function that outputs a <script>
 * tag with the JavaScript SDK initialization code.
 */
class ApplicationLoggerExtension extends AbstractExtension
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly array $config,
        private readonly ?Security $security = null,
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
     * Render JavaScript SDK initialization script.
     *
     * Outputs a <script type="module"> tag that imports the ApplicationLogger
     * class and initializes it with the configured options.
     *
     * This method is designed to never throw exceptions - it will silently fail
     * and return an empty string if any errors occur. This ensures the application
     * continues to work even if JavaScript SDK initialization fails.
     *
     * @param array<string, mixed> $options Override default configuration
     */
    public function renderInit(array $options = []): string
    {
        try {
            // Skip if JavaScript SDK is disabled
            if (!isset($this->config['enabled']) || !$this->config['enabled']) {
                return '';
            }

            // Validate required configuration
            if (!$this->validateConfiguration()) {
                $this->logError('JavaScript SDK configuration is invalid - missing required fields');

                return '';
            }

            // Build configuration object
            $config = $this->buildConfig($options);

            // Generate initialization script
            $initScript = $this->generateInitScript($config);

            // Add user context script if user is authenticated
            $userScript = $this->generateUserScript();

            return $initScript.$userScript;
        } catch (\Throwable $e) {
            // Never throw - resilience is priority
            $this->logError('Failed to render JavaScript SDK initialization', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return '';
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

        // Add session ID if available
        $sessionId = $this->getSessionId();
        if (null !== $sessionId) {
            $defaults['sessionId'] = $sessionId;
        }

        // Merge with custom options
        $config = array_merge($defaults, $options);

        // Remove null values
        return array_filter($config, fn ($value) => null !== $value);
    }

    /**
     * Generate initialization script tag.
     *
     * @param array<string, mixed> $config
     */
    private function generateInitScript(array $config): string
    {
        $configJson = json_encode($config, \JSON_UNESCAPED_SLASHES | \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_HEX_APOS | \JSON_HEX_QUOT);

        // JSON encoding should never fail with our config structure, but be defensive
        if (false === $configJson) {
            return ''; // Silently fail - resilience priority
        }

        // Get CSP nonce from request attributes
        $nonce = $this->getCspNonce();
        $nonceAttr = $nonce ? ' nonce="'.htmlspecialchars($nonce, \ENT_QUOTES, 'UTF-8').'"' : '';

        return <<<HTML
<script type="module"{$nonceAttr}>
    import ApplicationLogger from '@application-logger/logger';

    const logger = new ApplicationLogger({$configJson});
    logger.init();

    // Make available globally for manual usage
    window.appLogger = logger;
</script>

HTML;
    }

    /**
     * Generate user context script if user is authenticated.
     */
    private function generateUserScript(): string
    {
        // Skip if no security component or no authenticated user
        if (null === $this->security || null === ($user = $this->security->getUser())) {
            return '';
        }

        // Build user context
        $userContext = [
            'id' => $user->getUserIdentifier(),
        ];

        // Add email if available
        if (method_exists($user, 'getEmail')) {
            $email = $user->getEmail();
            if (null !== $email) {
                $userContext['email'] = $email;
            }
        }

        // Add username if different from identifier
        if (method_exists($user, 'getUsername')) {
            $username = $user->getUsername();
            if (null !== $username && $username !== $user->getUserIdentifier()) {
                $userContext['username'] = $username;
            }
        }

        $userJson = json_encode($userContext, \JSON_UNESCAPED_SLASHES | \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_HEX_APOS | \JSON_HEX_QUOT);

        // Should never fail, but be defensive
        if (false === $userJson) {
            return ''; // Silently fail
        }

        // Get CSP nonce from request attributes
        $nonce = $this->getCspNonce();
        $nonceAttr = $nonce ? ' nonce="'.htmlspecialchars($nonce, \ENT_QUOTES, 'UTF-8').'"' : '';

        return <<<HTML
<script type="module"{$nonceAttr}>
    // Set user context after initialization
    if (window.appLogger) {
        window.appLogger.setUser({$userJson});
    }
</script>

HTML;
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
     * Get CSP nonce from request attributes.
     *
     * Returns null if no nonce is available (e.g., project doesn't use CSP).
     */
    private function getCspNonce(): ?string
    {
        try {
            if (null === $this->requestStack) {
                return null;
            }

            $request = $this->requestStack->getCurrentRequest();

            if (null === $request) {
                return null;
            }

            $nonce = $request->attributes->get('csp_nonce');

            if (null === $nonce || !\is_string($nonce) || '' === $nonce) {
                return null;
            }

            return $nonce;
        } catch (\Throwable) {
            // Silently fail - CSP nonce is optional
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
