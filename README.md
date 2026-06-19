# AppLogger - Symfony Bundle

<div align="center">

**🛡️ Privacy-First Error Tracking for Symfony - Hosted in EU**

[![PHP](https://img.shields.io/badge/php-%5E8.2-blue?style=flat-square)](https://www.php.net/)
[![Symfony](https://img.shields.io/badge/symfony-6.4%20%7C%207.x%20%7C%208.x-green?style=flat-square)](https://symfony.com/)
[![License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)](https://github.com/dennisvanbeersel/application-logger/blob/master/LICENSE)
[![Tests](https://img.shields.io/badge/tests-passing-success?style=flat-square)](https://github.com/dennisvanbeersel/symfony-logger-client)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%206-success?style=flat-square)](https://github.com/dennisvanbeersel/symfony-logger-client)

*Resilience-first error tracking with integrated JavaScript SDK - your app never slows down* ⚡

[Quick Start](#-quick-start) •
[Why This Bundle?](#-why-this-bundle) •
[Features](#-features) •
[Documentation](#-documentation)

</div>

---

## 📦 TL;DR - Get Started in 2 Minutes

```bash
# 1. Install
composer require dennisvanbeersel/symfony-logger-client

# 2. Configure (config/packages/application_logger.yaml)
application_logger:
    dsn: '%env(APPLICATION_LOGGER_DSN)%'
    api_key: '%env(APPLICATION_LOGGER_API_KEY)%'

# 3. Add credentials to .env
APPLICATION_LOGGER_DSN=https://applogger.eu/your-project-uuid
APPLICATION_LOGGER_API_KEY=your-64-character-api-key-here

# 4. Clear cache
php bin/console cache:clear
```

**Done!** All PHP exceptions and JavaScript errors are now automatically tracked. No code changes needed.

---

## 🎯 Why This Bundle?

**AppLogger** ([applogger.eu](https://applogger.eu)) is an EU-hosted, privacy-first error tracking SaaS platform specifically designed for Symfony applications. This bundle provides zero-config integration with production-grade resilience.

Most error tracking solutions have a **critical flaw**: they can slow down or even crash your application when the tracking service is down. This bundle is different.

### Core Philosophy: **Never Impact Your Application**

We achieve this through battle-tested resilience patterns:

| Feature | This Bundle | Typical Solutions | Impact |
|---------|------------|-------------------|--------|
| **Timeout** | ⚡ 2s max (configurable) | ⏰ Often 30s+ or none | **50ms vs 30s+ delay** |
| **Circuit Breaker** | ✅ Automatic failover | ❌ Keep retrying | **Stops wasting resources** |
| **Fire & Forget** | ✅ Returns instantly, sends after response | ❌ Waits for response | **~0ms vs 2000ms** |
| **Reliable async delivery** | ✅ Completed on `kernel.terminate` | ⚠️ Lost under PHP-FPM | **No silent data loss** |
| **Exception Safety** | ✅ Never throws | ⚠️ Can crash app | **100% uptime guarantee** |
| **JS Offline Queue** | ✅ localStorage backup | ❌ Errors lost | **Zero data loss** |
| **JS Rate Limiting** | ✅ Token bucket | ❌ Can overwhelm API | **Protected from error storms** |

### Real-World Impact

**Without resilience patterns:**
```php
// API is down, timeout is 30s
$start = microtime(true);
errorTracker()->captureException($e);  // Blocks for 30 seconds!
$elapsed = microtime(true) - $start;   // 30,000ms
// User waited 30 seconds for page to load 😱
```

**With this bundle:**
```php
// API is down, circuit breaker is open
$start = microtime(true);
errorTracker()->captureException($e);  // Returns instantly
$elapsed = microtime(true) - $start;   // <1ms
// User doesn't notice anything 🎉
```

### Non-Blocking, Reliable Delivery (How `async: true` Works)

In the default fire-and-forget mode the bundle **never blocks the host request**,
yet still **reliably delivers** telemetry — even under per-request SAPIs like
PHP-FPM and FrankenPHP (non-worker mode), where naive fire-and-forget loses data:

1. **During the request:** the HTTP POST is *initiated* (a single non-blocking
   poll surfaces immediate connection failures so the circuit breaker can trip),
   then the method returns — adding ~0ms to the user-visible response.
2. **After the response is sent:** a `kernel.terminate` listener
   (`FlushTelemetrySubscriber`) drives the in-flight transfer to completion. This
   runs *after* Symfony has already flushed the response to the client
   (`fastcgi_finish_request()` under FPM, the runtime flush under FrankenPHP), so
   the user is never delayed. Without this step, per-request SAPIs would garbage-
   collect and abort the cURL handle before the request was transmitted, silently
   losing the event.
3. **CLI & Messenger workers:** there is no `kernel.terminate` there, so a bounded
   `__destruct` fallback drains any pending transfers on shutdown.

Each completed transfer feeds its outcome (2xx/3xx success, 4xx/5xx or
transport error failure) back into the circuit breaker, all bounded by the
configured `timeout` and never throwing into the host application.

> **Same-host self-monitoring:** because the send completes *after* the response
> is flushed, it is safe to point the bundle at the same host it runs on with
> `async: true`. Separate-host installs (the norm) are always non-blocking too.

---

## ✨ Features

### PHP Backend Features

<table>
<tr>
<td width="50%" valign="top">

**Automatic Capture**
- 🚨 Uncaught exceptions
- 📝 Monolog error logs
- 📡 Log aggregation (centralized app logs)
- 🔢 HTTP status codes (404, 500, etc.)
- 👤 User context from Symfony Security
- 📊 Request/response data
- 🍞 Breadcrumb trails

</td>
<td width="50%" valign="top">

**Resilience (Production-Grade)**
- ⚡ 2s timeout (configurable 0.5-5s)
- 🔌 Circuit breaker pattern
- 🔥 Fire-and-forget async mode
- 🔄 Optional smart retry (exponential backoff)
- ✅ Zero exceptions thrown
- 📊 Health monitoring

</td>
</tr>
<tr>
<td width="50%" valign="top">

**Security (GDPR Compliant)**
- 🔐 Automatic PII scrubbing
- 🌐 IP anonymization
- 🛡️ Secure DSN authentication
- 🔒 Encrypted in transit (HTTPS)
- 📋 Customizable scrub fields
- 🚫 No sensitive data leaks

</td>
<td width="50%" valign="top">

**Developer Experience**
- 🎯 Zero configuration needed
- 📦 Works out of the box
- 🔧 Highly customizable
- 🐛 Built-in debug mode
- 📊 Circuit breaker monitoring
- 📚 Comprehensive docs

</td>
</tr>
</table>

### JavaScript SDK Features (Included!)

> **No separate npm package needed!** The JavaScript SDK is bundled with this Symfony bundle.

<table>
<tr>
<td width="50%" valign="top">

**Automatic Capture**
- 🌐 Window errors (uncaught exceptions)
- ❌ Unhandled promise rejections
- 🔢 HTTP status codes from failed API calls
- 👤 User context (auto-synced from backend)
- 📊 Browser/platform detection
- 🍞 Navigation and user actions

</td>
<td width="50%" valign="top">

**Resilience (Client-Side)**
- ⚡ 3s timeout with AbortController
- 🔌 Circuit breaker (sessionStorage)
- 💾 Offline queue (localStorage, 50 errors)
- 🚦 Rate limiting (token bucket, 10/min)
- ⚖️ Deduplication (prevents spam)
- 📡 Beacon API (send on page close)

</td>
</tr>
</table>

---

## 📊 What Gets Tracked?

This bundle provides comprehensive monitoring for both backend and frontend:

### PHP Backend Tracking
- **Exceptions**: All uncaught exceptions via Symfony event subscriber
- **HTTP Errors**: 4xx and 5xx status codes (404, 500, etc.)
- **Monolog Integration**: Error-level logs when configured
- **Context**: Request/response data, user context, server metadata
- **Breadcrumbs**: User actions leading up to errors

### JavaScript Frontend Tracking
- **Browser Errors**: Uncaught exceptions and unhandled promise rejections
- **API Failures**: Failed HTTP requests with status codes
- **Error-Triggered Session Replay**: Captures user actions (30s/10 clicks before and after errors) with DOM snapshots
- **Session Tracking**: Page views, navigation flows, session duration
- **User Context**: Browser, platform, screen resolution

### GDPR Compliance
- **Automatic PII Scrubbing**: Passwords, tokens, sensitive fields removed
- **IP Anonymization**: Last octet masked (192.168.1.0 instead of 192.168.1.100)
- **Session Hashing**: User identifiers are cryptographically hashed
- **EU Data Residency**: All data stored in EU datacenters

---

## 📡 Log Aggregation (Centralized Application Logs)

Beyond error tracking, this bundle can ship your **application logs** (any Monolog
record) to AppLogger's **log aggregation** backend — a Papertrail/Loggly-style
centralized logging service backed by ClickHouse via the AppLogger log-collector.
This is a **distinct, optional feature** from error/exception tracking:

| | Error tracking | Log aggregation |
|---|----------------|-----------------|
| **What** | Exceptions, grouped & fingerprinted | Plain application log lines |
| **Triggered by** | A Monolog record with an attached `Throwable` | A Monolog record **without** an exception |
| **Endpoint** | `POST {dsn-host}/api/v1/errors` | `POST {log_endpoint}/v1/logs` (+ `/batch`) |
| **Auth** | `X-Api-Key: <api_key>` | `X-Api-Key: <log_token>` (`sk_log_…`) |
| **Storage** | PostgreSQL (error groups) | ClickHouse (high-throughput) |

The single auto-wired Monolog handler routes each record automatically: records
**with** an exception go to the error pipeline; records **without** one go to log
aggregation. If log aggregation is not configured, plain log records are simply
**not shipped anywhere** (the handler silently no-ops — it never errors).

### Enabling Log Aggregation

Add a log endpoint and ingestion token (from your AppLogger dashboard) — that's it:

```yaml
# config/packages/application_logger.yaml
application_logger:
    # ... error tracking config (dsn, api_key) ...

    # Log aggregation (optional)
    log_endpoint: '%env(APPLICATION_LOGGER_LOG_ENDPOINT)%'  # e.g. https://your-slug.logs.applogger.eu
    log_token: '%env(APPLICATION_LOGGER_LOG_TOKEN)%'        # sk_log_…

    # Minimum Monolog level shipped (debug|info|notice|warning|error|critical|alert|emergency)
    capture_level: info
```

```bash
# .env.local
APPLICATION_LOGGER_LOG_ENDPOINT=https://your-slug.logs.applogger.eu
APPLICATION_LOGGER_LOG_TOKEN=sk_log_xxxxxxxxxxxxxxxxxxxxxxxx
```

Now every `$logger->info()`, `$logger->warning()`, etc. (at or above
`capture_level`) is shipped to log aggregation. Records carrying an exception keep
going to error tracking.

> **Zero manual wiring.** The bundle auto-registers its Monolog handler via a
> container prepend, so you do **not** need to add anything to `monolog.yaml`. The
> handler is attached to all channels except `event`, `request` and `php` (those
> framework channels carry uncaught-exception logs already shipped by the
> exception subscriber, so excluding them avoids double-recording).

### Behavior

- **Batched & async:** records are buffered and flushed in a single HTTP request
  (default `log_batch_size: 50`), using the **same dispatcher and guarantees** as
  error tracking — non-blocking, completed after the response, circuit-breaker
  protected, never throwing.
- **Memory-bounded:** at most `max_log_buffer` records (default 1000) are buffered;
  the oldest are dropped beyond that.
- **Batch cap:** the collector hard-caps batches at **1000** entries; larger
  buffers are chunked defensively.
- **Sensitive data scrubbed:** log context is run through the same scrubber as
  errors, then flattened to a string map for the collector.

### The LogEntry Contract

Each shipped record maps to a collector `LogEntry`:

| Field | Source | Notes |
|-------|--------|-------|
| `timestamp` | record datetime | RFC 3339 / ATOM |
| `severity` | Monolog level | syslog keyword (`debug`…`emergency`) |
| `message` | record message | truncated to 8000 chars |
| `app_name` | Monolog channel | truncated to 255 chars |
| `environment` | `environment` config | e.g. `production` |
| `context` | record context | scrubbed, flattened to `map<string,string>` (original channel preserved as `context.channel`) |

Single records `POST {log_endpoint}/v1/logs`; multiple records
`POST {log_endpoint}/v1/logs/batch` with body `{"logs": [LogEntry, …]}`. A
successful ingestion returns **HTTP 202 Accepted**.

### Log Aggregation Only (No Error Tracking)

`dsn` and `api_key` are still required by the bundle (they configure the error
client), but if you only want centralized logs, set a high `capture_level` so
routine logs flow to aggregation while leaving error tracking effectively idle —
or simply enable both. A typical "logs + errors" setup:

```yaml
application_logger:
    dsn: '%env(APPLICATION_LOGGER_DSN)%'
    api_key: '%env(APPLICATION_LOGGER_API_KEY)%'

    # Error tracking is automatic (exceptions → /api/v1/errors)

    # Log aggregation for everything info-and-above
    log_endpoint: '%env(APPLICATION_LOGGER_LOG_ENDPOINT)%'
    log_token: '%env(APPLICATION_LOGGER_LOG_TOKEN)%'
    capture_level: info
```

---

## 🚀 Quick Start

### Installation

```bash
composer require dennisvanbeersel/symfony-logger-client
```

If you're not using Symfony Flex, register the bundle in `config/bundles.php`:

```php
return [
    // ...
    ApplicationLogger\Bundle\ApplicationLoggerBundle::class => ['all' => true],
];
```

### Configuration

#### Minimal Configuration (Recommended)

```yaml
# config/packages/application_logger.yaml
application_logger:
    dsn: '%env(APPLICATION_LOGGER_DSN)%'
```

Add to `.env`:

```bash
APPLICATION_LOGGER_DSN=https://public_key@logger.example.com/project_id
APP_VERSION=1.0.0  # Optional but recommended
```

#### Full Configuration Example

<details>
<summary><strong>Click to see all available options</strong></summary>

> **Source of truth:** the canonical, installed configuration is the Symfony Flex
> recipe at `recipe/config/packages/application_logger.yaml` (and its versioned
> copy under `recipe/contrib/.../config/`). The snippet below and
> `config/packages/application_logger.yaml.example` are illustrative mirrors —
> defaults (e.g. `scrub_fields`) are authoritatively defined in
> `src/DependencyInjection/Configuration.php`. If they ever disagree, the recipe
> and `Configuration.php` win.

```yaml
# config/packages/application_logger.yaml
application_logger:
    # Required: Your AppLogger DSN (get from applogger.eu dashboard)
    dsn: '%env(APPLICATION_LOGGER_DSN)%'

    # Optional: Enable/disable the bundle
    enabled: true

    # Optional: Application version for release tracking
    release: '%env(APP_VERSION)%'

    # Optional: Environment identifier
    environment: '%kernel.environment%'

    # Resilience Settings
    timeout: 2.0              # API timeout (0.5-5.0 seconds)
    retry_attempts: 0         # Retry failed requests (0-3, 0=fail fast)
    async: true               # Fire-and-forget mode (recommended)

    # Circuit Breaker
    circuit_breaker:
        enabled: true         # Enable circuit breaker pattern
        failure_threshold: 5  # Open after N consecutive failures
        timeout: 60           # Stay open for N seconds
        half_open_attempts: 1 # Test requests before closing

    # What to Capture (minimum Monolog level routed by the handler)
    capture_level: error      # debug, info, notice, warning, error, critical, alert, emergency

    # Log Aggregation (optional) - ship non-exception logs to centralized logging.
    # Leave null to disable; plain log records are then not shipped anywhere.
    log_endpoint: null        # e.g. https://your-slug.logs.applogger.eu
    log_token: null           # log ingestion token (sk_log_…)
    log_path: '/v1/logs'      # collector ingestion path (batch is this + "/batch")
    log_batch_size: 50        # buffer N records before flushing one batch (1-1000)
    max_log_buffer: 1000      # hard cap on buffered records (1-10000)

    # Error ingestion path on the platform API
    endpoint_path: '/api/v1/errors'

    # Breadcrumbs
    max_breadcrumbs: 50       # Maximum breadcrumbs to keep (10-100)

    # Security: Sensitive Data Scrubbing (these are the built-in defaults)
    scrub_fields:
        - password
        - token
        - api_key
        - secret
        - authorization
        - credit_card
        - creditcard
        - card_number
        - cvv
        - ssn
        - iban

    # Session Tracking (Required for session replay)
    session_tracking:
        enabled: true              # Enable automatic session tracking (default: true)
        track_page_views: true     # Track page views as session events (default: true)
        idle_timeout: 1800         # Session idle timeout in seconds (default: 30 min)

    # Error-Triggered Session Replay
    session_replay:
        enabled: true                      # Enable session replay (default: true)
        buffer_before_error_seconds: 30    # Seconds to buffer before error (5-60, default: 30)
        buffer_before_error_clicks: 10     # Clicks to buffer before error (1-15, default: 10)
        buffer_after_error_seconds: 30     # Seconds to buffer after error (5-60, default: 30)
        buffer_after_error_clicks: 10      # Clicks to buffer after error (1-15, default: 10)
        click_debounce_ms: 1000            # Click debounce delay (100-5000ms, default: 1000)
        snapshot_throttle_ms: 1000         # DOM snapshot throttle (500-5000ms, default: 1000)
        max_snapshot_size: 1048576         # Max snapshot size in bytes (default: 1MB)
        session_timeout_minutes: 30        # Cross-page session timeout (5-120 min, default: 30)
        max_buffer_size_mb: 5              # Max localStorage size (1-20MB, default: 5MB)
        expose_api: true                   # Expose JS API for user control (default: true)

    # JavaScript SDK
    javascript:
        enabled: true         # Enable Twig globals for JS SDK
        auto_inject: true     # Auto-inject init script (recommended)
        debug: false          # Enable console.log debugging

    # Debug
    debug: '%kernel.debug%'   # Enable internal logging
```

</details>

### Clear Cache

```bash
php bin/console cache:clear
```

**Done!** All exceptions are now automatically tracked. Visit your AppLogger dashboard at [applogger.eu](https://applogger.eu) to see errors.

---

## 📖 Usage

### 1️⃣ PHP Backend Usage

#### Automatic Capture (Zero Code Changes)

The bundle automatically captures:

- ✅ **Uncaught exceptions** via Symfony event subscriber
- ✅ **HTTP status codes** (404, 403, 500, etc.)
- ✅ **Monolog error logs** (when configured)
- ✅ **User context** from Symfony Security
- ✅ **Request data** (headers, POST data, query params)

**No code changes required!** Just install and configure.

#### Monolog Integration (Auto-Wired)

The bundle **automatically registers its Monolog handler** for you — there is
**no need to edit `monolog.yaml`**. On install, every Monolog record is routed:

- records carrying an exception → **error tracking** (`/api/v1/errors`)
- plain records (no exception), at or above `capture_level` → **log aggregation**
  (when `log_endpoint`/`log_token` are configured; otherwise silently dropped)

So `$logger->error('...', ['exception' => $e])` is tracked as an error, while
`$logger->info('User signed in')` is shipped to log aggregation. The handler is
attached to all channels except `event`, `request` and `php` (those carry
uncaught-exception logs already shipped by the exception subscriber).

Control the floor with `capture_level`:

```yaml
# config/packages/application_logger.yaml
application_logger:
    capture_level: info  # debug|info|notice|warning|error|critical|alert|emergency
```

> See [Log Aggregation](#-log-aggregation-centralized-application-logs) for
> shipping plain logs to centralized logging.

#### Manual Error Capture

For custom error handling:

```php
use ApplicationLogger\Bundle\Service\ApiClient;
use ApplicationLogger\Bundle\Service\BreadcrumbCollector;

class PaymentService
{
    public function __construct(
        private ApiClient $apiClient,
        private BreadcrumbCollector $breadcrumbs
    ) {}

    public function processPayment(Order $order): void
    {
        // Add breadcrumb for context
        $this->breadcrumbs->add([
            'type' => 'user',
            'category' => 'payment',
            'message' => 'Processing payment',
            'data' => ['order_id' => $order->getId()],
        ]);

        try {
            $this->chargeCustomer($order);
        } catch (\Exception $e) {
            // Manual error reporting
            $this->apiClient->sendError([
                'exception' => [
                    'type' => $e::class,
                    'value' => $e->getMessage(),
                    'stacktrace' => $this->formatStackTrace($e),
                ],
                'level' => 'error',
                'tags' => ['feature' => 'payment'],
            ]);

            throw $e; // Re-throw if needed
        }
    }
}
```

#### Adding Breadcrumbs

Track user actions leading up to errors:

```php
use ApplicationLogger\Bundle\Service\BreadcrumbCollector;

class CheckoutController extends AbstractController
{
    public function __construct(
        private BreadcrumbCollector $breadcrumbs
    ) {}

    #[Route('/checkout/step-1')]
    public function step1(): Response
    {
        $this->breadcrumbs->add([
            'type' => 'navigation',
            'category' => 'checkout',
            'message' => 'User entered checkout',
            'level' => 'info',
        ]);

        // ... your code
    }
}
```

### 2️⃣ JavaScript SDK Usage

#### Zero-Config Mode (Automatic) ⭐ Recommended

**Default behavior - no setup needed!**

The bundle automatically:
1. ✅ Registers JS SDK with AssetMapper
2. ✅ Injects initialization script on all HTML pages
3. ✅ Configures with your DSN
4. ✅ Sets environment and release
5. ✅ Populates user context
6. ✅ Makes `window.appLogger` available

**Just install the bundle - JavaScript tracking works immediately!**

#### Manual Mode (Custom Control)

If you want control over when/where the SDK loads:

```yaml
# config/packages/application_logger.yaml
application_logger:
    javascript:
        auto_inject: false  # Disable automatic injection
```

Then manually add to your templates:

```twig
{# templates/base.html.twig #}
<!DOCTYPE html>
<html>
<body>
    {% block body %}{% endblock %}

    {# Manually place the initialization script #}
    {{ application_logger_init() }}
</body>
</html>
```

#### Using the JavaScript SDK

Once loaded, use `window.appLogger`:

```javascript
// Capture exceptions
try {
    riskyOperation();
} catch (error) {
    window.appLogger.captureException(error, {
        tags: { component: 'checkout' },
        extra: { orderId: 12345 }
    });
}

// Capture messages
window.appLogger.captureMessage('Payment processed', 'info');

// Add breadcrumbs
window.appLogger.addBreadcrumb({
    type: 'user',
    message: 'User clicked checkout button',
    data: { cartTotal: 99.99 }
});

// Set user context
window.appLogger.setUser({
    id: 'user-123',
    email: 'user@example.com'
});

// Check circuit breaker status
window.appLogger.transport.getStats();
// {queueSize: 0, rateLimitTokens: 9.2, circuitBreaker: {state: 'closed'}}
```

---

## 🛡️ Resilience Features Explained

### Circuit Breaker Pattern

**Problem:** When the API is down, your app wastes resources retrying.

**Solution:** Circuit breaker with three states:

```
CLOSED (normal) → [5 failures] → OPEN (service down)
                                      ↓
                               [60 seconds wait]
                                      ↓
CLOSED ← [success] ← HALF_OPEN ← [timeout passed]
         [failure] → OPEN
```

**PHP Implementation:**
- Uses Symfony Cache for state persistence
- After 5 consecutive failures → opens for 60 seconds
- While OPEN: all API calls skip immediately (zero overhead)
- After 60s: enters HALF_OPEN, tries 1 request
- Success → CLOSED, failure → OPEN for another 60s

**JavaScript Implementation:**
- Uses sessionStorage for state persistence
- Same 3-state logic as PHP
- Prevents browser from hitting failing API

**Monitoring:**

```php
// PHP
$state = $apiClient->getCircuitBreakerState();
// ['state' => 'closed', 'failureCount' => 2, 'openedAt' => null]
```

```javascript
// JavaScript
window.appLogger.transport.circuitBreaker.getState();
// {state: 'closed', failureCount: 0, openedAt: null}
```

### Timeout Protection

**PHP:**
- Maximum 2 seconds per API call (configurable 0.5-5s)
- Configured at HTTP client level
- After timeout: connection aborted, circuit breaker records failure

**JavaScript:**
- Maximum 3 seconds per API call
- Uses `AbortController` to forcefully abort
- After timeout: error queued to localStorage

### Fire-and-Forget Mode (PHP)

When `async: true` (default):

```php
// With async: false (synchronous)
$start = microtime(true);
$apiClient->sendError($payload);
$elapsed = microtime(true) - $start;
// $elapsed could be 2000ms (full timeout)

// With async: true (fire-and-forget)
$start = microtime(true);
$apiClient->sendError($payload);
$elapsed = microtime(true) - $start;
// $elapsed is typically < 1ms (request initiated, method returns)
```

**Reliable post-response completion:** the request is *initiated* during your
request (adding ~0ms) and *completed* on `kernel.terminate` — after Symfony has
already sent the response to the client. This makes async delivery reliable even
on per-request SAPIs (PHP-FPM, FrankenPHP non-worker mode) where a naive fire-
and-forget request would be aborted before transmission. CLI commands and
Messenger consumers fall back to a bounded `__destruct` drain on shutdown. The
host request is never delayed. (See
[Non-Blocking, Reliable Delivery](#non-blocking-reliable-delivery-how-async-true-works).)

### Offline Queue (JavaScript)

When API is unreachable:
1. Errors stored in localStorage (FIFO queue)
2. Maximum 50 errors (oldest removed first)
3. Errors expire after 24 hours
4. On next successful connection: queue automatically flushed

**Handles quota errors gracefully:**
- If localStorage full → removes oldest 50%
- If still full → clears entire queue

### Rate Limiting (JavaScript)

Token bucket algorithm prevents error storms:
- **Capacity:** 10 tokens
- **Refill rate:** ~1 token per 6 seconds (~10 per minute)
- **Behavior:** No tokens → error goes to offline queue

```javascript
window.appLogger.transport.getStats();
// {rateLimitTokens: 8.5, queueSize: 0, ...}
```

### Deduplication (JavaScript)

Prevents sending the same error repeatedly:
- Creates hash from: error type + message + top 3 stack frames
- Remembers recently sent errors for 5 seconds
- Duplicate detected → ignored

### Beacon API (JavaScript)

**Problem:** When user closes tab, errors in queue are lost.

**Solution:** `navigator.sendBeacon()` API
- Listens to `beforeunload` and `visibilitychange`
- Flushes up to 10 most recent errors
- Guaranteed delivery even as page closes

---

## 🔒 Security Features

### Automatic Data Scrubbing

Sensitive data automatically removed from error reports:

**Default scrubbed fields:**
- password, token, api_key, secret, authorization
- credit_card, creditcard, card_number, cvv, ssn, iban

**How it works:**
- Recursive key check (case-insensitive substring matching)
- Replaces values with `[REDACTED]`
- Applies to: request data, headers, cookies, extra context

**Example:**

```php
$request->request->all();
// ['email' => 'user@example.com', 'password' => 'secret123']

// Sent to API as:
// ['email' => 'user@example.com', 'password' => '[REDACTED]']
```

**Custom scrub fields:**

```yaml
application_logger:
    scrub_fields:
        - password
        - credit_card
        - my_custom_secret
```

### IP Address Anonymization

**IPv4:** Masks last octet
```
192.168.1.100 → 192.168.1.0
```

**IPv6:** Masks last 80 bits
```
2001:0db8:85a3:0000:0000:8a2e:0370:7334
→ 2001:0db8:85a3:0000:0000:0000:0000:0000
```

**Why:** GDPR compliance - IP addresses are personal data.

---

## 🔧 Advanced Configuration

### Disable in Development

```yaml
# config/packages/dev/application_logger.yaml
application_logger:
    enabled: false
```

Or use `.env.local`:

```bash
APPLICATION_LOGGER_ENABLED=false
```

### Multiple Projects

Send errors to different AppLogger projects:

```yaml
# config/services.yaml
services:
    app.logger.project_a:
        class: ApplicationLogger\Bundle\Service\ApiClient
        arguments:
            $dsn: '%env(LOGGER_DSN_PROJECT_A)%'
            $timeout: 2.0
            $circuitBreaker: '@ApplicationLogger\Bundle\Service\CircuitBreaker'

    app.logger.project_b:
        class: ApplicationLogger\Bundle\Service\ApiClient
        arguments:
            $dsn: '%env(LOGGER_DSN_PROJECT_B)%'
            $timeout: 2.0
            $circuitBreaker: '@ApplicationLogger\Bundle\Service\CircuitBreaker'
```

### Custom Error Handler

```php
use ApplicationLogger\Bundle\Service\ApiClient;
use ApplicationLogger\Bundle\Service\BreadcrumbCollector;
use ApplicationLogger\Bundle\Service\ContextCollector;

class CustomErrorHandler
{
    public function __construct(
        private ApiClient $apiClient,
        private ContextCollector $contextCollector,
        private BreadcrumbCollector $breadcrumbs
    ) {}

    public function handleBusinessError(BusinessException $e): void
    {
        $this->apiClient->sendError([
            'exception' => [
                'type' => $e::class,
                'value' => $e->getMessage(),
                'stacktrace' => $this->formatTrace($e),
            ],
            'level' => 'warning', // Business errors are warnings
            'context' => $this->contextCollector->collectContext(),
            'breadcrumbs' => $this->breadcrumbs->get(),
            'tags' => [
                'error_type' => 'business',
                'rule' => $e->getBusinessRule(),
            ],
        ]);
    }
}
```

---

## 🐛 Troubleshooting

<details>
<summary><strong>Errors Not Appearing in Dashboard</strong></summary>

**1. Check bundle is enabled:**
```bash
php bin/console debug:config application_logger
```

**2. Check DSN is correct:**
```bash
php bin/console debug:container --parameters | grep application_logger.dsn
```

**3. Check circuit breaker:**
```php
$cbState = $this->apiClient->getCircuitBreakerState();
// If state is 'open', wait 60s or clear cache
```

**4. Enable debug mode:**
```yaml
application_logger:
    debug: true
```
Check `var/log/dev.log` for details.

</details>

<details>
<summary><strong>Circuit Breaker Stuck Open</strong></summary>

**Solution 1:** Wait for timeout (default 60 seconds)

**Solution 2:** Clear cache:
```bash
php bin/console cache:clear
```

**Solution 3:** Manually reset:
```php
$cache->delete('app_logger_circuit_breaker_state');
```

</details>

<details>
<summary><strong>JavaScript SDK Not Loading</strong></summary>

**1. Check AssetMapper:**
```bash
php bin/console debug:asset-map | grep application-logger
```

**2. Check browser console** for import errors

**3. Verify meta tag exists:**
```html
<meta name="app-logger-dsn" content="https://...">
```

</details>

<details>
<summary><strong>DSN Format Error</strong></summary>

**Correct format:**
```
https://public_key@your-host.com/project_id
```

**Common mistakes:**
```
❌ http://public_key@host/project       (use https://)
❌ https://host/project                 (missing public_key@)
❌ https://public_key:secret@host/proj  (secret not needed)
❌ https://public_key@host              (missing /project_id)
```

</details>

---

## 🛠️ Development

### Code Quality

```bash
composer lint        # PHP-CS-Fixer + PHPStan
composer cs-check    # Check PSR-12
composer cs-fix      # Auto-fix PSR-12
composer phpstan     # Static analysis (level 6)
npm run lint         # ESLint
npm run lint:fix     # Auto-fix ESLint
```

### Testing

```bash
# PHP tests
composer test
vendor/bin/phpunit

# JavaScript tests
npm test
npm run test:coverage
```

### Requirements

**Minimum:**
- PHP 8.2+
- Symfony 6.4, 7.x or 8.x
- ext-json, ext-curl

**Recommended:**
- PHP 8.3+
- Symfony 7.1+
- APCu or Redis (production cache)

---

## 📚 Documentation

| Document | Description |
|----------|-------------|
| [AppLogger Website](https://applogger.eu) | Sign up and get your DSN |
| [API Reference](https://github.com/dennisvanbeersel/application-logger/blob/master/docs/API.md) | REST API documentation |
| [Architecture](https://github.com/dennisvanbeersel/application-logger/blob/master/docs/ARCHITECTURE.md) | Technical architecture |
| [Security & Testing](https://github.com/dennisvanbeersel/application-logger/blob/master/docs/SECURITY_AND_TESTING.md) | Security practices and testing guidelines |

---

## 📝 License

Part of the AppLogger project - see main [LICENSE](https://github.com/dennisvanbeersel/application-logger/blob/master/LICENSE) file.

---

## 🙏 Credits

**Key Design Principles:**
1. **Resilience first** - never impact the host application
2. **Secure by default** - no sensitive data exposure
3. **Zero configuration** - works out of the box
4. **Production ready** - battle-tested patterns
5. **Developer friendly** - comprehensive docs

Built with ❤️ for the Symfony community.

---

<div align="center">

**Questions? Issues? Feedback?**

[📖 Documentation](https://github.com/dennisvanbeersel/application-logger/tree/master/docs) •
[🐛 Report Bug](https://github.com/dennisvanbeersel/symfony-logger-client/issues) •
[💬 Discussions](https://github.com/dennisvanbeersel/application-logger/discussions)

[⬆ Back to Top](#applogger---symfony-bundle)

</div>
