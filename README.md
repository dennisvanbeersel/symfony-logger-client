# AppLogger Symfony Client

> Symfony bundle for the [AppLogger](https://applogger.eu) error tracking and application monitoring platform — automatic exception capture, log aggregation, a frontend JavaScript SDK, and non-blocking, resilient delivery.

[![Packagist Version](https://img.shields.io/packagist/v/dennisvanbeersel/symfony-logger-client.svg?style=flat-square)](https://packagist.org/packages/dennisvanbeersel/symfony-logger-client)
[![Total Downloads](https://img.shields.io/packagist/dt/dennisvanbeersel/symfony-logger-client.svg?style=flat-square)](https://packagist.org/packages/dennisvanbeersel/symfony-logger-client)
[![PHP Version](https://img.shields.io/packagist/php-v/dennisvanbeersel/symfony-logger-client.svg?style=flat-square)](https://packagist.org/packages/dennisvanbeersel/symfony-logger-client)
[![License](https://img.shields.io/packagist/l/dennisvanbeersel/symfony-logger-client.svg?style=flat-square)](https://github.com/dennisvanbeersel/symfony-logger-client/blob/main/LICENSE)

---

## What is this?

This package is the Symfony client bundle for **[AppLogger](https://applogger.eu)** (`applogger.eu`), an EU-hosted, privacy-first error tracking and application/log monitoring SaaS built for Symfony and PHP applications.

Once installed and enabled, the bundle wires itself into your application and ships telemetry to the AppLogger platform through three channels:

- **Error tracking** — uncaught exceptions and exception-bearing Monolog records are sent to the platform, where they are grouped and fingerprinted into error groups (backed by PostgreSQL).
- **Log aggregation** — non-exception Monolog records at or above your configured capture level are batched and forwarded to the AppLogger log collector (backed by ClickHouse).
- **Frontend errors** — a bundled JavaScript SDK captures browser exceptions, unhandled promise rejections, and failed requests, with optional error-triggered session replay.

A central design goal of the bundle is that **telemetry delivery never delays or breaks the host application**. Sending is asynchronous and fire-and-forget by default, completes after the HTTP response has already been flushed to the user, and is guarded end-to-end so that no transport, encoding, or configuration failure propagates into your application's request handling.

The platform provides EU data residency, IP anonymization, PII scrubbing, and session-id hashing to support GDPR-conscious deployments.

---

## Features

- **Automatic exception capture** — uncaught exceptions are captured via an event subscriber (no manual instrumentation required).
- **Automatic Monolog capture** — any Monolog record carrying a `\Throwable` in its context is routed to error tracking; other records at or above your capture level are routed to log aggregation. Wiring is done automatically — you do not edit `monolog.yaml`.
- **Non-blocking, resilient delivery** — asynchronous transport with a per-pipeline circuit breaker; delivery is completed on `kernel.terminate` (after the response is sent), with a bounded fallback for CLI and Messenger workers.
- **Never throws into the host** — the dispatch path is wrapped so transport, encoding, and configuration failures are recorded internally rather than surfaced to your code.
- **Log aggregation** — buffered, batched log shipping to the AppLogger log collector with memory-bounded buffers.
- **Bundled JavaScript SDK** — frontend error capture with its own client-side circuit breaker, offline queue, rate limiting, and deduplication. Optional automatic injection via Twig.
- **Error-triggered session replay (opt-in)** — captures DOM snapshots and interactions around an error; sent together with the error payload.
- **GDPR-oriented data scrubbing** — key-based redaction of sensitive fields, query-string and URL credential redaction, and IP anonymization, all performed before data leaves your host.
- **Inert-by-default install** — auto-registers via Symfony Flex and ships disabled until you opt in; installs cleanly even in API-only apps without Twig.

---

## Requirements

The Composer-enforced runtime requirements are:

| Requirement | Constraint |
|-------------|------------|
| PHP | `>=8.2` |
| `symfony/framework-bundle` | `^6.4 \|\| ^7.0 \|\| ^8.0` |
| `symfony/http-kernel` | `^6.4 \|\| ^7.0 \|\| ^8.0` |
| `symfony/monolog-bundle` | `^3.0 \|\| ^4.0` |
| `symfony/http-client` | `^6.4 \|\| ^7.0 \|\| ^8.0` |
| `symfony/uid` | `^6.4 \|\| ^7.0 \|\| ^8.0` |

> **Note:** The JavaScript SDK is shipped pre-built in `assets/dist/`, so a Node.js toolchain is **not** required to use it. `symfony/asset-mapper`, `symfony/twig-bundle`, and `twig/twig` are development dependencies of this package — their absence in your application is tolerated, and the bundle remains fully functional. AssetMapper integration is wired automatically when AssetMapper is present; the Twig-based JS auto-injection is simply removed when Twig is not installed.

---

## Installation

Install with Composer:

```bash
composer require dennisvanbeersel/symfony-logger-client
```

### With Symfony Flex (recommended)

If your application uses Symfony Flex, the bundle is registered automatically and a Flex recipe is applied. The recipe:

- registers `ApplicationLogger\Bundle\ApplicationLoggerBundle` for all environments,
- installs a `config/packages/application_logger.yaml` configuration file,
- appends environment-variable placeholders to your `.env`:

```dotenv
APPLICATION_LOGGER_DSN=https://your-logger-host.com/your-project-id
APPLICATION_LOGGER_API_KEY=your-api-key-here
APPLICATION_LOGGER_ENABLED=false
```

**The bundle ships inert after installation.** Both PHP error tracking and the JavaScript SDK are gated on `APPLICATION_LOGGER_ENABLED`, which the recipe sets to `false`. To start sending telemetry, set your real DSN and API key (preferably in `.env.local`) and flip the flag:

```dotenv
# .env.local
APPLICATION_LOGGER_DSN=https://<your-host>/<your-project-id>
APPLICATION_LOGGER_API_KEY=<your-api-key>
APPLICATION_LOGGER_ENABLED=true
```

The recipe also defaults `release` to `%env(default::APP_VERSION)%`, `environment` to `%kernel.environment%`, and `debug` to `%kernel.debug%`.

### Without Flex (manual setup)

1. Register the bundle in `config/bundles.php`:

   ```php
   return [
       // ...
       ApplicationLogger\Bundle\ApplicationLoggerBundle::class => ['all' => true],
   ];
   ```

2. Create `config/packages/application_logger.yaml`. A minimal configuration only needs a DSN and an API key:

   ```yaml
   application_logger:
       dsn: '%env(APPLICATION_LOGGER_DSN)%'
       api_key: '%env(APPLICATION_LOGGER_API_KEY)%'
       enabled: '%env(bool:APPLICATION_LOGGER_ENABLED)%'
       environment: '%kernel.environment%'
   ```

3. Add the corresponding environment variables to `.env` / `.env.local` and clear the cache:

   ```bash
   php bin/console cache:clear
   ```

> The bundle does **not** require a Monolog handler to be declared by hand. It prepends its own `service`-type Monolog handler automatically on the channels `!event`, `!request`, and `!php` (those framework channels are excluded to avoid double-recording uncaught exceptions, which are already handled by the exception subscriber).

---

## Configuration

The configuration root key is `application_logger`. The DSN and API key are the only values required to start sending; everything else has a sensible default.

### DSN and authentication

The DSN identifies your project endpoint and has the form:

```
https://<host>/<project-id>
```

The client-internal parser validates that a project-id path segment exists. **The API key is not embedded in the DSN** — it is sent separately in the `X-Api-Key` request header.

Authentication summary:

| Channel | Header | Destination |
|---------|--------|-------------|
| Error & session tracking | `X-Api-Key: <api_key>` | Platform API host (from the DSN) |
| Log aggregation | `X-Api-Key: <log_token>` (format `sk_log_…`) | `log_endpoint` (the AppLogger log collector) |

Inertness rules:

- An **empty** DSN or API key makes the corresponding feature short-circuit silently (no requests, no errors).
- A **non-empty but malformed** DSN is treated as active misconfiguration and raises `InvalidArgumentException` (so genuine misconfiguration surfaces early rather than silently dropping telemetry).
- If `log_endpoint` or `log_token` is empty/null, log aggregation silently no-ops.

### Core options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `dsn` | string | `''` | Project endpoint URL `https://host/project-id`. Empty = inert. Deliberately not required, so a skipped Flex recipe never breaks `cache:clear`. |
| `api_key` | string | `''` | Sent as `X-Api-Key`. Empty = inert. |
| `enabled` | bool | `true` | Global error-tracking enable. |
| `release` | string | `null` | Version / release identifier. |
| `environment` | string | `'production'` | Environment name reported with telemetry. |
| `endpoint_path` | string | `/api/v1/errors` | Error ingestion path. |

### Log aggregation

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `log_endpoint` | string | `null` | Collector base URL, e.g. `https://<slug>.logs.applogger.eu`. `null` = aggregation off. |
| `log_token` | string | `null` | Log token (`sk_log_…`), sent as `X-Api-Key` to the collector. |
| `log_path` | string | `/v1/logs` | Single-log endpoint path (batch endpoint is `<log_path>/batch`). |
| `log_batch_size` | int | `50` | Records per batch (min 1, max 1000). |
| `max_log_buffer` | int | `1000` | Max buffered records before oldest are dropped (min 1, max 10000). |

### Performance & resilience

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `timeout` | float | `2.0` | Request timeout in seconds (min 0.5, max 5.0). |
| `retry_attempts` | int | `0` | Sync-mode retries (min 0, max 3). `0` = fail fast (recommended). |
| `async` | bool | `true` | Fire-and-forget transport. |
| `debug` | bool | `false` | Internal PHP debug logging. |

### Circuit breaker (`circuit_breaker`)

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `enabled` | bool | `true` | Enable the circuit breaker. |
| `failure_threshold` | int | `5` | Failures before the circuit opens (min 1). |
| `timeout` | int | `60` | Seconds the circuit stays open (min 10, max 300). |
| `half_open_attempts` | int | `1` | Trial requests allowed while half-open (min 1). |

### Capture

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `capture_level` | string | `error` | Minimum Monolog level routed by the handler (`debug`–`emergency`). An invalid literal falls back to `error` at runtime rather than throwing. |
| `scrub_fields` | array | see below | Field names redacted before sending (kept in sync with the JS list). |
| `max_breadcrumbs` | int | `50` | Maximum breadcrumbs retained (min 10, max 100). |

### JavaScript (`javascript`)

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `enabled` | bool | `true` | Enable the frontend SDK. |
| `auto_inject` | bool | `true` | Automatically inject the SDK into HTML responses. |
| `debug` | bool | `false` | SDK debug logging. |
| `environment` | string | `null` | Falls back to the root `environment`. |
| `release` | string | `null` | Falls back to the root `release`. |
| `scrub_fields` | array | `[]` | Merged (deduplicated) with the root `scrub_fields`. |

### Session tracking (`session_tracking`)

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `enabled` | bool | `true` | Required for session replay. |
| `track_page_views` | bool | `true` | Record page-view events. |
| `idle_timeout` | int | `1800` | Idle timeout in seconds (min 300, max 7200). |
| `ignored_routes` | array | `['_profiler', '_wdt']` | Routes excluded from tracking. |
| `ignored_paths` | array | `['/api/', '/_fragment']` | Path prefixes excluded from tracking. |

### Session replay (`session_replay`)

These values are forwarded to the JavaScript SDK. Replay is **error-triggered only** (never continuous). See [Session replay](#session-replay-opt-in) for the important note on default state.

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `enabled` | bool | `true` | Error-triggered replay enable (see note below). |
| `buffer_before_error_seconds` | int | `30` | Seconds buffered before an error (min 5, max 60). |
| `buffer_before_error_clicks` | int | `10` | Clicks buffered before an error (min 1, max 15). |
| `buffer_after_error_seconds` | int | `30` | Seconds buffered after an error (min 5, max 60). |
| `buffer_after_error_clicks` | int | `10` | Clicks buffered after an error (min 1, max 15). |
| `snapshot_throttle_ms` | int | `1000` | DOM snapshot throttle (min 500, max 5000). |
| `click_debounce_ms` | int | `1000` | Click debounce (min 100, max 5000). |
| `max_snapshot_size` | int | `1048576` | Max snapshot size in bytes (min 102400, max 5242880). |
| `session_timeout_minutes` | int | `30` | Replay session timeout (min 5, max 120). |
| `max_buffer_size_mb` | int | `5` | Max replay buffer size in MB (min 1, max 20). |
| `expose_api` | bool | `true` | Expose the JS enable/disable/isEnabled runtime API. |

### Realistic example

```yaml
# config/packages/application_logger.yaml
application_logger:
    dsn: '%env(APPLICATION_LOGGER_DSN)%'
    api_key: '%env(APPLICATION_LOGGER_API_KEY)%'
    enabled: '%env(bool:APPLICATION_LOGGER_ENABLED)%'
    environment: '%kernel.environment%'
    release: '%env(default::APP_VERSION)%'

    # Resilience
    timeout: 2.0
    async: true
    retry_attempts: 0

    circuit_breaker:
        enabled: true
        failure_threshold: 5
        timeout: 60

    # Capture
    capture_level: error
    max_breadcrumbs: 50

    # Log aggregation (optional)
    log_endpoint: '%env(default::APPLICATION_LOGGER_LOG_ENDPOINT)%'
    log_token: '%env(default::APPLICATION_LOGGER_LOG_TOKEN)%'
    log_batch_size: 50
    max_log_buffer: 1000

    # Frontend SDK
    javascript:
        enabled: '%env(bool:APPLICATION_LOGGER_ENABLED)%'
        auto_inject: true

    session_replay:
        enabled: true
```

---

## Usage

### Automatic exception capture (zero-config)

Once the bundle is enabled with a valid DSN and API key, uncaught exceptions are captured automatically. The exception subscriber listens on `kernel.exception` at a low priority (`-100`, after the framework's own handlers), extracts the HTTP status code (the status of an `HttpExceptionInterface`, otherwise `500`), attaches `exception_class` and `exception_code` tags plus a breadcrumb, and sends the error payload. The subscriber is wrapped in its own try/catch and never interferes with your application's exception handling.

No code changes are needed for this.

### Manual capture via Monolog

Because the bundle auto-registers a Monolog handler, the most natural way to record events from your own code is to log through Monolog. The handler routes records by content:

- a record whose `context['exception']` is a `\Throwable` is sent to **error tracking**;
- any other record at or above `capture_level` is sent to **log aggregation**.

```php
use Psr\Log\LoggerInterface;

final class CheckoutService
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function process(Order $order): void
    {
        try {
            // ... domain logic ...
        } catch (\Throwable $e) {
            // Routed to error tracking (carries a Throwable in context).
            $this->logger->error('Checkout failed', [
                'exception' => $e,
                'order_id'  => $order->getId(),
            ]);

            throw $e;
        }
    }
}
```

### Log aggregation via Monolog

Records without an exception that meet the `capture_level` threshold are buffered and shipped to the log collector in batches:

```php
$this->logger->warning('Payment gateway latency elevated', [
    'gateway' => 'acme-pay',
    'latency_ms' => 812,
]);
```

Each record is converted into a log entry with an RFC3339 timestamp, an RFC5424 severity keyword (`debug`, `info`, `notice`, `warning`, `error`, `critical`, `alert`, `emergency`), the Monolog channel as the application name, the environment, and a scrubbed, flattened context map. Entries are sent to `POST <log_endpoint><log_path>` (or the `/batch` variant for batches); a successful ingestion returns `HTTP 202 Accepted`. If no `log_endpoint` / `log_token` is configured, log aggregation is silently skipped.

### JavaScript SDK

The bundled SDK is shipped pre-built and integrated through AssetMapper automatically. With `javascript.auto_inject` enabled (the default), the SDK is injected into eligible HTML responses — main requests with a `text/html` content type, a status below 400, a `</body>` tag, and a body under 1 MiB. Error pages (4xx/5xx) are deliberately skipped.

If you prefer to place the SDK yourself (and Twig is available), disable `auto_inject` and call the Twig function just before `</body>`:

```twig
{# templates/base.html.twig #}
    {{ application_logger_init() }}
  </body>
</html>
```

At runtime the SDK is exposed as `window.ApplicationLogger`. It automatically captures `window` `error` events, unhandled promise rejections, failed fetch/HTTP requests, and `console.error()` breadcrumbs. When `expose_api` is enabled (the default), you can also report manually:

```javascript
// Capture an exception
window.ApplicationLogger.captureException(new Error('Manual report'), {
  tags: { feature: 'checkout' },
});

// Capture a message
window.ApplicationLogger.captureMessage('User completed onboarding', 'info');

// Enrich context
window.ApplicationLogger.setUser({ id: '123' });
window.ApplicationLogger.setTags({ plan: 'pro' });
window.ApplicationLogger.addBreadcrumb({ category: 'ui', message: 'Opened modal' });
```

The SDK is resilient on the client side too: a sessionStorage-backed circuit breaker (default threshold 5, 60s open window), a localStorage offline queue (default 50 events, 24h max age), token-bucket rate limiting (default burst of 10, refilling at 1 token/second), and deduplication (default 5s window). On page close it uses the Beacon API to flush queued events. Breadcrumbs are capped at 50 by default.

### Session replay (opt-in)

Session replay is **error-triggered only** — it never records continuously. When enabled, the SDK buffers a window of DOM snapshots and interactions before and after an error and sends that data **together with the error payload** (not as a separate request). Cross-page continuity is maintained via localStorage.

Session tracking (`session_tracking.enabled`) must be on for replay to function.

> **Important — default state:** Replay is an opt-in feature. The JavaScript SDK itself defaults `sessionReplayEnabled` to `false`, while the bundle's PHP `session_replay.enabled` config node defaults to `true`; the effective value forwarded to the SDK is whatever the PHP configuration / Twig integration provides. Treat replay as **opt-in and error-triggered**, and explicitly confirm `session_replay.enabled` in your configuration to match your intended behavior. When `expose_api` is `true`, replay can also be toggled at runtime:

```javascript
window.ApplicationLogger.sessionReplay.enable();
window.ApplicationLogger.sessionReplay.disable();
window.ApplicationLogger.sessionReplay.isEnabled();
```

### The non-blocking, never-throws guarantee

By default (`async: true`) telemetry is dispatched fire-and-forget and completed **after** the HTTP response has been flushed to the client:

- On the web, a `kernel.terminate` subscriber (priority `-1024`, runs last) flushes buffered logs and then completes any in-flight error requests, so the user is never delayed by telemetry. This also makes **same-host self-monitoring safe** — the send finishes after the response is sent.
- For CLI commands and Messenger workers (which have no `kernel.terminate`), a bounded destructor-based fallback completes outstanding requests within a short time budget.
- Post-response completion is capped (at `min(timeout, 2.0)` seconds) so a slow backend cannot stall worker recycling.

The single dispatch envelope is guarded throughout: the global kill-switch, JSON encoding, the circuit breaker, and the transport itself are all wrapped so failures are recorded internally and **never thrown into your application**. The only intentional exceptions are at construction time (an out-of-range `timeout`, or a non-empty but malformed DSN), which surface genuine misconfiguration early.

---

## Privacy & GDPR

The bundle is designed to scrub sensitive data **on your host, before anything is transmitted**:

- **Key-based field redaction.** Values whose key name (case-insensitive substring match) contains a configured scrub fragment are replaced with `[REDACTED]`, recursively (depth-limited). The default PHP scrub fields are:

  ```text
  password, token, api_key, secret, authorization,
  credit_card, creditcard, card_number, cvv, ssn, iban
  ```

  > This redaction is key-based: values stored under non-matching keys are not inspected, so secrets embedded in free-form text under an innocuous key are not caught. Add your own field names via `scrub_fields` as needed. The JavaScript SDK maintains its own (broader) default scrub list — the two lists are kept in parallel rather than shared, so configure both if you add custom fields.

- **URL and query-string redaction.** Query-string values whose name matches a scrub fragment are redacted, and embedded userinfo credentials (`user:pass@host`) are always redacted, while scheme, host, port, path, and fragment are preserved.

- **IP anonymization.** IPv4 addresses have their last octet masked (e.g. `192.168.1.100` → `192.168.1.0`); IPv6 addresses keep the first 48 bits and zero the remaining 80 bits. Invalid or null inputs return `null` — a raw IP is never echoed.

- **Session-id hashing.** Session identifiers are SHA-256 hashed before leaving the host.

The AppLogger platform itself is EU-hosted with EU data residency. As with any monitoring tool, review what your application logs and adjust `scrub_fields` to match your data-protection obligations.

---

## The AppLogger.eu platform

[AppLogger](https://applogger.eu) is an EU-hosted, privacy-first error tracking and application/log monitoring SaaS built on a Symfony stack:

- **Error tracking** — errors are grouped and fingerprinted into error groups, backed by PostgreSQL.
- **Log aggregation** — high-volume logs are stored in ClickHouse, ingested through a dedicated log collector.
- **Privacy by design** — EU data residency, IP anonymization, PII scrubbing, and session-id hashing.

### Getting a DSN and tokens

1. Sign up at **[applogger.eu](https://applogger.eu)**.
2. Create a project to obtain its **DSN** and **API key** (used for error and session tracking).
3. For log aggregation, obtain the project's **log endpoint** and **log token** (`sk_log_…`).
4. Put these values in your `.env.local` and set `APPLICATION_LOGGER_ENABLED=true`.

---

## Links

- **Website & sign-up:** https://applogger.eu
- **Package repository & issues:** https://github.com/dennisvanbeersel/symfony-logger-client
- **Platform documentation** (API, architecture, security): https://github.com/dennisvanbeersel/application-logger

---

## Contributing

Contributions are welcome. Please open issues and pull requests on the [package repository](https://github.com/dennisvanbeersel/symfony-logger-client).

The project follows PSR-12 with `declare(strict_types=1)` and is checked at PHPStan level 6. Convenience Composer scripts are provided:

```bash
composer test       # Run the PHPUnit test suite
composer cs-check   # Check coding style (PHP-CS-Fixer)
composer cs-fix     # Apply coding-style fixes
composer phpstan    # Run static analysis
composer lint       # cs-check + phpstan
```

Please ensure `composer lint` and `composer test` pass before submitting a pull request.

---

## Security

If you discover a security vulnerability, please report it responsibly rather than opening a public issue. Use the security advisory channel on the [package repository](https://github.com/dennisvanbeersel/symfony-logger-client/security) so it can be addressed before public disclosure.

---

## License

Released under the [MIT License](https://github.com/dennisvanbeersel/symfony-logger-client/blob/main/LICENSE).

Authored by Dennis Van Beersel — [applogger.eu](https://applogger.eu).
