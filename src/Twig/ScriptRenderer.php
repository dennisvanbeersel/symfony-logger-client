<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\Twig;

use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\AssetMapper\AssetMapperInterface;

/**
 * Owns the generation of the ApplicationLogger JavaScript SDK <script> blocks.
 *
 * Extracted from {@see ApplicationLoggerExtension} so the Twig extension stays a
 * thin facade. This class is the single responsibility holder for the four script
 * fragments (nuclear trap, early error buffer, SDK init module, user context
 * module) and the AssetMapper SDK-URL resolution they depend on.
 *
 * The emitted HTML/JS is byte-for-byte identical to the previous inline
 * implementation; only the location changed.
 */
final readonly class ScriptRenderer
{
    public function __construct(
        private CspNonceProvider $nonceProvider,
        private ?Security $security = null,
        private ?LoggerInterface $logger = null,
        private ?AssetMapperInterface $assetMapper = null,
    ) {
    }

    /**
     * Resolve the public URL of the SDK module so the injected `import` works on a clean
     * install WITHOUT the host application adding an importmap entry.
     *
     * The bundle registers assets/dist under the "@application-logger" AssetMapper namespace,
     * so the SDK's logical path is "@application-logger/logger.js". When AssetMapper is
     * available we resolve that to its digested public path (e.g. /assets/logger-abc123.js)
     * and import from the concrete URL. When AssetMapper is absent (e.g. a Webpack/esbuild
     * host), we fall back to the bare specifier, which such hosts are expected to alias.
     */
    private function resolveSdkModule(): string
    {
        try {
            $publicPath = $this->assetMapper?->getPublicPath('@application-logger/logger.js');
            if (\is_string($publicPath) && '' !== $publicPath) {
                return $publicPath;
            }
        } catch (\Throwable $e) {
            $this->logError('Could not resolve SDK asset path via AssetMapper: '.$e->getMessage());
        }

        return '@application-logger/logger';
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
    public function generateNuclearTrap(): string
    {
        $nonceAttr = $this->nonceProvider->nonceAttribute();

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
    public function generateBufferScript(): string
    {
        $nonceAttr = $this->nonceProvider->nonceAttribute();

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
    public function generateInitScript(array $config): string
    {
        $configJson = json_encode($config, \JSON_UNESCAPED_SLASHES | \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_HEX_APOS | \JSON_HEX_QUOT);

        // JSON encoding should never fail with our config structure, but be defensive
        if (false === $configJson) {
            return ''; // Silently fail - resilience priority
        }

        $nonceAttr = $this->nonceProvider->nonceAttribute();

        // Resolve a concrete module URL so the import works without a host importmap entry.
        $sdkModule = $this->resolveSdkModule();

        return <<<HTML
<script type="module"{$nonceAttr}>
    import ApplicationLogger from '{$sdkModule}';

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
    public function generateUserScript(): string
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

        $nonceAttr = $this->nonceProvider->nonceAttribute();

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
