# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] - 2026-06-25

### Changed

- **Package renamed** from `dennisvanbeersel/symfony-logger-client` to `applogger/symfony-bundle`.
  Update your `composer.json` require constraint accordingly:
  ```bash
  composer require applogger/symfony-bundle
  ```
- The bundle is now a thin Symfony adapter over `applogger/sdk-core: ^1.0`. Error
  capture routes through the SDK Hub; log aggregation routes through LogClient;
  session tracking is retained unchanged.
- `ApiClient` is now a deprecated compatibility facade. It remains functional but
  will be removed in a future major version. Inject the SDK Hub and LogClient
  directly for new code.
- Deprecated configuration keys (`log_path`, `log_batch_size`, `max_log_buffer`,
  `endpoint_path`, `retry_attempts`, `async`, `circuit_breaker.enabled`) are
  accepted but no-ops; the SDK core owns these concerns. Remove them from your
  config to silence deprecation notices.

### Upgrade notes

- Change `composer require dennisvanbeersel/symfony-logger-client` to
  `composer require applogger/symfony-bundle` in all `composer.json` files and
  CI scripts.
- No PHP namespace changes — `ApplicationLogger\Bundle\` is unchanged.
- No config key changes beyond the deprecated no-ops listed above.

## [Unreleased]

### Added
- **`flush_budget` config (default `2.0`s):** bounds the post-response telemetry drain
  (kernel.terminate / CLI `__destruct`) to `min(timeout, flush_budget)` of **real
  wall-clock** time. The default matches the previous hardcoded terminate cap, so
  **delivery behavior is unchanged**. **Lower it (e.g. `0.5`)** to harden a FrankenPHP
  worker pool against a slow collector — this is the opt-in worker-mode safety knob.
- `ApiClient::getLogCircuitBreakerState()` to expose the log-aggregation breaker state
  independently of the error/session breaker.

- `excluded_channels` config (default `['http_client','console','deprecation','doctrine']`) to control which Monolog channels are aggregated.
- `error_tracking_enabled` / `log_aggregation_enabled` config toggles (default true) for logs-only / errors-only operation.
- `application-logger:test` console command and `ApiClient::sendLogSync()` for collector connectivity smoke-testing.
- Dedicated `application_logger_internal` Monolog channel for the bundle's own diagnostics (always excluded from aggregation).

### Changed
- The post-response drain is now bounded by a true **wall-clock** deadline (previously
  the cap was a per-poll idle timeout that a trickling backend could exceed) and is
  **skipped entirely when the circuit breaker is OPEN** (previously it re-paid the
  drain on every request until the breaker happened to recover).
- The circuit breaker **no longer trips on a *healthy-but-slow* collector**: a handle
  that connected (or returned 2xx/3xx) but exceeded the drain budget is dropped without
  recording a failure, so a slow-but-alive backend no longer sheds all telemetry.
  This progress-aware classification now applies to **all** settling sites, including
  the non-blocking soft-cap reap (`flushPendingResponses`): sustained logging to a
  healthy-but-slow collector can no longer false-trip the breaker mid-request.
- CLI/`__destruct` drain cap changed from a hardcoded `min(timeout, 1.0)` to
  `min(timeout, flush_budget)` (default ⇒ up to 2.0s; more delivery time on CLI, which
  has no worker pool to saturate).
- **Default channel exclusions widened** to also exclude `http_client`/`console`/`deprecation`/`doctrine`, stopping log self-amplification at `capture_level: info` in http_client-heavy apps. The bundle's own self-diagnostics now log on the internal channel (JS-config advisories downgraded from error to warning).

### Upgrade notes
- If you use `capture_level: info` with log aggregation and rely on aggregating
  `http_client`/`console`/`deprecation`/`doctrine` channel records, set
  `application_logger.excluded_channels` to fewer entries (e.g. `[]`).

## [0.3.0] - 2026-06-19

### Changed
- **Reliable post-response async delivery.** In fire-and-forget mode (`async: true`,
  the default) the outbound HTTP send is now **driven to completion after the
  response has already been sent to the client**, via a `kernel.terminate` listener
  (`FlushTelemetrySubscriber`, priority `-1024`). This guarantees telemetry delivery
  in per-request SAPIs (PHP-FPM, FrankenPHP non-worker mode, the PHP built-in
  server) where fire-and-forget requests could previously be aborted by garbage
  collection before a single byte was transmitted. The host request is never
  delayed (~0ms added). A bounded `__destruct` fallback delivers telemetry in
  CLI / Messenger-worker contexts that have no `kernel.terminate` event.
- **Same-host self-monitoring is now safe.** Pointing the bundle at the same host
  it runs on with `async: true` no longer risks a request deadlock, because the
  send completes after the response is flushed and the platform's ingestion is
  disconnect-safe (async message processing).
- Widened framework constraints to `^6.4|^7.0|^8.0` (Symfony 8 support) for
  `symfony/framework-bundle`, `symfony/http-kernel`, `symfony/http-client` and
  `symfony/uid`.

### Documentation
- Documented the pure **log aggregation** feature (shipping non-exception Monolog
  records to the AppLogger log-collector / ClickHouse) as a first-class feature,
  including `log_endpoint`, `log_token`, `capture_level`, batching behaviour, the
  LogEntry contract and the 202 success response.
- Documented that the Monolog handler is **auto-wired** (zero-config) by the
  bundle's container prepend; no manual `monolog.yaml` handler entry is required.

### Added
- Initial release of the Application Logger Symfony Bundle
- `ExceptionSubscriber` for automatic exception capture
- `ApplicationLoggerHandler` for Monolog integration
- `BreadcrumbCollector` for tracking user actions leading up to errors
- `ContextCollector` for gathering request, server, and user context
- `CircuitBreaker` for resilience against API failures
- `ApiClient` for sending errors to the Application Logger platform
- JavaScript SDK for frontend error tracking
  - Automatic error capture for uncaught exceptions
  - Console breadcrumb tracking
  - Network request breadcrumb tracking
  - Session replay support
  - Rate limiting and deduplication
  - Web Crypto API SHA-256 session hashing (GDPR-compliant)
- Twig templates for SDK initialization
- Full test coverage for PHP and JavaScript components

### Security
- IP address anonymization (masks last octet for IPv4, last 80 bits for IPv6)
- Sensitive data scrubbing (password, token, api_key, secret, authorization, credit_card, creditcard, card_number, cvv, ssn, iban)
- Session hashing using SHA-256 for privacy-preserving session tracking
- Circuit breaker prevents cascade failures

[Unreleased]: https://github.com/dennisvanbeersel/application-logger-bundle/compare/v0.3.0...HEAD
[0.3.0]: https://github.com/dennisvanbeersel/application-logger-bundle/releases/tag/v0.3.0
