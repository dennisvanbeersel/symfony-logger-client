# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
- Sensitive data scrubbing (password, token, api_key, secret, authorization)
- Session hashing using SHA-256 for privacy-preserving session tracking
- Circuit breaker prevents cascade failures

## [1.0.0] - TBD

### Added
- First stable release

[Unreleased]: https://github.com/dennisvanbeersel/application-logger-bundle/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/dennisvanbeersel/application-logger-bundle/releases/tag/v1.0.0
