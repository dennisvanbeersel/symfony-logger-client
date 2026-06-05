# CLAUDE.md - Symfony Bundle

This file provides guidance to Claude Code when working with the AppLogger Symfony Bundle client library.

## Overview

This is a **client library** for the AppLogger error tracking platform. It enables Symfony applications to automatically capture and send errors to [applogger.eu](https://applogger.eu).

**Key Principle**: This bundle runs inside customer applications. **Never impact the host application's performance or stability.**

### What This Bundle Does

- Captures PHP exceptions and Monolog errors
- Provides a JavaScript SDK for frontend error tracking
- Sends errors to AppLogger API with resilience patterns
- Collects breadcrumbs and context for debugging
- Sanitizes sensitive data (GDPR compliance)

### Technology Stack

- **PHP 8.2+** with strict types
- **Symfony 6.4 / 7.x** bundle architecture
- **JavaScript ES6+** SDK (bundled, not separate npm package)
- **Rollup** for JS build (ESM + UMD outputs)

---

## Directory Structure

```
.
├── src/
│   ├── ApplicationLoggerBundle.php      # Bundle entry point
│   ├── DependencyInjection/
│   │   ├── ApplicationLoggerExtension.php  # Service registration
│   │   └── Configuration.php               # Bundle config schema
│   ├── EventSubscriber/
│   │   ├── ExceptionSubscriber.php         # Catches uncaught exceptions
│   │   ├── SessionTrackingSubscriber.php   # Tracks user sessions
│   │   └── JavaScriptInjectionSubscriber.php # Auto-injects JS SDK
│   ├── Monolog/Handler/
│   │   └── ApplicationLoggerHandler.php    # Monolog integration
│   ├── Service/
│   │   ├── ApiClient.php                   # HTTP client with resilience
│   │   ├── BreadcrumbCollector.php         # Breadcrumb trail
│   │   ├── CircuitBreaker.php              # Circuit breaker pattern
│   │   ├── ContextCollector.php            # Request/user context
│   │   └── DsnGenerator.php                # DSN parsing/validation
│   ├── Twig/
│   │   └── ApplicationLoggerExtension.php  # Twig globals/functions
│   └── Util/
│       └── StackTraceParserTrait.php       # Exception formatting
│
├── assets/                    # JavaScript SDK (bundled with PHP package)
│   ├── src/                   # JS source code
│   │   ├── index.js           # Main entry point
│   │   ├── client.js          # Error capture client
│   │   ├── breadcrumbs.js     # Breadcrumb tracking
│   │   └── transport.js       # HTTP transport with resilience
│   └── dist/                  # Built JS files (committed for distribution)
│       ├── logger.js          # ES module
│       └── logger.umd.js      # UMD bundle
│
├── config/
│   └── services.yaml          # Service definitions
│
├── tests/                     # PHPUnit tests
├── composer.json              # PHP dependencies
├── package.json               # JS build dependencies
└── rollup.config.js           # JS build configuration
```

---

## Development Commands

```bash
# Install PHP dependencies
composer install

# Install JavaScript dependencies (for building JS SDK)
npm install

# Build JavaScript SDK (REQUIRED after JS changes)
npm run build

# Build in watch mode (during development)
npm run build:watch

# Run PHP tests
vendor/bin/phpunit

# Run JavaScript tests
npm test

# Lint PHP code
composer cs-check      # Check PSR-12
composer cs-fix        # Auto-fix PSR-12
composer phpstan       # Static analysis (level 6)

# Lint JavaScript code
npm run lint
npm run lint:fix
```

---

## Critical Best Practices

### 1. Never Impact Host Application

This is the **#1 rule**. The bundle runs inside customer apps:

```php
// ❌ NEVER throw exceptions that could crash the host app
public function sendError(array $payload): void
{
    throw new \RuntimeException('API failed'); // BAD!
}

// ✅ ALWAYS catch and log silently
public function sendError(array $payload): void
{
    try {
        $this->doSend($payload);
    } catch (\Throwable $e) {
        // Log internally, don't throw
        $this->logger?->warning('AppLogger API error', ['exception' => $e]);
    }
}
```

### 2. Timeout Protection

All HTTP calls MUST have aggressive timeouts:

```php
// ✅ Configure timeout at client level
$this->httpClient = $httpClient->withOptions([
    'timeout' => 2.0,  // 2 seconds max (configurable 0.5-5.0)
]);
```

### 3. Circuit Breaker Pattern

Prevent cascading failures when API is down:

```php
// ✅ Check circuit breaker before making requests
if ($this->circuitBreaker->isOpen()) {
    return; // Skip API call, don't wait
}

try {
    $response = $this->httpClient->request('POST', $url, $options);
    $this->circuitBreaker->recordSuccess();
} catch (\Throwable $e) {
    $this->circuitBreaker->recordFailure();
}
```

**Circuit Breaker States**:
- `CLOSED` (normal): Requests pass through
- `OPEN` (failed): Skip all requests (saves resources)
- `HALF_OPEN` (testing): Allow 1 test request after timeout

### 4. Fire-and-Forget Mode

Default mode - return immediately, don't wait for API response:

```php
// ✅ Async mode - returns in < 1ms
$this->httpClient->request('POST', $url, [
    'buffer' => false,  // Don't wait for response body
]);
// Method returns immediately, request continues in background
```

### 5. Data Sanitization (GDPR)

Always scrub sensitive data before sending:

```php
// ✅ Scrub sensitive fields recursively
private function scrubData(array $data, array $scrubFields): array
{
    foreach ($data as $key => $value) {
        foreach ($scrubFields as $field) {
            if (stripos($key, $field) !== false) {
                $data[$key] = '[REDACTED]';
                break;
            }
        }
        if (is_array($value)) {
            $data[$key] = $this->scrubData($value, $scrubFields);
        }
    }
    return $data;
}
```

**Default scrub fields**: password, token, api_key, secret, authorization, credit_card, ssn

### 6. IP Anonymization

Mask IP addresses for GDPR compliance:

```php
// ✅ IPv4: mask last octet
// 192.168.1.100 → 192.168.1.0

// ✅ IPv6: mask last 80 bits
// 2001:db8::1234 → 2001:db8::0
```

---

## PHP Code Standards

### Strict Types (Required)

```php
<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\Service;
```

### Type Hints (Required)

```php
// ✅ Full type hints on everything
public function sendError(
    array $payload,
    ?string $idempotencyKey = null
): bool {
    // ...
}

// ✅ Property types
private readonly HttpClientInterface $httpClient;
private readonly CircuitBreaker $circuitBreaker;
private ?LoggerInterface $logger = null;
```

### Readonly Properties (Where Applicable)

```php
// ✅ Use readonly for immutable properties
public function __construct(
    private readonly string $dsn,
    private readonly float $timeout,
    private readonly CircuitBreaker $circuitBreaker,
) {}
```

### Dependency Injection

```php
// ✅ Inject dependencies via constructor
public function __construct(
    private readonly ApiClient $apiClient,
    private readonly BreadcrumbCollector $breadcrumbs,
) {}

// ❌ NEVER use static methods or global state
ErrorTracker::capture($e);  // BAD!
```

### PHPStan Level 6

All code must pass PHPStan level 6:

```bash
composer phpstan
```

Common issues to avoid:
- Mixed types without explicit handling
- Unchecked array access
- Missing null checks
- Incorrect return types

---

## JavaScript SDK Standards

### ES6+ Syntax (Required)

```javascript
// ✅ Modern syntax
const captureException = async (error, context = {}) => {
    const payload = { ...context, error: serializeError(error) };
    await transport.send(payload);
};

// ❌ Avoid var, function declarations where possible
var capture = function(error) { ... }; // BAD!
```

### Resilience Patterns (Required)

The JS SDK MUST implement:

1. **Timeout with AbortController**:
```javascript
const controller = new AbortController();
const timeoutId = setTimeout(() => controller.abort(), 3000);

try {
    await fetch(url, { signal: controller.signal, ...options });
} finally {
    clearTimeout(timeoutId);
}
```

2. **Circuit Breaker (sessionStorage)**:
```javascript
// Store state in sessionStorage to persist across page loads
const state = JSON.parse(sessionStorage.getItem('applogger_cb') || '{}');
if (state.open && Date.now() < state.openUntil) {
    return; // Skip request
}
```

3. **Offline Queue (localStorage)**:
```javascript
// Queue errors when offline/rate-limited
const queue = JSON.parse(localStorage.getItem('applogger_queue') || '[]');
queue.push({ payload, timestamp: Date.now() });
// Keep max 50 errors, expire after 24h
localStorage.setItem('applogger_queue', JSON.stringify(queue.slice(-50)));
```

4. **Rate Limiting (Token Bucket)**:
```javascript
// ~10 errors per minute max
class TokenBucket {
    constructor(capacity = 10, refillRate = 10/60) {
        this.tokens = capacity;
        this.lastRefill = Date.now();
    }
}
```

5. **Beacon API for Page Close**:
```javascript
window.addEventListener('beforeunload', () => {
    navigator.sendBeacon(url, JSON.stringify(queuedErrors));
});
```

### No console.log in Production

```javascript
// ✅ Use debug flag
if (config.debug) {
    console.log('[AppLogger]', message);
}

// ❌ NEVER in production code
console.log('Sending error...'); // BAD!
```

### JSDoc Comments for Public API

```javascript
/**
 * Captures an exception and sends it to AppLogger.
 * @param {Error} error - The error to capture
 * @param {Object} [context={}] - Additional context
 * @param {Object} [context.tags] - Key-value tags
 * @param {Object} [context.extra] - Extra data
 * @returns {Promise<boolean>} True if sent successfully
 */
export const captureException = async (error, context = {}) => { ... };
```

---

## JavaScript Build Process

The JS SDK is **bundled with the PHP package** (not a separate npm package).

### Build Output

```bash
npm run build
```

Produces:
- `assets/dist/logger.js` - ES module (for modern bundlers)
- `assets/dist/logger.umd.js` - UMD bundle (for script tags)

### Important: Commit Built Files

The built `assets/dist/*.js` files MUST be committed to the repository. They're distributed with the Composer package - there's no `npm install` during `composer require`.

```bash
# After JS changes:
npm run build
git add assets/dist/
git commit -m "Build JS SDK"
```

### Rollup Configuration

```javascript
// rollup.config.js
export default {
    input: 'assets/src/index.js',
    output: [
        { file: 'assets/dist/logger.js', format: 'es' },
        { file: 'assets/dist/logger.umd.js', format: 'umd', name: 'AppLogger' }
    ],
    // No external dependencies - bundle everything
};
```

---

## Symfony Integration Patterns

### Event Subscriber for Exceptions

```php
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: -100)]
public function onKernelException(ExceptionEvent $event): void
{
    // Priority -100 = run after other handlers
    // Don't interfere with error pages

    try {
        $this->apiClient->sendError($this->formatException($event->getThrowable()));
    } catch (\Throwable $e) {
        // Silent failure - never crash host app
    }
}
```

### Twig Extension for JS SDK

```php
// Provide Twig globals and functions
public function getGlobals(): array
{
    return [
        'application_logger_dsn' => $this->dsn,
        'application_logger_enabled' => $this->enabled,
    ];
}

public function getFunctions(): array
{
    return [
        new TwigFunction('application_logger_init', [$this, 'renderInitScript'], ['is_safe' => ['html']]),
    ];
}
```

### Auto-Injection via Response Subscriber

```php
#[AsEventListener(event: KernelEvents::RESPONSE, priority: -1000)]
public function onKernelResponse(ResponseEvent $event): void
{
    $response = $event->getResponse();

    // Only inject into HTML responses
    if (stripos($response->headers->get('Content-Type', ''), 'text/html') === false) {
        return;
    }

    // Inject before </body>
    $content = $response->getContent();
    $script = $this->generateInitScript();
    $response->setContent(str_replace('</body>', $script . '</body>', $content));
}
```

---

## Testing

### PHP Unit Tests

```php
class ApiClientTest extends TestCase
{
    public function testSendErrorDoesNotThrowOnFailure(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')->willThrowException(new TransportException());

        $client = new ApiClient($httpClient, $this->circuitBreaker, 'https://...');

        // Should NOT throw - silent failure
        $client->sendError(['message' => 'test']);
        $this->assertTrue(true); // If we got here, it passed
    }

    public function testCircuitBreakerOpensAfterFailures(): void
    {
        $circuitBreaker = new CircuitBreaker($this->cache, threshold: 3);

        $circuitBreaker->recordFailure();
        $circuitBreaker->recordFailure();
        $circuitBreaker->recordFailure();

        $this->assertTrue($circuitBreaker->isOpen());
    }
}
```

### JavaScript Tests

```javascript
// Use Jest or similar
describe('Transport', () => {
    test('queues errors when offline', async () => {
        // Mock fetch to fail
        global.fetch = jest.fn().mockRejectedValue(new Error('Network error'));

        const transport = new Transport({ dsn: 'https://...' });
        await transport.send({ message: 'test' });

        const queue = JSON.parse(localStorage.getItem('applogger_queue'));
        expect(queue).toHaveLength(1);
    });
});
```

---

## Configuration Schema

The bundle configuration is defined in `src/DependencyInjection/Configuration.php`:

```php
$rootNode
    ->children()
        ->scalarNode('dsn')
            ->isRequired()
            ->info('AppLogger DSN (https://public_key@host/project_id)')
        ->end()
        ->booleanNode('enabled')
            ->defaultTrue()
        ->end()
        ->floatNode('timeout')
            ->defaultValue(2.0)
            ->min(0.5)
            ->max(5.0)
            ->info('API timeout in seconds')
        ->end()
        ->arrayNode('circuit_breaker')
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')->defaultTrue()->end()
                ->integerNode('failure_threshold')->defaultValue(5)->end()
                ->integerNode('timeout')->defaultValue(60)->end()
            ->end()
        ->end()
        // ... more options
    ->end();
```

---

## Common Mistakes to Avoid

### 1. Throwing Exceptions

```php
// ❌ NEVER
throw new ApiException('Failed to send error');

// ✅ ALWAYS
$this->logger?->warning('Failed to send error', ['exception' => $e]);
return false;
```

### 2. Long Timeouts

```php
// ❌ NEVER - could block for 30+ seconds
$client = HttpClient::create(['timeout' => 30]);

// ✅ ALWAYS - 2 seconds max
$client = HttpClient::create(['timeout' => 2.0]);
```

### 3. Ignoring Circuit Breaker

```php
// ❌ NEVER - wastes resources when API is down
$this->httpClient->request('POST', $url, $data);

// ✅ ALWAYS - check first
if (!$this->circuitBreaker->isOpen()) {
    $this->httpClient->request('POST', $url, $data);
}
```

### 4. Leaking Sensitive Data

```php
// ❌ NEVER - sends passwords to API
$context = $request->request->all();

// ✅ ALWAYS - scrub first
$context = $this->scrubSensitiveData($request->request->all());
```

### 5. Synchronous Waiting

```php
// ❌ NEVER - blocks until response received
$response = $this->httpClient->request('POST', $url);
$content = $response->getContent(); // Waits for response

// ✅ ALWAYS - fire and forget
$this->httpClient->request('POST', $url, ['buffer' => false]);
// Returns immediately, request continues in background
```

---

## API Compatibility

When making changes, maintain backward compatibility:

- **Config options**: Add new options with sensible defaults
- **Service signatures**: Add optional parameters at the end
- **Twig functions**: Don't change existing function signatures
- **JS API**: `window.appLogger.*` methods should be stable

If breaking changes are required, increment the major version.

---

## Relationship to Main Platform

This bundle is developed in the AppLogger monorepo at `libraries/symfony-bundle/`. It's extracted to a separate repository for Packagist distribution via GitHub Actions.

- Main platform API spec: See `docs/API.md` in root
- Bundle must stay compatible with platform API
- Test against actual AppLogger instance when possible
