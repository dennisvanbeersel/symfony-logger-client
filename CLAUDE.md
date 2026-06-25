# CLAUDE.md - Symfony Bundle

This file provides guidance to Claude Code when working with the AppLogger Symfony Bundle client library.

## Overview

**Composer package:** `applogger/symfony-bundle` — the Symfony client bundle for the AppLogger error tracking and application monitoring platform.

This is a **client library** for the AppLogger error tracking platform. It enables Symfony applications to automatically capture and send errors to [applogger.eu](https://applogger.eu).

**v2.0 architecture:** The bundle is a thin Symfony adapter over `applogger/sdk-core`. Error capture routes through the SDK Hub; log aggregation routes through LogClient. The bundle handles Symfony-specific wiring (event subscribers, Monolog handler, Twig extension, DI) and delegates all transport/circuit-breaker/resilience concerns to the SDK core.

**Key Principle**: This bundle runs inside customer applications. **Never impact the host application's performance or stability.**

### What This Bundle Does

- Captures PHP exceptions and Monolog errors (error tracking pipeline), delegating to `applogger/sdk-core` Hub
- Ships non-exception Monolog records to **log aggregation** (ClickHouse via the
  Go log-collector) — a distinct, optional feature from error tracking, delegating to LogClient
- Provides a JavaScript SDK for frontend error tracking
- Sends errors/logs to AppLogger with resilience patterns (non-blocking, completed
  after the response via `kernel.terminate`)
- Collects breadcrumbs and context for debugging
- Sanitizes sensitive data (GDPR compliance)

### Technology Stack

- **PHP 8.3+** with strict types
- **Symfony 6.4 / 7.x / 8.x** bundle architecture (`^6.4|^7.0|^8.0`)
- **JavaScript ES6+** SDK (bundled, not separate npm package)
- **Rollup** for JS build (ESM + UMD outputs)

---

## Directory Structure

```
.
├── src/
│   ├── ApplicationLoggerBundle.php      # Bundle entry point
│   ├── DependencyInjection/
│   │   ├── ApplicationLoggerExtension.php  # Service registration + prepend (auto-wires Monolog handler + AssetMapper)
│   │   ├── Compiler/RemoveTwigServicesPass.php # Drops Twig services when Twig absent
│   │   └── Configuration.php               # Bundle config schema
│   ├── EventSubscriber/
│   │   ├── ExceptionSubscriber.php         # Catches uncaught exceptions
│   │   ├── FlushTelemetrySubscriber.php    # Drains async sends on kernel.terminate (post-response)
│   │   ├── SessionTrackingSubscriber.php   # Tracks user sessions
│   │   └── JavaScriptInjectionSubscriber.php # Auto-injects JS SDK
│   ├── Monolog/Handler/
│   │   └── ApplicationLoggerHandler.php    # Routes records: exceptions → errors, plain logs → log aggregation
│   ├── Service/
│   │   ├── ApiClient.php                   # Thin endpoint facade (errors + logs + sessions)
│   │   ├── Http/ResilientHttpDispatcher.php # Owns all transport: async/sync POST, retry, circuit breaker, flushAndComplete()
│   │   ├── BreadcrumbCollector.php         # Breadcrumb trail
│   │   ├── CircuitBreaker.php              # Circuit breaker pattern
│   │   ├── ContextCollector.php            # Request/user context
│   │   ├── DataScrubber.php                # Sensitive-data scrubbing (GDPR)
│   │   ├── ErrorPayloadFactory.php         # Builds error payloads from Throwables
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

### 4. Fire-and-Forget Mode (Non-Blocking + Reliable)

Default mode (`async: true`) - return immediately, never block the host request,
but still RELIABLY deliver telemetry.

```php
// ✅ Async mode - returns in < 1ms
$this->httpClient->request('POST', $url, [
    'buffer' => false,  // Don't wait for response body
]);
// Method returns immediately, request continues in background
```

**How reliable delivery works (changed: post-response completion):**

The transport lives in `Service/Http/ResilientHttpDispatcher`. In async mode
`post()` initiates the POST and does ONE non-blocking poll (`stream($response, 0.0)`)
to surface immediate connection failures (so the circuit breaker can trip), then
returns — adding ~0ms to the host request. The in-flight handle is retained in
`$pendingResponses`.

Completion happens AFTER the response is sent to the client:

- **Web (PHP-FPM / FrankenPHP non-worker / built-in server):**
  `FlushTelemetrySubscriber` listens on `kernel.terminate` (priority `-1024`) and
  calls `ApiClient::flush()` → `ResilientHttpDispatcher::flushAndComplete()`, which
  loops `stream()` until every handle reaches `isLast()` or the timeout expires.
  This runs after `fastcgi_finish_request()` / the runtime flush, so the user is
  never delayed. WITHOUT this, a per-request SAPI would GC-cancel the cURL handle
  before transmission and silently lose the event.
- **CLI / Messenger workers (no `kernel.terminate`):** `__destruct` calls
  `flushAndComplete()` with a bounded timeout as a fallback.

Each completed transfer records a deterministic circuit-breaker outcome
(2xx/3xx success; 4xx/5xx, timeout or transport error → failure). The whole path
is bounded by `timeout` and never throws into the host app.

**Same-host self-monitoring:** because the send completes after the response is
flushed (and the platform ingestion is disconnect-safe), it is safe to point the
bundle at the same host it runs on with `async: true`. Separate-host installs are
the norm and are always non-blocking.

**Post-response drain budget (`flush_budget`, default 2.0s):** the kernel.terminate
(and CLI `__destruct`) drain is bounded to `min(timeout, flush_budget)` wall-clock and
is skipped entirely when the circuit breaker is OPEN. The default (2.0s) matches the
previous hardcoded cap, so delivery is unchanged. **Lower `flush_budget` (e.g. 0.5s)
to harden a FrankenPHP worker pool** against a slow collector. A *healthy-but-slow*
collector (connected / 2xx headers) that exceeds the budget is dropped WITHOUT
tripping the breaker.

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

## Log Aggregation (Distinct From Error Tracking)

The bundle ships TWO independent pipelines through one auto-wired Monolog handler
(`Monolog/Handler/ApplicationLoggerHandler`):

- **Error tracking:** records carrying a `Throwable` (`context['exception']`) →
  `ApiClient::sendError()` → `POST {dsn-host}/api/v1/errors` (PostgreSQL, grouped/
  fingerprinted). Auth: `X-Api-Key: <api_key>`.
- **Log aggregation:** records WITHOUT an exception → buffered, then
  `ApiClient::sendLogs()` → `POST {log_endpoint}/v1/logs` (single) or
  `/v1/logs/batch` (`{"logs":[…]}`, max 1000) → the Go log-collector (ClickHouse).
  Auth: `X-Api-Key: <log_token>` (`sk_log_…`). Success = HTTP 202.

Key facts:

- **Auto-wired, zero-config.** `ApplicationLoggerExtension::prependMonolog()`
  registers the handler on channels `['!event', '!request', '!php']` (the excluded
  framework channels carry uncaught-exception logs already shipped by
  `ExceptionSubscriber`, avoiding double-recording). Customers do NOT edit
  `monolog.yaml`.
- **Config:** `log_endpoint` + `log_token` enable aggregation; `capture_level`
  (default `error`) is the minimum Monolog level handled; `log_batch_size`
  (default 50) and `max_log_buffer` (default 1000) bound buffering; `log_path`
  defaults to `/v1/logs`. If `log_endpoint`/`log_token` are null, `sendLogs()`
  silently no-ops (never an error).
- **Behavior:** batched, async, non-blocking — same `ResilientHttpDispatcher`
  guarantees as error tracking. Context is scrubbed and flattened to
  `map<string,string>`.
- **LogEntry contract:** `timestamp` (RFC3339), `severity` (syslog keyword),
  `message` (≤8000), `app_name` (channel, ≤255), `environment`, `context`
  (`channel` preserved inside it).

## Configuration Schema

The bundle configuration is defined in `src/DependencyInjection/Configuration.php`. Key design points:

- `dsn` and `api_key` default to `''` (empty string, **not required**). An empty value keeps the bundle inert; this is intentional so a skipped Flex recipe never breaks `cache:clear`.
- Several keys deprecated in v2.0 (`endpoint_path`, `log_path`, `log_batch_size`, `max_log_buffer`, `retry_attempts`, `async`, `circuit_breaker.enabled`) are accepted but no-op; the SDK core owns those concerns.
- `capture_level` is a plain `scalarNode` (not `enumNode`) to allow `%env()%` placeholders. Invalid literals fall back to `error` at runtime rather than throwing.

```php
$rootNode
    ->children()
        ->scalarNode('dsn')
            ->defaultValue('')          // Empty = inert (NOT required)
            ->info('AppLogger DSN (https://host/project-id). Empty = bundle stays inert.')
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
        ->floatNode('flush_budget')
            ->defaultValue(2.0)
            ->min(0.05)
            ->max(2.0)
            ->info('Wall-clock cap (s) on the post-response drain; min(timeout, flush_budget).')
        ->end()
        ->arrayNode('circuit_breaker')
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')->defaultTrue()->end()  // Deprecated no-op in v2.0
                ->integerNode('failure_threshold')->defaultValue(5)->end()
                ->integerNode('timeout')->defaultValue(60)->end()
            ->end()
        ->end()
        // ... more options — see Configuration.php for the complete schema
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
- **JS API**: `window.appLogger.*` methods should be stable. The auto-injected init script (`ScriptRenderer`/`init.html.twig`) imports the SDK as an ES module and assigns the ready instance to **`window.appLogger`** (lowercase) — that is the runtime global users call (`captureException`, `addBreadcrumb`, `setUser`, `sessionReplay`, …). `ApplicationLogger` is only the rollup UMD export *name* (used when loading `logger.umd.js` directly via `<script>` as `new window.ApplicationLogger(...)`); the bundle's own ESM injection never sets `window.ApplicationLogger`. Docs/examples for the bundle must use `window.appLogger`.

If breaking changes are required, increment the major version.

---

## Relationship to Main Platform

This bundle is developed in the AppLogger monorepo at `libraries/symfony-bundle/`. It's extracted to a separate repository for Packagist distribution via GitHub Actions.

- Main platform API spec: See `docs/API.md` in root
- Bundle must stay compatible with platform API
- Test against actual AppLogger instance when possible
