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

            // Generate scripts in defense-in-depth order:
            // 1. Nuclear trap (ultra-minimal, captures catastrophic errors)
            $nuclearTrap = $this->generateNuclearTrap();

            // 2. Early error buffer (lightweight, captures early errors)
            $bufferScript = $this->generateBufferScript();

            // 3. Full SDK initialization (module, deferred)
            $initScript = $this->generateInitScript($config);

            // 4. User context (if authenticated)
            $userScript = $this->generateUserScript();

            // Return in order: nuclear trap → buffer → SDK → user context
            return $nuclearTrap.$bufferScript.$initScript.$userScript;
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
     * Generate ultra-minimal nuclear error trap (inline, executes FIRST).
     *
     * This is the FIRST line of defense - captures catastrophic errors that
     * break JavaScript execution before our SDK can even load.
     *
     * Features:
     * - NO dependencies (survives even if SDK fails to load)
     * - Stores raw errors to localStorage ONLY
     * - Will be "resurrected" on next page load
     * - ~250 bytes minified
     *
     * Handles:
     * - Syntax errors before SDK loads
     * - Module import failures
     * - Blocking runtime errors
     * - Third-party script failures
     */
    private function generateNuclearTrap(): string
    {
        // Get CSP nonce from request attributes
        $nonce = $this->getCspNonce();
        $nonceAttr = $nonce ? ' nonce="'.htmlspecialchars($nonce, \ENT_QUOTES, 'UTF-8').'"' : '';

        // Ultra-minimal, no dependencies, compressed, bulletproof
        // Handles: errors, promise rejections, localStorage failures, quota exceeded
        return <<<HTML
<script{$nonceAttr}>
(function(){try{if(!window.localStorage)return;var k='_appLogger_nuclear',m=20,s=function(e,t){try{var r=localStorage.getItem(k),n=r?JSON.parse(r):[];if(n.length<m){n.push({m:e,f:t.f||'',l:t.l||0,c:t.c||0,t:Date.now(),u:location.href});localStorage.setItem(k,JSON.stringify(n));}}catch(a){}};window.addEventListener('error',function(e){s(e.message||'',{f:e.filename,l:e.lineno,c:e.colno})},!0);window.addEventListener('unhandledrejection',function(e){s('Unhandled rejection: '+(e.reason&&e.reason.message||String(e.reason||''))+'',{})});}catch(e){}})();
</script>

HTML;
    }

    /**
     * Generate early error buffer script (inline, executes immediately).
     *
     * This lightweight script captures errors that occur before the full SDK loads.
     * It executes synchronously to ensure no errors are missed.
     */
    private function generateBufferScript(): string
    {
        // Get CSP nonce from request attributes
        $nonce = $this->getCspNonce();
        $nonceAttr = $nonce ? ' nonce="'.htmlspecialchars($nonce, \ENT_QUOTES, 'UTF-8').'"' : '';

        return <<<HTML
<script{$nonceAttr}>
  // ApplicationLogger Early Error Buffer
  // Captures errors before the full SDK loads (executes immediately)
  (function() {
    'use strict';

    // Prevent duplicate initialization
    if (window._appLoggerBuffer && window._appLoggerBuffer._initialized) {
      return;
    }

    // Initialize buffer (preserve existing errors if any)
    var existingErrors = window._appLoggerBuffer && Array.isArray(window._appLoggerBuffer.errors)
      ? window._appLoggerBuffer.errors
      : [];

    window._appLoggerBuffer = {
      errors: existingErrors,
      maxSize: 50,
      startTime: window._appLoggerBuffer && window._appLoggerBuffer.startTime
        ? window._appLoggerBuffer.startTime
        : Date.now(),
      _initialized: true,

      push: function(item) {
        try {
          if (Array.isArray(this.errors) && this.errors.length < this.maxSize) {
            this.errors.push(item);
          }
        } catch (e) {
          // Silent fail - never crash the buffer
        }
      }
    };

    // Capture uncaught errors
    window.addEventListener('error', function(event) {
      try {
        // Defensive: ensure event exists and has expected shape
        if (!event) return;

        var errorData = {
          type: 'error',
          message: (event.message != null ? String(event.message) : 'Unknown error'),
          filename: (event.filename != null ? String(event.filename) : 'unknown'),
          lineno: (typeof event.lineno === 'number' ? event.lineno : 0),
          colno: (typeof event.colno === 'number' ? event.colno : 0),
          timestamp: Date.now(),
          error: null
        };

        // Safely extract error object if present
        if (event.error && typeof event.error === 'object') {
          try {
            errorData.error = {
              name: event.error.name != null ? String(event.error.name) : 'Error',
              message: event.error.message != null ? String(event.error.message) : '',
              stack: event.error.stack != null ? String(event.error.stack) : ''
            };
          } catch (e) {
            // Error object might not be serializable
            errorData.error = { name: 'Error', message: 'Could not serialize error', stack: '' };
          }
        }

        window._appLoggerBuffer.push(errorData);
      } catch (e) {
        // Never crash on error handling
      }
    }, true); // Use capture phase to get errors before other handlers

    // Capture unhandled promise rejections
    window.addEventListener('unhandledrejection', function(event) {
      try {
        if (!event) return;

        var reason = event.reason;
        var reasonData;

        // Handle different types of rejection reasons
        if (reason == null) {
          reasonData = { name: 'UnhandledRejection', message: 'undefined', stack: '' };
        } else if (typeof reason === 'object') {
          try {
            reasonData = {
              name: reason.name != null ? String(reason.name) : 'UnhandledRejection',
              message: reason.message != null ? String(reason.message) : String(reason),
              stack: reason.stack != null ? String(reason.stack) : ''
            };
          } catch (e) {
            reasonData = { name: 'UnhandledRejection', message: 'Could not serialize reason', stack: '' };
          }
        } else {
          // Primitive value (string, number, boolean)
          reasonData = {
            name: 'UnhandledRejection',
            message: String(reason),
            stack: ''
          };
        }

        window._appLoggerBuffer.push({
          type: 'rejection',
          reason: reasonData,
          timestamp: Date.now()
        });
      } catch (e) {
        // Never crash on rejection handling
      }
    });
  })();
</script>

HTML;
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
