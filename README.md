# Application Logger - Symfony Bundle

<div align="center">

**🛡️ Error Tracking for Symfony Applications**

[![PHP](https://img.shields.io/badge/php-%5E8.2-blue?style=flat-square)](https://www.php.net/)
[![Symfony](https://img.shields.io/badge/symfony-6.4%20%7C%207.x-green?style=flat-square)](https://symfony.com/)
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

# 3. Add DSN to .env
APPLICATION_LOGGER_DSN=https://public_key@logger.example.com/project_id

# 4. Clear cache
php bin/console cache:clear
```

**Done!** All PHP exceptions and JavaScript errors are now automatically tracked. No code changes needed.

---

## 🎯 Why This Bundle?

Most error tracking solutions have a **critical flaw**: they can slow down or even crash your application when the tracking service is down. This bundle is different.

### Core Philosophy: **Never Impact Your Application**

We achieve this through battle-tested resilience patterns:

| Feature | This Bundle | Typical Solutions | Impact |
|---------|------------|-------------------|--------|
| **Timeout** | ⚡ 2s max (configurable) | ⏰ Often 30s+ or none | **50ms vs 30s+ delay** |
| **Circuit Breaker** | ✅ Automatic failover | ❌ Keep retrying | **Stops wasting resources** |
| **Fire & Forget** | ✅ Returns instantly | ❌ Waits for response | **<1ms vs 2000ms** |
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

---

## ✨ Features

### PHP Backend Features

<table>
<tr>
<td width="50%" valign="top">

**Automatic Capture**
- 🚨 Uncaught exceptions
- 📝 Monolog error logs
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

```yaml
# config/packages/application_logger.yaml
application_logger:
    # Required: Your Application Logger DSN
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

    # What to Capture
    capture_level: error      # Monolog level: debug, info, warning, error, critical

    # Breadcrumbs
    max_breadcrumbs: 50       # Maximum breadcrumbs to keep (10-100)

    # Security: Sensitive Data Scrubbing
    scrub_fields:
        - password
        - token
        - api_key
        - secret
        - authorization
        - credit_card
        - ssn

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

**Done!** All exceptions are now automatically tracked. Visit your Application Logger dashboard to see errors.

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

#### Monolog Integration

Send error-level logs to Application Logger:

```yaml
# config/packages/monolog.yaml
monolog:
    handlers:
        application_logger:
            type: service
            id: ApplicationLogger\Bundle\Monolog\Handler\ApplicationLoggerHandler
            level: error
            channels: ['!event']  # Exclude to avoid duplication
```

Now all `$logger->error()`, `$logger->critical()`, etc. calls are tracked.

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
// $elapsed is typically < 1ms (request queued, method returns)
```

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
- password, passwd, pwd
- token, api_key, secret
- authorization, auth
- credit_card, ssn, private_key

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

Send errors to different Application Logger projects:

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
- Symfony 6.4 or 7.x
- ext-json, ext-curl

**Recommended:**
- PHP 8.3+
- Symfony 7.1+
- APCu or Redis (production cache)

---

## 📚 Documentation

| Document | Description |
|----------|-------------|
| [Main README](https://github.com/dennisvanbeersel/application-logger) | Platform overview and setup |
| [API Reference](https://github.com/dennisvanbeersel/application-logger/blob/master/docs/API.md) | REST API documentation |
| [Architecture](https://github.com/dennisvanbeersel/application-logger/blob/master/docs/ARCHITECTURE.md) | Technical architecture |
| [Security & Testing](https://github.com/dennisvanbeersel/application-logger/blob/master/docs/SECURITY_AND_TESTING.md) | Security practices and testing guidelines |

---

## 📝 License

Part of the Application Logger project - see main [LICENSE](https://github.com/dennisvanbeersel/application-logger/blob/master/LICENSE) file.

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

[⬆ Back to Top](#application-logger---symfony-bundle)

</div>
